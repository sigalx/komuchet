<?php

namespace App\Form;

use App\Entity\ElectricityConsumptionBand;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ElectricityConsumptionBandRuleRangeType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('consumptionBand', EntityType::class, [
                'label' => 'Диапазон',
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
            ->add('lowerBoundKwh', TextType::class, [
                'label' => 'Нижняя граница, кВт⋅ч',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите нижнюю границу.'),
                    new Regex(
                        pattern: '/^\d+(?:[.,]\d{1,3})?$/',
                        message: 'Граница должна быть неотрицательным числом с точностью до 3 знаков.',
                    ),
                ],
            ])
            ->add('upperBoundKwh', TextType::class, [
                'label' => 'Верхняя граница, кВт⋅ч',
                'required' => false,
                'empty_data' => '',
                'constraints' => [
                    new Regex(
                        pattern: '/^\d+(?:[.,]\d{1,3})?$/',
                        message: 'Граница должна быть неотрицательным числом с точностью до 3 знаков.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'active_consumption_bands' => [],
        ]);
        $resolver->setAllowedTypes('active_consumption_bands', 'array');
    }
}
