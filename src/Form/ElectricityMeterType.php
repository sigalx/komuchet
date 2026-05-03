<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityTariffZone;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ElectricityMeterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['include_account']) {
            $builder->add('account', EntityType::class, [
                'label' => 'Участок',
                'class' => Account::class,
                'choices' => $options['active_accounts'],
                'choice_label' => 'number',
                'placeholder' => 'Выберите участок',
                'attr' => [
                    'class' => 'js-searchable-select',
                    'data-search-placeholder' => 'Начните вводить номер участка',
                ],
                'constraints' => [
                    new NotBlank(message: 'Выберите участок.'),
                ],
            ]);
        }

        $builder
            ->add('serialNumber', TextType::class, [
                'label' => 'Серийный номер',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'Серийный номер не должен быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('model', TextType::class, [
                'label' => 'Модель',
                'required' => false,
                'constraints' => [
                    new Length(max: 255, maxMessage: 'Модель не должна быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('installedOn', DateType::class, [
                'label' => 'Дата установки',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Укажите дату в формате дд.мм.гггг.',
                'attr' => [
                    'class' => 'js-date-picker',
                    'placeholder' => 'дд.мм.гггг',
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите дату установки.'),
                ],
            ])
            ->add('removedOn', DateType::class, [
                'label' => 'Дата снятия',
                'required' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Укажите дату в формате дд.мм.гггг.',
                'attr' => [
                    'class' => 'js-date-picker',
                    'placeholder' => 'дд.мм.гггг',
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('verifiedOn', DateType::class, [
                'label' => 'Дата поверки',
                'required' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Укажите дату в формате дд.мм.гггг.',
                'attr' => [
                    'class' => 'js-date-picker',
                    'placeholder' => 'дд.мм.гггг',
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('verificationValidUntil', DateType::class, [
                'label' => 'Поверка действует до',
                'required' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Укажите дату в формате дд.мм.гггг.',
                'attr' => [
                    'class' => 'js-date-picker',
                    'placeholder' => 'дд.мм.гггг',
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Заметки',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Заметка не должна быть длиннее {{ limit }} символов.'),
                ],
            ])
        ;

        if ($options['include_registers']) {
            $builder->add('tariffZones', EntityType::class, [
                'label' => 'Тарифные зоны счетчика',
                'class' => ElectricityTariffZone::class,
                'choices' => $options['active_tariff_zones'],
                'choice_label' => static fn (ElectricityTariffZone $tariffZone): string => sprintf('%s - %s', $tariffZone->getCode(), $tariffZone->getName()),
                'multiple' => true,
                'mapped' => false,
                'attr' => [
                    'class' => 'js-searchable-select',
                    'data-search-placeholder' => 'Начните вводить тарифную зону',
                ],
                'constraints' => [
                    new Count(min: 1, minMessage: 'Выберите хотя бы одну тарифную зону.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ElectricityMeter::class,
            'active_accounts' => [],
            'active_tariff_zones' => [],
            'include_account' => true,
            'include_registers' => true,
        ]);
        $resolver->setAllowedTypes('active_accounts', 'array');
        $resolver->setAllowedTypes('active_tariff_zones', 'array');
        $resolver->setAllowedTypes('include_account', 'bool');
        $resolver->setAllowedTypes('include_registers', 'bool');
    }
}
