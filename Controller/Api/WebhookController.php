<?php

namespace MauticPlugin\WhatSaasBundle\Controller\Api;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WhatSaasBundle\Transport\Configuration;
use MauticPlugin\WhatSaasBundle\Transport\ConfigurationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives webhook events from WhatSaaS / Evolution API.
 *
 * Configure in WhatSaaS: POST https://your-mautic.com/whatsaas/webhook
 *
 * Events handled:
 *   - messages.upsert    -> Log incoming WhatsApp messages as contact activity
 *   - messages.update     -> Track delivery/read status for engagement scoring
 *   - connection.update   -> Log instance connection state changes
 */
class WebhookController extends CommonController
{
    public function receiveAction(
        Request $request,
        Configuration $configuration,
        LoggerInterface $logger,
    ): JsonResponse {
        if ('POST' !== $request->getMethod()) {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payload = json_decode($request->getContent(), true);

        if (empty($payload)) {
            $logger->warning('WhatSaaS webhook: empty or invalid JSON payload');

            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        $event    = $payload['event'] ?? '';
        $data     = $payload['data'] ?? [];
        $instance = $payload['instance'] ?? '';

        // Verify webhook secret per channel (if configured)
        try {
            $channel = $configuration->getChannelByInstance($instance);
            $webhookSecret = $channel['webhookSecret'] ?? '';

            if (!empty($webhookSecret)) {
                $rawBody = $request->getContent();
                $signatureHeader = $request->headers->get('X-Webhook-Signature', '');

                if (!empty($signatureHeader)) {
                    $providedSignature = str_replace('sha256=', '', $signatureHeader);
                    $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);

                    if (!hash_equals($expectedSignature, $providedSignature)) {
                        $logger->warning('WhatSaaS webhook: invalid HMAC signature for instance '.$instance);

                        return new JsonResponse(['error' => 'Unauthorized'], 401);
                    }
                } else {
                    // Backward compatibility with older plain-secret header.
                    $headerSecret = $request->headers->get('X-Webhook-Secret', '');
                    if (!hash_equals($webhookSecret, $headerSecret)) {
                        $logger->warning('WhatSaaS webhook: invalid secret for instance '.$instance);

                        return new JsonResponse(['error' => 'Unauthorized'], 401);
                    }
                }
            }
        } catch (ConfigurationException $e) {
            // Unknown instance — accept anyway (log for debugging)
            $logger->debug('WhatSaaS webhook: unknown instance "'.$instance.'" - '.$e->getMessage());
        }

        $logger->debug('WhatSaaS webhook received', [
            'event'    => $event,
            'instance' => $instance,
        ]);

        // Dispatch to Mautic event system so our subscriber can handle it
        try {
            $this->dispatcher->dispatch(
                new \MauticPlugin\WhatSaasBundle\Event\WebhookEvent($event, $data, $instance),
                \MauticPlugin\WhatSaasBundle\WhatSaasEvents::WEBHOOK_RECEIVED
            );
        } catch (\Throwable $e) {
            $logger->error('WhatSaaS webhook: handler failed - '.$e->getMessage(), [
                'event'     => $event,
                'instance'  => $instance,
                'exception' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 200);
        }

        return new JsonResponse(['success' => true]);
    }
}
