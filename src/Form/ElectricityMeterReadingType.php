<?php

namespace App\Form;

use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffZone;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ElectricityMeterReadingType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tariffZone', EntityType::class, [
                'label' => 'Тарифная зона',
                'class' => ElectricityTariffZone::class,
                'choices' => $options['meter_tariff_zones'],
                'choice_label' => static fn (ElectricityTariffZone $tariffZone): string => sprintf('%s - %s', $tariffZone->getCode(), $tariffZone->getName()),
                'placeholder' => 'Выберите зону',
                'attr' => [
                    'class' => 'js-searchable-select',
                    'data-search-placeholder' => 'Начните вводить тарифную зону',
                ],
                'constraints' => [
                    new NotBlank(message: 'Выберите тарифную зону.'),
                ],
            ])
            ->add('readingValue', TextType::class, [
                'label' => 'Показание, кВт⋅ч',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите показание.'),
                    new Regex(
                        pattern: '/^\d+(?:[.,]\d{1,3})?$/',
                        message: 'Показание должно быть неотрицательным числом с точностью до 3 знаков.',
                    ),
                ],
            ])
            ->add('takenOn', DateType::class, [
                'label' => 'Дата снятия',
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
                    new NotBlank(message: 'Укажите дату снятия.'),
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ElectricityMeterReading::class,
            'meter_tariff_zones' => [],
        ]);
        $resolver->setAllowedTypes('meter_tariff_zones', 'array');
    }
}
