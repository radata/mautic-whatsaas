<?php

namespace MauticPlugin\WhatSaasBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationHelper $helper,
        private TranslatorInterface $translator,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    public function injectViewButtons(CustomButtonEvent $event): void
    {
        $myIntegration = $this->helper->getIntegrationObject('WhatSaas');

        if (false === $myIntegration || !$myIntegration->getIntegrationSettings()->getIsPublished()) {
            return;
        }

        if (str_starts_with($event->getRoute(), 'mautic_contact_') && $event->getItem()) {
            $contactId = $event->getItem()->getId();

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

            // "WhatSaaS Chat" — server-side redirect to external WhatSaaS dashboard
            $event->addButton(
                [
                    'attr' => [
                        'href'   => $this->router->generate(
                            'mautic_plugin_whatsaas_action',
                            ['objectAction' => 'openChat', 'objectId' => $contactId]
                        ),
                        'target' => '_blank',
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
