<?php

namespace MauticPlugin\WhatSaasBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\SmsBundle\Entity\Stat;
use MauticPlugin\WhatSaasBundle\Form\Type\SendWhatsappType;
use MauticPlugin\WhatSaasBundle\Transport\Configuration;
use MauticPlugin\WhatSaasBundle\Transport\ConfigurationException;
use MauticPlugin\WhatSaasBundle\Transport\WhatSaasTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsappController extends FormController
{
    public const PLUGIN_VERSION = '1.7.2';

    public function sendWhatsappAction(
        Request $request,
        WhatSaasTransport $transport,
        Configuration $configuration,
        $objectId = '',
    ): JsonResponse|Response {
        if ('POST' === $request->getMethod()) {
            $data     = $request->request->all()['whatsaas_send'] ?? [];
            $objectId = $data['contactId'] ?? $objectId;
        }

        $leadModel = $this->getModel('lead');
        $lead      = $leadModel->getEntity($objectId);

        if (!$lead) {
            $this->addFlashMessage('mautic.lead.lead.error.notfound', [], 'error');

            return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
        }

        if (!$this->security->hasEntityAccess(
            'lead:leads:editown',
            'lead:leads:editother',
            $lead->getPermissionUser()
        )) {
            $this->addFlashMessage('mautic.core.error.accessdenied', [], 'error');

            return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
        }

        // Get channel choices for the form
        try {
            $channelChoices = $configuration->getChannelChoices();
        } catch (ConfigurationException $e) {
            $this->addFlashMessage('whatsaas.send.error.not_configured', ['%error%' => $e->getMessage()], 'error');

            return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
        }

        /** @var \Mautic\SmsBundle\Model\SmsModel $smsModel */
        $smsModel = $this->getModel('sms');

        if ('GET' === $request->getMethod()) {
            // Load SMS templates for dropdown (same signature as Zender SMS)
            $templateChoices  = [];
            $templateMessages = [];
            try {
                $smsList = $smsModel->getRepository()->getSmsList('', 0, 0, true);
                foreach ($smsList as $sms) {
                    $templateChoices[$sms['name']] = (string) $sms['id'];
                }

                // Load message content for each template (for JS auto-fill)
                foreach ($templateChoices as $name => $id) {
                    $smsEntity = $smsModel->getEntity((int) $id);
                    if ($smsEntity && $smsEntity->isPublished()) {
                        $templateMessages[$id] = $smsEntity->getMessage();
                    } else {
                        unset($templateChoices[$name]);
                    }
                }
            } catch (\Throwable $e) {
                // If SMS templates fail to load, continue without them
            }
            $route = $this->generateUrl(
                'mautic_plugin_whatsaas_action',
                ['objectAction' => 'sendWhatsapp']
            );

            return $this->delegateView([
                'viewParameters' => [
                    'form' => $this->createForm(
                        SendWhatsappType::class,
                        ['contactId' => (string) $objectId],
                        [
                            'action'           => $route,
                            'channel_choices'  => $channelChoices,
                            'template_choices' => $templateChoices,
                        ]
                    )->createView(),
                    'contact'          => $lead,
                    'templateMessages' => $templateMessages,
                ],
                'contentTemplate' => '@WhatSaas/SendWhatsapp/form.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $route,
                ],
            ]);
        }

        if ('POST' === $request->getMethod()) {
            $channelInstance = $data['channel'] ?? null;
            $messageType     = $data['messageType'] ?? 'text';
            $message         = trim($data['message'] ?? '');
            $mediaUrl        = trim($data['mediaUrl'] ?? '');
            $smsTemplateId   = $data['smsTemplate'] ?? null;

            // If template selected and no custom message, use template content
            if (!empty($smsTemplateId) && empty($message)) {
                $smsEntity = $smsModel->getEntity((int) $smsTemplateId);
                if ($smsEntity) {
                    $message = $smsEntity->getMessage();
                }
            }

            if (empty($message)) {
                $this->addFlashMessage('whatsaas.send.error.no_message', [], 'error');

                return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
            }

            if ('text' !== $messageType && empty($mediaUrl)) {
                $this->addFlashMessage('whatsaas.send.error.no_media', [], 'error');

                return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
            }

            // Check WhatsApp DNC
            if ($transport->isDnc($lead)) {
                $this->addFlashMessage('whatsaas.send.error.dnc', [], 'error');

                return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
            }

            // Replace tokens in message
            $message = str_replace(
                ['{contactfield=firstname}', '{contactfield=lastname}', '{contactfield=email}', '{contactfield=phone}', '{contactfield=mobile}', '{contactfield=whatsapp}'],
                [$lead->getFirstname(), $lead->getLastname(), $lead->getEmail(), $lead->getPhone(), $lead->getMobile(), $lead->getFieldValue('whatsapp') ?? ''],
                $message
            );

            // Get recipient phone number (whatsapp field → mobile → phone)
            $recipient = $transport->getWhatsappNumber($lead);
            if (empty($recipient)) {
                $this->addFlashMessage('whatsaas.send.error.no_phone', [], 'error');

                return new JsonResponse(['closeModal' => true, 'flashes' => $this->getFlashContent(), 'v' => self::PLUGIN_VERSION]);
            }

            // Normalize phone number
            $recipient = preg_replace('/[\s\-\(\)]/', '', $recipient);
            if (!str_starts_with($recipient, '+')) {
                $recipient = str_starts_with($recipient, '0')
                    ? '+31'.substr($recipient, 1)
                    : '+'.$recipient;
            }

            $result = $transport->sendWhatsapp(
                $recipient,
                $messageType,
                $message,
                'text' !== $messageType ? $mediaUrl : null,
                $channelInstance,
            );

            // Create stat entry for activity tracking
            $stat = new Stat();
            $stat->setDateSent(new \DateTime());
            $stat->setLead($lead);
            $stat->setTrackingHash(str_replace('.', '', uniqid('', true)));
            $stat->setSource('api');

            // Link to SMS template if used
            if (!empty($smsTemplateId)) {
                $smsEntity = $smsModel->getEntity((int) $smsTemplateId);
                if ($smsEntity) {
                    $stat->setSms($smsEntity);
                }
            }

            $details = [
                'message'  => $message,
                'type'     => $messageType,
                'channel'  => 'whatsapp',
                'instance' => $channelInstance,
            ];

            if ('text' !== $messageType) {
                $details['media_url'] = $mediaUrl;
            }

            $responseData = [
                'closeModal' => true,
                'v'          => self::PLUGIN_VERSION,
            ];

            if (true === $result) {
                $stat->setDetails($details);
                $this->addFlashMessage('whatsaas.send.success');
            } else {
                $stat->setIsFailed(true);
                $details['error'] = $result;
                $stat->setDetails($details);
                $this->addFlashMessage('whatsaas.send.error.failed_detail', ['%error%' => $result], 'error');
                $responseData['error'] = $result;
            }

            $smsModel->getStatRepository()->saveEntity($stat);
            $responseData['flashes'] = $this->getFlashContent();

            return new JsonResponse($responseData);
        }

        return new Response('Bad Request', 400);
    }

    /**
     * Redirect to WhatSaaS chat dashboard for a contact.
     *
     * Opens the WhatSaaS chat UI for the contact's whatsapp number.
     * URL: /whatsaas/openChat/{contactId}
     */
    public function openChatAction(
        Configuration $configuration,
        $objectId = '',
    ): Response {
        $leadModel = $this->getModel('lead');
        $lead      = $leadModel->getEntity($objectId);

        if (!$lead) {
            return new Response('Contact not found', 404);
        }

        try {
            $channel = $configuration->getDefaultChannel();
        } catch (ConfigurationException $e) {
            return new Response('WhatSaaS not configured', 500);
        }

        $urlTemplate = $channel['whatsaasUrl'] ?? '';
        if (empty($urlTemplate)) {
            return new Response('WhatSaaS chat URL not configured', 500);
        }

        // Get phone: whatsapp field → mobile → phone
        $phone = $lead->getFieldValue('whatsapp') ?: $lead->getMobile() ?: $lead->getPhone();
        if (empty($phone)) {
            return new Response('Contact has no phone number', 400);
        }

        // Strip formatting — WhatSaaS expects bare number (e.g. 31612345678)
        $phone = preg_replace('/[\s\-\(\)\+]/', '', $phone);
        $url   = str_replace('{phone}', $phone, $urlTemplate);

        return new RedirectResponse($url);
    }
}
