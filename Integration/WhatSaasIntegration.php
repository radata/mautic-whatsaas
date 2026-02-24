<?php

namespace MauticPlugin\WhatSaasBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class WhatSaasIntegration extends AbstractIntegration
{
    protected bool $coreIntegration = false;

    public function getName(): string
    {
        return 'WhatSaaS';
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
            'api_url',
            TextType::class,
            [
                'label'    => 'whatsaas.config.api_url',
                'required' => true,
                'data'     => $data['api_url'] ?? 'https://wa.hollandworx.nl',
                'attr'     => [
                    'class'       => 'form-control',
                    'placeholder' => 'https://your-whatsaas-instance.com',
                ],
            ]
        );

        $builder->add(
            'channels',
            TextareaType::class,
            [
                'label'    => 'whatsaas.config.channels',
                'required' => true,
                'data'     => $data['channels'] ?? $this->getChannelsPlaceholder(),
                'attr'     => [
                    'class'       => 'form-control',
                    'rows'        => 8,
                    'placeholder' => $this->getChannelsPlaceholder(),
                    'style'       => 'font-family: monospace; font-size: 12px;',
                ],
            ]
        );

        $builder->add(
            'webhook_secret',
            TextType::class,
            [
                'label'    => 'whatsaas.config.webhook_secret',
                'required' => false,
                'data'     => $data['webhook_secret'] ?? '',
                'attr'     => [
                    'class'       => 'form-control',
                    'placeholder' => 'Optional shared secret for webhook verification',
                ],
            ]
        );
    }

    private function getChannelsPlaceholder(): string
    {
        return json_encode([
            [
                'name'         => 'Main Business',
                'apiKey'       => 'sk_live_your_api_key_here',
                'instanceName' => 'my-instance-name',
                'default'      => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
