<?php

namespace App\Form;

use App\Entity\Accrual;
use App\Enum\AccrualType as AccrualTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class AccrualType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'label' => 'Тип начисления',
                'class' => AccrualTypeEnum::class,
                'choice_label' => static fn (AccrualTypeEnum $type): string => $type->label(),
            ])
            ->add('amount', TextType::class, [
                'label' => 'Сумма, руб.',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите сумму начисления.'),
                    new Regex(
                        pattern: '/^(?!0+(?:[.,]0{1,2})?$)\d+(?:[.,]\d{1,2})?$/',
                        message: 'Сумма должна быть положительным числом с точностью до копеек.',
                    ),
                ],
            ])
            ->add('periodStart', DateType::class, [
                'label' => 'Начало периода',
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
                    new NotBlank(message: 'Укажите начало периода.'),
                ],
            ])
            ->add('periodEnd', DateType::class, [
                'label' => 'Конец периода',
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
                    new NotBlank(message: 'Укажите конец периода.'),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Комментарий не должен быть длиннее {{ limit }} символов.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Accrual::class,
        ]);
    }
}
