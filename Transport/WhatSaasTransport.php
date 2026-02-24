<?php

namespace MauticPlugin\WhatSaasBundle\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\SmsBundle\Sms\TransportInterface;
use Psr\Log\LoggerInterface;

class WhatSaasTransport implements TransportInterface
{
    public function __construct(
        private Configuration $configuration,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Send a WhatsApp text message via default channel (used by campaign SMS transport).
     *
     * Phone priority: whatsapp field → mobile → phone
     * Checks DNC for 'whatsapp' channel before sending.
     *
     * @param string $content
     *
     * @return bool|string true on success, error message on failure
     */
    public function sendSms(Lead $lead, $content)
    {
        // Check WhatsApp DNC
        if ($this->isDnc($lead)) {
            return 'Contact is on WhatsApp Do Not Contact list';
        }

        $number = $this->getWhatsappNumber($lead);
        if (empty($number)) {
            return 'mautic.sms.transport.error.no_phone';
        }

        $number = $this->normalizePhoneNumber($number);

        try {
            $channel = $this->configuration->getDefaultChannel();
            $apiUrl  = $this->configuration->getApiUrl();
        } catch (ConfigurationException $e) {
            $this->logger->warning('WhatSaaS not configured: '.$e->getMessage());

            return $e->getMessage();
        }

        return $this->send($apiUrl, $channel, $number, 'text', $content);
    }

    /**
     * Send a WhatsApp message with optional media, with channel selection.
     *
     * @param string      $recipient    E.164 phone number
     * @param string      $type         text|image|video|document|audio
     * @param string      $message      Message text (or caption for media)
     * @param string|null $mediaUrl     URL for media/document/audio
     * @param string|null $instanceName Channel instanceName (null = default)
     *
     * @return bool|string true on success, error message on failure
     */
    public function sendWhatsapp(
        string $recipient,
        string $type,
        string $message,
        ?string $mediaUrl = null,
        ?string $instanceName = null,
    ): bool|string {
        try {
            $apiUrl  = $this->configuration->getApiUrl();
            $channel = $instanceName
                ? $this->configuration->getChannelByInstance($instanceName)
                : $this->configuration->getDefaultChannel();
        } catch (ConfigurationException $e) {
            $this->logger->warning('WhatSaaS not configured: '.$e->getMessage());

            return $e->getMessage();
        }

        return $this->send($apiUrl, $channel, $recipient, $type, $message, $mediaUrl);
    }

    /**
     * Check if a contact is on the WhatsApp DNC list.
     */
    public function isDnc(Lead $lead): bool
    {
        $dncRepo = $this->em->getRepository(DoNotContact::class);
        $dncEntries = $dncRepo->getEntriesByLeadAndChannel($lead, 'whatsapp');

        if (empty($dncEntries)) {
            return false;
        }

        foreach ($dncEntries as $dnc) {
            if (DoNotContact::IS_CONTACTABLE !== $dnc->getReason()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the WhatsApp phone number for a contact.
     *
     * Priority: whatsapp custom field → mobile → phone
     */
    public function getWhatsappNumber(Lead $lead): string
    {
        // 1. Check whatsapp custom field first
        $whatsapp = $lead->getFieldValue('whatsapp');
        if (!empty($whatsapp)) {
            return (string) $whatsapp;
        }

        // 2. Fall back to mobile, then phone (standard Mautic behavior)
        return (string) $lead->getLeadPhoneNumber();
    }

    /**
     * Send via Evolution API.
     *
     * Text:     POST /message/sendText/{instance}     {"number":"...","text":"..."}
     * Media:    POST /message/sendMedia/{instance}     {"number":"...","mediatype":"image|video|document","media":"url","caption":"text"}
     * Audio:    POST /message/sendWhatsAppAudio/{instance} {"number":"...","audio":"url"}
     */
    private function send(
        string $apiUrl,
        array $channel,
        string $recipient,
        string $type,
        string $message,
        ?string $mediaUrl = null,
    ): bool|string {
        $instance = $channel['instanceName'];
        $apiUrl   = rtrim($apiUrl, '/');

        // Strip '+' from number — Evolution API expects digits only
        $number = ltrim($recipient, '+');

        // Build endpoint URL and payload based on message type
        if ('text' === $type || empty($mediaUrl)) {
            $url     = $apiUrl.'/message/sendText/'.$instance;
            $payload = [
                'number' => $number,
                'text'   => $message,
            ];
        } elseif ('audio' === $type) {
            $url     = $apiUrl.'/message/sendWhatsAppAudio/'.$instance;
            $payload = [
                'number' => $number,
                'audio'  => $mediaUrl,
            ];
        } else {
            // image, video, document
            $url     = $apiUrl.'/message/sendMedia/'.$instance;
            $payload = [
                'number'    => $number,
                'mediatype' => $type,
                'media'     => $mediaUrl,
                'caption'   => $message,
            ];
            if ('document' === $type) {
                $payload['fileName'] = basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'document');
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'apikey: '.$channel['apiKey'],
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if (!empty($error)) {
            $this->logger->error('WhatSaaS cURL error: '.$error);

            return 'Evolution API error: '.$error;
        }

        $data = json_decode($response, true);

        if (null === $data) {
            $this->logger->error('WhatSaaS invalid response', [
                'url'      => $url,
                'httpCode' => $httpCode,
                'response' => substr($response, 0, 500),
            ]);

            return 'Evolution API: invalid response (HTTP '.$httpCode.')';
        }

        // Evolution API returns 200/201 on success with a "key" object
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->logger->info('WhatSaaS WhatsApp sent successfully', [
                'recipient' => $recipient,
                'type'      => $type,
                'instance'  => $instance,
                'messageId' => $data['key']['id'] ?? null,
            ]);

            return true;
        }

        $errorMsg = $data['response']['message'][0]
            ?? $data['message'][0]
            ?? $data['error']
            ?? $data['message']
            ?? 'Unknown error';
        if (is_array($errorMsg)) {
            $errorMsg = implode(', ', $errorMsg);
        }
        $maskedKey = substr($channel['apiKey'], 0, 8).'...'.substr($channel['apiKey'], -4);
        $this->logger->warning('WhatSaaS send failed: '.$errorMsg, [
            'url'       => $url,
            'instance'  => $instance,
            'apiKey'    => $maskedKey,
            'recipient' => $recipient,
            'httpCode'  => $httpCode,
            'response'  => $response,
        ]);

        return sprintf(
            'Evolution API: %s [url=%s, instance=%s, key=%s, http=%d]',
            $errorMsg,
            $url,
            $instance,
            $maskedKey,
            $httpCode
        );
    }

    private function normalizePhoneNumber(string $number): string
    {
        $number = preg_replace('/[\s\-\(\)]/', '', $number);

        if (str_starts_with($number, '+')) {
            return $number;
        }

        if (str_starts_with($number, '0')) {
            return '+31'.substr($number, 1);
        }

        return '+'.$number;
    }
}
