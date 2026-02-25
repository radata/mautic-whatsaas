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
 * Processes incoming WhatSaaS webhook events:
 *
 * 1. messages.upsert  → Incoming WhatsApp messages logged as contact activity
 * 3. messages.update   → Read receipts tracked for engagement scoring
 * 4. connection.update → Instance status changes logged
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
        match ($event->getEventType()) {
            'messages.upsert'  => $this->handleIncomingMessage($event),
            'messages.update'  => $this->handleMessageStatus($event),
            'connection.update' => $this->handleConnectionUpdate($event),
            default => $this->logger->debug('WhatSaaS webhook: unhandled event type', [
                'event' => $event->getEventType(),
            ]),
        };
    }

    /**
     * Handle incoming WhatsApp messages — log as contact activity.
     *
     * Extracts phone number from JID (e.g. 31612345678@s.whatsapp.net → +31612345678),
     * finds the Mautic contact by mobile or phone field, and creates an SMS Stat entry.
     */
    private function handleIncomingMessage(WebhookEvent $event): void
    {
        $data = $event->getData();
        $key  = $data['key'] ?? [];

        // Only process incoming messages (not our own outgoing)
        if (!empty($key['fromMe'])) {
            return;
        }

        // Skip group messages
        $remoteJid = $key['remoteJid'] ?? '';
        if (str_contains($remoteJid, '@g.us')) {
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
        $pushName    = $data['pushName'] ?? '';
        $timestamp   = $data['messageTimestamp'] ?? time();

        $text = $this->extractMessageText($message, $messageType);

        // Create stat entry for contact activity timeline
        $stat = new Stat();
        $stat->setDateSent(new \DateTime('@'.$timestamp));
        $stat->setLead($lead);
        $stat->setTrackingHash(str_replace('.', '', uniqid('wa_in_', true)));
        $stat->setSource('whatsapp_incoming');

        $details = [
            'direction'    => 'incoming',
            'channel'      => 'whatsapp',
            'instance'     => $event->getInstance(),
            'phone'        => $phone,
            'pushName'     => $pushName,
            'messageType'  => $messageType,
            'message'      => $text,
            'messageId'    => $key['id'] ?? '',
        ];

        // Include media info if present
        if (!empty($data['mediaUrl'])) {
            $details['mediaUrl'] = $data['mediaUrl'];
        }

        $stat->setDetails($details);

        try {
            $this->em->persist($stat);
            $this->em->flush();

            $this->logger->info('WhatSaaS webhook: incoming message logged for contact', [
                'contactId' => $lead->getId(),
                'phone'     => $phone,
                'type'      => $messageType,
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

        // Can be a single update or array of updates
        $updates = isset($data['key']) ? [$data] : ($data ?? []);

        foreach ($updates as $update) {
            $key    = $update['key'] ?? [];
            $status = $update['status'] ?? '';

            // Only track outgoing message statuses (our sent messages)
            if (empty($key['fromMe'])) {
                continue;
            }

            $remoteJid = $key['remoteJid'] ?? '';
            $messageId = $key['id'] ?? '';

            if (empty($messageId) || str_contains($remoteJid, '@g.us')) {
                continue;
            }

            $phone = $this->jidToPhone($remoteJid);
            if (empty($phone)) {
                continue;
            }

            // Normalize status names from Evolution API
            $normalizedStatus = match (strtoupper($status)) {
                'SENT', 'SERVER_ACK'        => 'sent',
                'DELIVERY_ACK', 'DELIVERED'  => 'delivered',
                'READ', 'PLAYED'            => 'read',
                default                     => $status,
            };

            $lead = $this->findContactByPhone($phone);
            if (!$lead) {
                continue;
            }

            // Find existing stat entry for this contact and update details
            $statRepo = $this->em->getRepository(Stat::class);

            // Look for recent outgoing stats for this contact
            $qb = $statRepo->createQueryBuilder('s')
                ->where('s.lead = :lead')
                ->andWhere('s.source = :source')
                ->setParameter('lead', $lead)
                ->setParameter('source', 'api')
                ->orderBy('s.dateSent', 'DESC')
                ->setMaxResults(1);

            $existingStat = $qb->getQuery()->getOneOrNullResult();

            if ($existingStat) {
                $details = $existingStat->getDetails() ?? [];
                $details['whatsapp_status'] = $normalizedStatus;
                $details['status_updated']  = (new \DateTime())->format('Y-m-d H:i:s');
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
