<?php

namespace MauticPlugin\WhatSaasBundle\Controller\Api;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\PluginBundle\Helper\IntegrationHelper;
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
 *   - messages.upsert    → Log incoming WhatsApp messages as contact activity
 *   - messages.update     → Track delivery/read status for engagement scoring
 *   - connection.update   → Log instance connection state changes
 */
class WebhookController extends CommonController
{
    public function receiveAction(
        Request $request,
        IntegrationHelper $integrationHelper,
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

        // Verify webhook secret if configured
        try {
            $integration = $integrationHelper->getIntegrationObject('WhatSaaS');
            if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
                throw new ConfigurationException('WhatSaaS integration is not enabled');
            }

            $features = $integration->getIntegrationSettings()->getFeatureSettings();
            $webhookSecret = $features['webhook_secret'] ?? '';

            if (!empty($webhookSecret)) {
                $headerSecret = $request->headers->get('X-Webhook-Secret', '');
                if (!hash_equals($webhookSecret, $headerSecret)) {
                    $logger->warning('WhatSaaS webhook: invalid secret');

                    return new JsonResponse(['error' => 'Unauthorized'], 401);
                }
            }
        } catch (ConfigurationException $e) {
            $logger->warning('WhatSaaS webhook: plugin not configured - '.$e->getMessage());

            return new JsonResponse(['error' => 'Plugin not configured'], 503);
        }

        $event    = $payload['event'] ?? '';
        $data     = $payload['data'] ?? [];
        $instance = $payload['instance'] ?? '';

        $logger->debug('WhatSaaS webhook received', [
            'event'    => $event,
            'instance' => $instance,
        ]);

        // Dispatch to Mautic event system so our subscriber can handle it
        $this->dispatcher->dispatch(
            new \MauticPlugin\WhatSaasBundle\Event\WebhookEvent($event, $data, $instance),
            \MauticPlugin\WhatSaasBundle\WhatSaasEvents::WEBHOOK_RECEIVED
        );

        return new JsonResponse(['success' => true]);
    }
}
