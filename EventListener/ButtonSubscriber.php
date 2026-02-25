<?php

namespace MauticPlugin\WhatSaasBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\WhatSaasBundle\Transport\Configuration;
use MauticPlugin\WhatSaasBundle\Transport\ConfigurationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationHelper $helper,
        private TranslatorInterface $translator,
        private RouterInterface $router,
        private Configuration $configuration,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    /**
     * Build the WhatSaaS chat dashboard URL for a contact.
     *
     * Uses the whatsaasUrl template from the default channel config,
     * replacing {phone} with the contact's whatsapp field value.
     */
    private function buildWhatsaasChatUrl(object $contact): ?string
    {
        try {
            $channel = $this->configuration->getDefaultChannel();
        } catch (ConfigurationException) {
            return null;
        }

        $urlTemplate = $channel['whatsaasUrl'] ?? '';
        if (empty($urlTemplate)) {
            return null;
        }

        // Get phone from whatsapp custom field first, then mobile, then phone
        $phone = $contact->getFieldValue('whatsapp') ?: $contact->getMobile() ?: $contact->getPhone();
        if (empty($phone)) {
            return null;
        }

        // Strip formatting — WhatSaaS expects bare number (e.g. 31612345678)
        $phone = preg_replace('/[\s\-\(\)\+]/', '', $phone);

        return str_replace('{phone}', $phone, $urlTemplate);
    }

    public function injectViewButtons(CustomButtonEvent $event): void
    {
        $myIntegration = $this->helper->getIntegrationObject('WhatSaas');

        if (false === $myIntegration || !$myIntegration->getIntegrationSettings()->getIsPublished()) {
            return;
        }

        if (str_starts_with($event->getRoute(), 'mautic_contact_') && $event->getItem()) {
            $contact   = $event->getItem();
            $contactId = $contact->getId();

            // "Send WhatsApp" modal button
            $event->addButton(
                [
                    'attr' => [
                        'data-toggle' => 'ajaxmodal',
                        'data-target' => '#MauticSharedModal',
                        'data-header' => $this->translator->trans('whatsaas.send.header'),
                        'href'        => $this->router->generate(
                            'mautic_plugin_whatsaas_action',
                            ['objectAction' => 'sendWhatsapp', 'objectId' => $contactId]
                        ),
                    ],
                    'btnText'   => $this->translator->trans('whatsaas.send.button'),
                    'iconClass' => 'ri-whatsapp-line',
                ],
                ButtonHelper::LOCATION_PAGE_ACTIONS,
                ['mautic_contact_action', ['objectAction' => 'view']]
            );

            // "Open WhatSaaS Chat" external link button
            $chatUrl = $this->buildWhatsaasChatUrl($contact);
            if ($chatUrl) {
                $event->addButton(
                    [
                        'attr' => [
                            'href'   => $chatUrl,
                            'target' => '_blank',
                            'rel'    => 'noopener noreferrer',
                        ],
                        'btnText'   => $this->translator->trans('whatsaas.chat.button'),
                        'iconClass' => 'ri-chat-3-line',
                    ],
                    ButtonHelper::LOCATION_PAGE_ACTIONS,
                    ['mautic_contact_action', ['objectAction' => 'view']]
                );
            }
        }
    }
}
