<?php

namespace MauticPlugin\WhatSaasBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\SmsBundle\Entity\Stat;
use MauticPlugin\WhatSaasBundle\Transport\WhatSaasTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WhatsappApiController extends CommonApiController
{
    /**
     * Send a WhatsApp message to a contact using an SMS template.
     *
     * GET /api/whatsaas/{smsId}/contact/{contactId}/send?channel=instance-name
     */
    public function sendToContactAction(
        Request $request,
        WhatSaasTransport $transport,
        int $smsId,
        int $contactId,
    ): JsonResponse {
        /** @var \Mautic\SmsBundle\Model\SmsModel $smsModel */
        $smsModel = $this->getModel('sms');
        $sms      = $smsModel->getEntity($smsId);

        if (!$sms || !$sms->isPublished()) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'SMS template not found or not published',
            ], 404);
        }

        $leadModel = $this->getModel('lead');
        $lead      = $leadModel->getEntity($contactId);

        if (!$lead) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'Contact not found',
            ], 404);
        }

        $phone = $lead->getLeadPhoneNumber();
        if (empty($phone)) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'Contact has no phone number',
            ], 400);
        }

        // Normalize phone number to E.164
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        if (!str_starts_with($phone, '+')) {
            $phone = str_starts_with($phone, '0')
                ? '+31'.substr($phone, 1)
                : '+'.$phone;
        }

        // Get message content and replace contact field tokens
        $message = $sms->getMessage();
        $message = str_replace(
            ['{contactfield=firstname}', '{contactfield=lastname}', '{contactfield=email}', '{contactfield=phone}', '{contactfield=mobile}'],
            [$lead->getFirstname(), $lead->getLastname(), $lead->getEmail(), $lead->getPhone(), $lead->getMobile()],
            $message
        );

        // Optional channel selection via query param
        $channelInstance = $request->query->get('channel');

        $result = $transport->sendWhatsapp($phone, 'text', $message, null, $channelInstance);

        // Record stat entry
        $stat = new Stat();
        $stat->setDateSent(new \DateTime());
        $stat->setLead($lead);
        $stat->setSms($sms);
        $stat->setTrackingHash(str_replace('.', '', uniqid('', true)));
        $stat->setSource('api');

        $details = [
            'message'  => $message,
            'type'     => 'text',
            'channel'  => 'whatsapp',
            'instance' => $channelInstance,
        ];

        if (true === $result) {
            $stat->setDetails($details);
            $smsModel->getStatRepository()->saveEntity($stat);

            return new JsonResponse([
                'success' => true,
                'status'  => 'Delivered',
                'result'  => [
                    'sent'     => true,
                    'channel'  => 'whatsapp',
                    'instance' => $channelInstance,
                    'id'       => $sms->getId(),
                    'name'     => $sms->getName(),
                    'content'  => $message,
                ],
                'errors' => [],
            ]);
        }

        $details['error'] = $result;
        $stat->setIsFailed(true);
        $stat->setDetails($details);
        $smsModel->getStatRepository()->saveEntity($stat);

        return new JsonResponse([
            'success' => false,
            'error'   => is_string($result) ? $result : 'Unknown error',
        ], 500);
    }
}
