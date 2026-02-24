<?php

namespace MauticPlugin\WhatSaasBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class WebhookEvent extends Event
{
    public function __construct(
        private string $eventType,
        private array $data,
        private string $instance,
    ) {
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getInstance(): string
    {
        return $this->instance;
    }
}
