<?php

namespace App\Form;

use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityConsumptionBandRule;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

class ElectricityConsumptionBandRuleType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tariffProfile', EntityType::class, [
                'label' => 'Тарифный профиль',
                'class' => ElectricityTariffProfile::class,
                'choices' => $options['active_tariff_profiles'],
                'choice_label' => static fn (ElectricityTariffProfile $tariffProfile): string => sprintf('%s - %s', $tariffProfile->getCode(), $tariffProfile->getName()),
                'placeholder' => 'Выберите профиль',
                'attr' => [
                    'class' => 'js-searchable-select',
                    'data-search-placeholder' => 'Начните вводить тарифный профиль',
                ],
                'constraints' => [
                    new NotBlank(message: 'Выберите тарифный профиль.'),
                ],
            ])
            ->add('month', ChoiceType::class, [
                'label' => 'Месяц',
                'choices' => [
                    'Январь' => 1,
                    'Февраль' => 2,
                    'Март' => 3,
                    'Апрель' => 4,
                    'Май' => 5,
                    'Июнь' => 6,
                    'Июль' => 7,
                    'Август' => 8,
                    'Сентябрь' => 9,
                    'Октябрь' => 10,
                    'Ноябрь' => 11,
                    'Декабрь' => 12,
                ],
            ])
            ->add('validFrom', DateType::class, [
                'label' => 'Действует с',
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
                    new NotBlank(message: 'Укажите дату начала.'),
                ],
            ])
            ->add('validTo', DateType::class, [
                'label' => 'Действует до',
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
            ->add('allocationMethod', ChoiceType::class, [
                'label' => 'Распределение по зонам',
                'choices' => [
                    'По общему потреблению, пропорционально зонам' => ElectricityConsumptionBandAllocationMethod::TotalProportional,
                    'Отдельно внутри каждой тарифной зоны' => ElectricityConsumptionBandAllocationMethod::PerTariffZone,
                ],
                'choice_value' => static fn (?ElectricityConsumptionBandAllocationMethod $allocationMethod): string => $allocationMethod?->value ?? '',
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'Приоритет',
                'constraints' => [
                    new PositiveOrZero(message: 'Приоритет не может быть отрицательным.'),
                ],
            ])
            ->add('sourceDocument', TextareaType::class, [
                'label' => 'Документ-основание',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Документ-основание не должен быть длиннее {{ limit }} символов.'),
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
            'data_class' => ElectricityConsumptionBandRule::class,
            'active_tariff_profiles' => [],
        ]);
        $resolver->setAllowedTypes('active_tariff_profiles', 'array');
    }
}
