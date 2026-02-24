<?php

return [
    'name'        => 'WhatSaaS WhatsApp',
    'description' => 'Multi-channel WhatsApp transport for WhatSaaS / Evolution API with webhook support',
    'version'     => '1.3.4',
    'author'      => 'Radata',

    'routes' => [
        'main' => [
            'mautic_plugin_whatsaas_action' => [
                'path'       => '/whatsaas/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\WhatSaasBundle\Controller\WhatsappController::executeAction',
                'defaults'   => [
                    'objectId' => 0,
                ],
            ],
            // Webhook endpoint — public, no Mautic auth required (uses webhook_secret header)
            'mautic_plugin_whatsaas_webhook' => [
                'path'       => '/whatsaas/webhook',
                'controller' => 'MauticPlugin\WhatSaasBundle\Controller\Api\WebhookController::receiveAction',
                'method'     => 'POST',
            ],
        ],
        'api' => [
            'plugin_whatsaas_api_send' => [
                'path'       => '/whatsaas/{smsId}/contact/{contactId}/send',
                'controller' => 'MauticPlugin\WhatSaasBundle\Controller\Api\WhatsappApiController::sendToContactAction',
                'method'     => 'GET',
            ],
        ],
    ],

    'services' => [
        'integrations' => [
            'mautic.integration.whatsaas' => [
                'class'     => \MauticPlugin\WhatSaasBundle\Integration\WhatSaasIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
        'events' => [
            'mautic.whatsaas.subscriber.buttons' => [
                'class'     => \MauticPlugin\WhatSaasBundle\EventListener\ButtonSubscriber::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'translator',
                    'router',
                ],
            ],
            'mautic.whatsaas.subscriber.webhook' => [
                'class'     => \MauticPlugin\WhatSaasBundle\EventListener\WebhookSubscriber::class,
                'arguments' => [
                    'mautic.lead.model.lead',
                    'doctrine.orm.entity_manager',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.whatsaas.subscriber.plugin' => [
                'class'     => \MauticPlugin\WhatSaasBundle\EventListener\PluginSubscriber::class,
                'arguments' => [
                    'mautic.whatsaas.field_installer',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.whatsaas.subscriber.channel' => [
                'class'     => \MauticPlugin\WhatSaasBundle\EventListener\ChannelSubscriber::class,
                'arguments' => [
                    'mautic.helper.integration',
                ],
            ],
        ],
        'forms' => [
            'mautic.form.type.whatsaas_send' => [
                'class' => \MauticPlugin\WhatSaasBundle\Form\Type\SendWhatsappType::class,
            ],
        ],
        'others' => [
            'mautic.whatsaas.field_installer' => [
                'class'     => \MauticPlugin\WhatSaasBundle\Helper\FieldInstaller::class,
                'arguments' => [
                    'mautic.lead.model.field',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.sms.transport.whatsaas.configuration' => [
                'class'     => \MauticPlugin\WhatSaasBundle\Transport\Configuration::class,
                'arguments' => [
                    'mautic.helper.integration',
                ],
            ],
            'mautic.sms.transport.whatsaas' => [
                'class'     => \MauticPlugin\WhatSaasBundle\Transport\WhatSaasTransport::class,
                'arguments' => [
                    'mautic.sms.transport.whatsaas.configuration',
                    'monolog.logger.mautic',
                    'doctrine.orm.entity_manager',
                ],
                'tag'          => 'mautic.sms_transport',
                'tagArguments' => [
                    'channel'          => 'WhatSaas',
                    'integrationAlias' => 'WhatSaas',
                ],
            ],
        ],
    ],
];
