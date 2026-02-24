<?php

namespace MauticPlugin\WhatSaasBundle\Transport;

use Mautic\PluginBundle\Helper\IntegrationHelper;

class Configuration
{
    private string $apiUrl  = '';
    private array $channels = [];
    private bool $configured = false;

    public function __construct(
        private IntegrationHelper $integrationHelper,
    ) {
    }

    public function getApiUrl(): string
    {
        $this->setConfiguration();

        return rtrim($this->apiUrl, '/');
    }

    /**
     * @return array[] Each channel: ['name' => string, 'apiKey' => string, 'instanceName' => string, 'default' => bool]
     */
    public function getChannels(): array
    {
        $this->setConfiguration();

        return $this->channels;
    }

    /**
     * Get the default channel (first one marked default, or first channel).
     *
     * @return array{name: string, apiKey: string, instanceName: string, default: bool}
     *
     * @throws ConfigurationException
     */
    public function getDefaultChannel(): array
    {
        $this->setConfiguration();

        foreach ($this->channels as $channel) {
            if (!empty($channel['default'])) {
                return $channel;
            }
        }

        // Fall back to first channel
        if (!empty($this->channels)) {
            return $this->channels[0];
        }

        throw new ConfigurationException('No WhatsApp channels configured');
    }

    /**
     * Get a channel by its instanceName.
     *
     * @throws ConfigurationException
     */
    public function getChannelByInstance(string $instanceName): array
    {
        $this->setConfiguration();

        foreach ($this->channels as $channel) {
            if ($channel['instanceName'] === $instanceName) {
                return $channel;
            }
        }

        throw new ConfigurationException(sprintf('WhatsApp channel "%s" not found', $instanceName));
    }

    /**
     * Get channel choices for form dropdowns: ['Display Name' => 'instanceName', ...]
     */
    public function getChannelChoices(): array
    {
        $this->setConfiguration();

        $choices = [];
        foreach ($this->channels as $channel) {
            $label = $channel['name'];
            if (!empty($channel['default'])) {
                $label .= ' (default)';
            }
            $choices[$label] = $channel['instanceName'];
        }

        return $choices;
    }

    /**
     * @throws ConfigurationException
     */
    private function setConfiguration(): void
    {
        if ($this->configured) {
            return;
        }

        $integration = $this->integrationHelper->getIntegrationObject('WhatSaas');
        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            throw new ConfigurationException('WhatSaaS integration is not enabled');
        }

        $features = $integration->getIntegrationSettings()->getFeatureSettings();

        $this->apiUrl = !empty($features['api_url']) ? $features['api_url'] : '';
        if (empty($this->apiUrl)) {
            throw new ConfigurationException('WhatSaaS API URL is not configured');
        }

        $channelsJson = $features['channels'] ?? '';
        if (empty($channelsJson)) {
            throw new ConfigurationException('No WhatsApp channels configured');
        }

        $parsed = json_decode($channelsJson, true);
        if (!is_array($parsed) || empty($parsed)) {
            throw new ConfigurationException('Invalid channels JSON configuration');
        }

        // Validate each channel
        foreach ($parsed as $i => $ch) {
            if (empty($ch['apiKey']) || empty($ch['instanceName'])) {
                throw new ConfigurationException(sprintf('Channel #%d is missing apiKey or instanceName', $i + 1));
            }
            $this->channels[] = [
                'name'         => $ch['name'] ?? $ch['instanceName'],
                'apiKey'       => $ch['apiKey'],
                'instanceName' => $ch['instanceName'],
                'default'      => !empty($ch['default']),
            ];
        }

        $this->configured = true;
    }
}
