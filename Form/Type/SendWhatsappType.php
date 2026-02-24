<?php

namespace MauticPlugin\WhatSaasBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SendWhatsappType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'contactId',
            HiddenType::class,
            [
                'data' => $options['data']['contactId'] ?? '',
            ]
        );

        // Channel selector (populated via options)
        $channelChoices = $options['channel_choices'] ?? [];
        if (!empty($channelChoices)) {
            $builder->add(
                'channel',
                ChoiceType::class,
                [
                    'label'      => 'whatsaas.send.channel',
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => ['class' => 'form-control'],
                    'choices'    => $channelChoices,
                    'required'   => true,
                ]
            );
        }

        // SMS Template selector
        $templateChoices = $options['template_choices'] ?? [];
        if (!empty($templateChoices)) {
            $builder->add(
                'smsTemplate',
                ChoiceType::class,
                [
                    'label'       => 'whatsaas.send.sms_template',
                    'label_attr'  => ['class' => 'control-label'],
                    'attr'        => [
                        'class'    => 'form-control',
                        'onchange' => 'WhatSaaS.loadTemplate(this)',
                    ],
                    'choices'     => $templateChoices,
                    'required'    => false,
                    'placeholder' => 'whatsaas.send.sms_template_placeholder',
                ]
            );
        }

        $builder->add(
            'messageType',
            ChoiceType::class,
            [
                'label'      => 'whatsaas.send.message_type',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'    => 'form-control',
                    'onchange' => 'WhatSaaS.toggleFields(this.value)',
                ],
                'choices' => [
                    'Text'     => 'text',
                    'Image'    => 'image',
                    'Video'    => 'video',
                    'Document' => 'document',
                    'Audio'    => 'audio',
                ],
                'required' => true,
            ]
        );

        $builder->add(
            'message',
            TextareaType::class,
            [
                'label'      => 'whatsaas.send.message',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'rows'        => 4,
                    'placeholder' => 'whatsaas.send.message_placeholder',
                ],
                'required' => false,
            ]
        );

        $builder->add(
            'mediaUrl',
            TextType::class,
            [
                'label'      => 'whatsaas.send.media_url',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => 'https://example.com/image.jpg',
                ],
                'required' => false,
            ]
        );

        $builder->add(
            'buttons',
            FormButtonsType::class,
            [
                'apply_text'     => false,
                'save_text'      => 'whatsaas.send.submit',
                'cancel_onclick' => 'javascript:void(0);',
                'cancel_attr'    => [
                    'data-dismiss' => 'modal',
                ],
            ]
        );

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'channel_choices'  => [],
            'template_choices' => [],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'whatsaas_send';
    }
}
