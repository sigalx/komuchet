<?php

namespace App\Form;

use App\Entity\ElectricityTariffProfile;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class AccountElectricityTariffProfileAssignType extends AbstractType
{
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
            ->add('validFrom', DateType::class, [
                'label' => 'Действует с',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'data' => new DateTimeImmutable('today'),
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
            'active_tariff_profiles' => [],
        ]);
        $resolver->setAllowedTypes('active_tariff_profiles', 'array');
    }
}
