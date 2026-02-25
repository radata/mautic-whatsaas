<?php

namespace MauticPlugin\WhatSaasBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class WhatSaasIntegration extends AbstractIntegration
{
    protected bool $coreIntegration = false;

    public function getName(): string
    {
        return 'WhatSaas';
    }

    public function getDisplayName(): string
    {
        return 'WhatSaaS WhatsApp';
    }

    public function getSecretKeys(): array
    {
        return [];
    }

    public function getRequiredKeyFields(): array
    {
        return [];
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        $builder->add(
            'channels',
            TextareaType::class,
            [
                'label'    => 'whatsaas.config.channels',
                'required' => true,
                'data'     => $data['channels'] ?? $this->getChannelsPlaceholder(),
                'attr'     => [
                    'class'       => 'form-control',
                    'rows'        => 16,
                    'placeholder' => $this->getChannelsPlaceholder(),
                    'style'       => 'font-family: monospace; font-size: 12px;',
                ],
            ]
        );
    }

    private function getChannelsPlaceholder(): string
    {
        return json_encode([
            [
                'name'          => 'My WhatsApp',
                'instanceName'  => 'HW-9908',
                'default'       => true,
                'backend'       => 'evolution',
                'apiUrl'        => 'http://evolution:8080',
                'apiKey'        => 'your-evolution-api-key',
                'whatsaasUrl'   => 'https://wa.hollandworx.nl/dashboard/chat/{phone}?instanceId=1',
                'webhookSecret' => '',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
