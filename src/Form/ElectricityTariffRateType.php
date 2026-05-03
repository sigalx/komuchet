<?php

namespace App\Form;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityTariffZone;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ElectricityTariffRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['include_dimensions']) {
            $builder
                ->add('tariffZone', EntityType::class, [
                    'label' => 'Тарифная зона',
                    'class' => ElectricityTariffZone::class,
                    'choices' => $options['active_tariff_zones'],
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
                ->add('consumptionBand', EntityType::class, [
                    'label' => 'Диапазон потребления',
                    'class' => ElectricityConsumptionBand::class,
                    'choices' => $options['active_consumption_bands'],
                    'choice_label' => static fn (ElectricityConsumptionBand $band): string => sprintf('%s - %s', $band->getCode(), $band->getName()),
                    'placeholder' => 'Выберите диапазон',
                    'attr' => [
                        'class' => 'js-searchable-select',
                        'data-search-placeholder' => 'Начните вводить диапазон',
                    ],
                    'constraints' => [
                        new NotBlank(message: 'Выберите диапазон потребления.'),
                    ],
                ])
            ;
        }

        $builder
            ->add('rate', TextType::class, [
                'label' => 'Ставка, руб/кВт⋅ч',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите ставку.'),
                    new Regex(
                        pattern: '/^\d+(?:[.,]\d{1,6})?$/',
                        message: 'Ставка должна быть неотрицательным числом с точностью до 6 знаков.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'active_tariff_zones' => [],
            'active_consumption_bands' => [],
            'include_dimensions' => true,
        ]);
        $resolver->setAllowedTypes('active_tariff_zones', 'array');
        $resolver->setAllowedTypes('active_consumption_bands', 'array');
        $resolver->setAllowedTypes('include_dimensions', 'bool');
    }
}
