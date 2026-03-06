<?php

namespace MauticPlugin\WhatSaasBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\SmsBundle\Entity\Stat;
use MauticPlugin\WhatSaasBundle\Event\WebhookEvent;
use MauticPlugin\WhatSaasBundle\WhatSaasEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Processes incoming webhook events from Evolution API and WhatSaaS:
 *
 * Evolution API events:
 *   - messages.upsert    → Incoming WhatsApp messages logged as contact activity
 *   - messages.update    → Read receipts tracked for engagement scoring
 *   - connection.update  → Instance status changes logged
 *
 * WhatSaaS outgoing webhook events:
 *   - message.received   → Incoming WhatsApp message (same handler as messages.upsert)
 *   - message.sent       → Outgoing message sent from WhatSaaS chat UI
 */
class WebhookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LeadModel $leadModel,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WhatSaasEvents::WEBHOOK_RECEIVED => ['onWebhookReceived', 0],
        ];
    }

    public function onWebhookReceived(WebhookEvent $event): void
    {
        $eventType = $this->normalizeEventType($event->getEventType());

        $this->logger->debug('WhatSaaS webhook: processing event', [
            'event'    => $eventType,
            'rawEvent' => $event->getEventType(),
            'instance' => $event->getInstance(),
        ]);

        match ($eventType) {
            // Evolution API events
            'messages.upsert'    => $this->handleMessage($event),
            'messages.update'    => $this->handleMessageStatus($event),
            'connection.update'  => $this->handleConnectionUpdate($event),
            // WhatSaaS outgoing webhook events
            'message.received'   => $this->handleMessage($event),
            'message.sent'       => $this->handleMessage($event),
            'message.status'     => $this->handleMessageStatus($event),
            'contact.updated'    => $this->logger->debug('WhatSaaS webhook: contact updated', [
                'instance' => $event->getInstance(),
            ]),
            'connection.status'  => $this->handleConnectionUpdate($event),
            default => $this->logger->debug('WhatSaaS webhook: unhandled event type', [
                'event' => $eventType,
            ]),
        };
    }

    /**
     * Handle WhatsApp messages (incoming and outgoing) — log as contact activity.
     *
     * Handles both:
     * - Evolution API: messages.upsert
     * - WhatSaaS: message.received, message.sent
     *
     * Extracts phone number from JID (e.g. 31612345678@s.whatsapp.net → +31612345678),
     * finds the Mautic contact by whatsapp/mobile/phone field, and creates an SMS Stat entry.
     */
    private function handleMessage(WebhookEvent $event): void
    {
        $data = $event->getData();
        $key  = $data['key'] ?? [];

        // Evolution payload: key.fromMe
        // WhatSaaS outgoing webhook payload: fromMe
        $fromMe = array_key_exists('fromMe', $key)
            ? !empty($key['fromMe'])
            : !empty($data['fromMe']);

        // Skip group messages
        $remoteJid = $this->extractRemoteJid($data, $key);
        if (
            empty($remoteJid) ||
            str_contains($remoteJid, '@g.us') ||
            str_contains($remoteJid, '@newsletter') ||
            'status@broadcast' === $remoteJid
        ) {
            return;
        }

        $phone = $this->jidToPhone($remoteJid);
        if (empty($phone)) {
            return;
        }

        $lead = $this->findContactByPhone($phone);
        if (!$lead) {
            $this->logger->debug('WhatSaaS webhook: no Mautic contact found for phone '.$phone);

            return;
        }

        // Extract message content
        $message     = $data['message'] ?? [];
        $messageType = $data['messageType'] ?? 'conversation';
        $messageId   = $key['id'] ?? ($data['messageId'] ?? '');
        $pushName    = $data['pushName'] ?? '';
        $timestamp   = $data['messageTimestamp'] ?? ($data['timestamp'] ?? time());

        // WhatSaaS payload includes plain text in data.text
        $text = $data['text'] ?? $this->extractMessageText($message, $messageType);

        $direction = $fromMe ? 'outgoing' : 'incoming';
        $source    = $fromMe ? 'whatsapp_outgoing' : 'whatsapp_incoming';
        $prefix    = $fromMe ? 'wa_out_' : 'wa_in_';

        $trackingHash = !empty($messageId)
            ? $prefix.hash('sha1', (string) $messageId)
            : str_replace('.', '', uniqid($prefix, true));

        // Deduplicate repeated webhook deliveries for the same WhatsApp message ID.
        if (!empty($messageId)) {
            $existing = $this->em->getRepository(Stat::class)->findOneBy(['trackingHash' => $trackingHash]);
            if ($existing) {
                $this->logger->debug('WhatSaaS webhook: duplicate message ignored', [
                    'event'      => $event->getEventType(),
                    'messageId'  => $messageId,
                    'contactId'  => $lead->getId(),
                    'trackingHash' => $trackingHash,
                ]);

                return;
            }
        }

        // Create stat entry for contact activity timeline
        $stat = new Stat();
        $stat->setDateSent($this->parseTimestamp($timestamp));
        $stat->setLead($lead);
        $stat->setTrackingHash($trackingHash);
        $stat->setSource($source);

        $details = [
            'direction'    => $direction,
            'channel'      => 'whatsapp',
            'instance'     => $event->getInstance(),
            'phone'        => $phone,
            'pushName'     => $pushName,
            'messageType'  => $messageType,
            'message'      => $text,
            'messageId'    => (string) $messageId,
        ];

        // Include media info if present
        if (!empty($data['mediaUrl'])) {
            $details['mediaUrl'] = $data['mediaUrl'];
        }

        $stat->setDetails($details);

        try {
            $this->em->persist($stat);
            $this->em->flush();

            $this->logger->info('WhatSaaS webhook: '.$direction.' message logged for contact', [
                'contactId' => $lead->getId(),
                'phone'     => $phone,
                'type'      => $messageType,
                'direction' => $direction,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('WhatSaaS webhook: failed to save stat - '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle message status updates — track delivery and read receipts.
     *
     * Status flow: SENT → DELIVERY_ACK → READ/PLAYED
     * Updates existing Stat entries with delivery/read status for engagement scoring.
     */
    private function handleMessageStatus(WebhookEvent $event): void
    {
        $data = $event->getData();

        // Can be:
        // - Evolution: single update with key/status
        // - Evolution: array of updates
        // - WhatSaaS outgoing webhook: {messageId, status, remoteJid, ...}
        if (isset($data['key']) || isset($data['messageId']) || isset($data['status'])) {
            $updates = [$data];
        } elseif (array_is_list($data)) {
            $updates = $data;
        } else {
            $updates = [];
        }

        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $key    = $update['key'] ?? [];
            $status = $update['status'] ?? ($update['update']['status'] ?? ($update['ack'] ?? null));

            $fromMe = array_key_exists('fromMe', $key)
                ? !empty($key['fromMe'])
                : (array_key_exists('fromMe', $update) ? !empty($update['fromMe']) : true);

            // Only track outgoing message statuses (our sent messages)
            if (!$fromMe) {
                continue;
            }

            $remoteJid = $this->extractRemoteJid($update, $key);
            $messageId = $key['id'] ?? ($update['messageId'] ?? '');

            if (
                empty($messageId) ||
                empty($remoteJid) ||
                str_contains($remoteJid, '@g.us') ||
                str_contains($remoteJid, '@newsletter') ||
                'status@broadcast' === $remoteJid
            ) {
                continue;
            }

            $phone = $this->jidToPhone($remoteJid);
            if (empty($phone)) {
                continue;
            }

            $normalizedStatus = $this->normalizeMessageStatus($status);
            if (empty($normalizedStatus)) {
                continue;
            }

            $lead = $this->findContactByPhone($phone);
            if (!$lead) {
                continue;
            }

            // Find existing stat entry for this contact and update details
            $statRepo = $this->em->getRepository(Stat::class);

            $trackingHash = 'wa_out_'.hash('sha1', (string) $messageId);
            $existingStat = $statRepo->findOneBy(['trackingHash' => $trackingHash]);

            // Fallback for older stats before deterministic tracking hash.
            if (!$existingStat) {
                $qb = $statRepo->createQueryBuilder('s')
                    ->where('s.lead = :lead')
                    ->andWhere('s.source IN (:sources)')
                    ->setParameter('lead', $lead)
                    ->setParameter('sources', ['whatsapp_outgoing', 'api'])
                    ->orderBy('s.dateSent', 'DESC')
                    ->setMaxResults(1);

                $existingStat = $qb->getQuery()->getOneOrNullResult();
            }

            if ($existingStat) {
                $details = $existingStat->getDetails() ?? [];
                $details['whatsapp_status'] = $normalizedStatus;
                $details['status_updated']  = (new \DateTime())->format('Y-m-d H:i:s');
                $details['messageId']       = $details['messageId'] ?? (string) $messageId;
                $existingStat->setDetails($details);

                try {
                    $this->em->persist($existingStat);
                    $this->em->flush();

                    $this->logger->info('WhatSaaS webhook: message status updated', [
                        'contactId' => $lead->getId(),
                        'status'    => $normalizedStatus,
                        'messageId' => $messageId,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('WhatSaaS webhook: failed to update stat - '.$e->getMessage());
                }
            }
        }
    }

    /**
     * Handle connection status changes — log for monitoring.
     */
    private function handleConnectionUpdate(WebhookEvent $event): void
    {
        $data     = $event->getData();
        $state    = $data['state'] ?? $data['status'] ?? 'unknown';
        $instance = $event->getInstance();

        $this->logger->info('WhatSaaS webhook: connection update', [
            'instance' => $instance,
            'state'    => $state,
        ]);
    }

    /**
     * Convert WhatsApp JID to E.164 phone number.
     *
     * 31612345678@s.whatsapp.net → +31612345678
     */
    private function jidToPhone(string $jid): string
    {
        $number = explode('@', $jid)[0] ?? '';
        $number = explode(':', $number)[0] ?? '';
        $number = preg_replace('/\D+/', '', $number);

        if (empty($number) || !is_numeric($number)) {
            return '';
        }

        return '+'.$number;
    }

    /**
     * Find a Mautic contact by phone number.
     *
     * Searches whatsapp, mobile, and phone fields with various formats:
     * +31612345678, 31612345678, 0612345678, 06-12345678
     */
    private function findContactByPhone(string $phone): ?Lead
    {
        // Build search variants
        $variants = [$phone];

        // Without + prefix
        $bare = ltrim($phone, '+');
        $variants[] = $bare;

        // If Dutch number (+31...), also search for local format (06...)
        if (str_starts_with($bare, '31') && strlen($bare) >= 11) {
            $local = '0'.substr($bare, 2);
            $variants[] = $local;
            // Common formatted variants
            $variants[] = substr($local, 0, 2).'-'.substr($local, 2);
            $variants[] = substr($local, 0, 3).' '.substr($local, 3, 3).' '.substr($local, 6);
        }

        $connection = $this->em->getConnection();

        // Search whatsapp field first, then mobile, then phone
        foreach (['whatsapp', 'mobile', 'phone'] as $field) {
            $placeholders = [];
            $params = [];
            foreach ($variants as $i => $variant) {
                $key = 'v'.$i;
                // Use REPLACE to strip common formatting before comparing
                $placeholders[] = "REPLACE(REPLACE(REPLACE(REPLACE(l.{$field}, ' ', ''), '-', ''), '(', ''), ')', '') = :{$key}";
                $params[$key] = preg_replace('/[\s\-\(\)]/', '', $variant);
            }

            $sql = sprintf(
                'SELECT l.id FROM %sleads l WHERE (%s) LIMIT 1',
                MAUTIC_TABLE_PREFIX,
                implode(' OR ', $placeholders)
            );

            try {
                $result = $connection->executeQuery($sql, $params)->fetchAssociative();

                if ($result) {
                    return $this->leadModel->getEntity($result['id']);
                }
            } catch (\Exception $e) {
                // Field may not exist yet (whatsapp is custom), skip silently
                $this->logger->debug('WhatSaaS webhook: field lookup failed for '.$field.': '.$e->getMessage());
            }
        }

        return null;
    }

    private function normalizeEventType(string $eventType): string
    {
        $normalized = strtolower(str_replace('_', '.', trim($eventType)));

        return match ($normalized) {
            'message.upsert' => 'messages.upsert',
            'message.update' => 'messages.update',
            'chat.update' => 'chats.update',
            'contact.update' => 'contacts.update',
            default => $normalized,
        };
    }

    private function extractRemoteJid(array $data, array $key = []): string
    {
        $candidates = [
            $key['remoteJidAlt'] ?? null,
            $data['remoteJidAlt'] ?? null,
            $key['remoteJid'] ?? null,
            $data['remoteJid'] ?? null,
            $key['participant'] ?? null,
            $data['participant'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function parseTimestamp(mixed $timestamp): \DateTime
    {
        if (is_numeric($timestamp)) {
            $seconds = (int) $timestamp;
            if ($seconds > 9999999999) {
                $seconds = (int) floor($seconds / 1000);
            }

            return new \DateTime('@'.$seconds);
        }

        if (is_string($timestamp) && '' !== trim($timestamp)) {
            try {
                return new \DateTime($timestamp);
            } catch (\Throwable) {
                // Fall through to now.
            }
        }

        return new \DateTime();
    }

    private function normalizeMessageStatus(mixed $status): string
    {
        if (is_numeric($status)) {
            $value = (int) $status;

            return match (true) {
                $value <= 1 => 'sent',
                2 === $value => 'delivered',
                $value >= 3 => 'read',
                default => '',
            };
        }

        if (!is_string($status)) {
            return '';
        }

        $normalized = strtoupper(trim($status));
        if ('' === $normalized) {
            return '';
        }

        if (preg_match('/^-?\d+$/', $normalized)) {
            return $this->normalizeMessageStatus((int) $normalized);
        }

        return match ($normalized) {
            'SENT', 'SERVER_ACK', 'PENDING', 'ACK_SERVER' => 'sent',
            'DELIVERY_ACK', 'DELIVERED', 'ACK_DEVICE', 'DELIVERY_RECEIPT' => 'delivered',
            'READ', 'PLAYED', 'READ_ACK', 'ACK_READ', 'READ_RECEIPT', 'PLAYED_ACK' => 'read',
            default => '',
        };
    }

    /**
     * Extract readable text from a WhatSaaS message payload.
     */
    private function extractMessageText(array $message, string $messageType): string
    {
        return match ($messageType) {
            'conversation'                => $message['conversation'] ?? '',
            'extendedTextMessage'         => $message['extendedTextMessage']['text'] ?? '',
            'imageMessage'                => $message['imageMessage']['caption'] ?? '[Image]',
            'videoMessage'                => $message['videoMessage']['caption'] ?? '[Video]',
            'audioMessage'                => '[Voice message]',
            'documentMessage'             => $message['documentMessage']['fileName'] ?? '[Document]',
            'stickerMessage'              => '[Sticker]',
            'contactMessage'              => $message['contactMessage']['displayName'] ?? '[Contact]',
            'locationMessage'             => sprintf(
                '[Location: %s, %s]',
                $message['locationMessage']['degreesLatitude'] ?? '?',
                $message['locationMessage']['degreesLongitude'] ?? '?'
            ),
            'templateButtonReplyMessage'  => $message['templateButtonReplyMessage']['selectedDisplayText'] ?? '[Template reply]',
            default                       => '[Message]',
        };
    }
}
