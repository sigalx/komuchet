<?php

namespace App\Form;

use App\Entity\Payment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class PaymentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', TextType::class, [
                'label' => 'Сумма, руб.',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите сумму оплаты.'),
                    new Regex(
                        pattern: '/^(?!0+(?:[.,]0{1,2})?$)\d+(?:[.,]\d{1,2})?$/',
                        message: 'Сумма должна быть положительным числом с точностью до копеек.',
                    ),
                ],
            ])
            ->add('paidOn', DateType::class, [
                'label' => 'Дата оплаты',
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
                    new NotBlank(message: 'Укажите дату оплаты.'),
                ],
            ])
            ->add('payerName', TextType::class, [
                'label' => 'Плательщик',
                'required' => false,
                'constraints' => [
                    new Length(max: 500, maxMessage: 'Имя плательщика не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('purpose', TextareaType::class, [
                'label' => 'Назначение платежа',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Назначение платежа не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('externalReference', TextType::class, [
                'label' => 'Идентификатор операции',
                'required' => false,
                'help' => 'Номер операции, строка из выписки или другой идентификатор источника.',
                'constraints' => [
                    new Length(max: 500, maxMessage: 'Идентификатор операции не должен быть длиннее {{ limit }} символов.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Payment::class,
        ]);
    }
}
