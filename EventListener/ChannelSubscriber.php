<?php

namespace MauticPlugin\WhatSaasBundle\EventListener;

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelEvent;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers 'whatsapp' as a Mautic DNC channel.
 *
 * This enables the native Do Not Contact system for WhatsApp,
 * so contacts can be blocked from receiving WhatsApp messages
 * via the same DNC interface used for email and sms.
 */
class ChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationHelper $integrationHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelEvents::ADD_CHANNEL => ['onAddChannel', 80],
        ];
    }

    public function onAddChannel(ChannelEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('WhatSaaS');

        if (false === $integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return;
        }

        $event->addChannel(
            'whatsapp',
            [
                LeadModel::CHANNEL_FEATURE => [],
            ]
        );
    }
}
