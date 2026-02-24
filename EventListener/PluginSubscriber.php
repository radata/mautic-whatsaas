<?php

namespace MauticPlugin\WhatSaasBundle\EventListener;

use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\WhatSaasBundle\Helper\FieldInstaller;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private FieldInstaller $fieldInstaller,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', 0],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onUpdate', 0],
        ];
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if (!$event->checkContext('WhatSaaS WhatsApp')) {
            return;
        }

        $this->logger->info('WhatSaaS: plugin install - creating custom fields.');
        $this->fieldInstaller->installFields();
    }

    public function onUpdate(PluginUpdateEvent $event): void
    {
        if (!$event->checkContext('WhatSaaS WhatsApp')) {
            return;
        }

        $this->logger->info('WhatSaaS: plugin update - ensuring custom fields exist.');
        $this->fieldInstaller->installFields();
    }
}
