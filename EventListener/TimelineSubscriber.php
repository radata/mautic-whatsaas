<?php

namespace MauticPlugin\WhatSaasBundle\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rewrites WhatsApp SMS timeline entries to a custom template that exposes
 * whatsapp_status (sent/delivered/read) from Stat.details JSON.
 */
class TimelineSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Run after core subscribers so we can safely rewrite generated entries.
            LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', -255],
        ];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        if (!$event->isForTimeline() || $event->isEngagementCount()) {
            return;
        }

        $eventsByType = $this->getTimelineEventsByType($event);
        if (empty($eventsByType) || !is_array($eventsByType)) {
            return;
        }

        $updated = false;

        foreach ($eventsByType as &$entries) {
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as &$entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (!$this->isWhatsappSmsEntry($entry)) {
                    continue;
                }

                $entry['contentTemplate'] = '@WhatSaas/SubscribedEvents/Timeline/sms_whatsapp.html.twig';
                $updated                  = true;
            }
        }

        if ($updated) {
            $this->setTimelineEventsByType($event, $eventsByType);
        }
    }

    private function isWhatsappSmsEntry(array $entry): bool
    {
        $eventKey = (string) ($entry['event'] ?? '');
        if ('sms.sent' !== $eventKey && 'sms.failed' !== $eventKey) {
            return false;
        }

        $stat = $entry['extra']['stat'] ?? null;
        if (!is_array($stat)) {
            return false;
        }

        $source = (string) ($stat['source'] ?? '');
        if (str_starts_with($source, 'whatsapp_')) {
            return true;
        }

        $details = $this->decodeDetails($stat['details'] ?? null);
        if ('whatsapp' === (($details['channel'] ?? null))) {
            return true;
        }

        return array_key_exists('whatsapp_status', $details);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>|null
     */
    private function getTimelineEventsByType(LeadTimelineEvent $event): ?array
    {
        $reflection = new \ReflectionObject($event);
        if (!$reflection->hasProperty('events')) {
            return null;
        }

        $property = $reflection->getProperty('events');
        $property->setAccessible(true);
        $value = $property->getValue($event);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $eventsByType
     */
    private function setTimelineEventsByType(LeadTimelineEvent $event, array $eventsByType): void
    {
        $reflection = new \ReflectionObject($event);
        if (!$reflection->hasProperty('events')) {
            return;
        }

        $property = $reflection->getProperty('events');
        $property->setAccessible(true);
        $property->setValue($event, $eventsByType);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (is_string($details) && '' !== trim($details)) {
            $decoded = json_decode($details, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
