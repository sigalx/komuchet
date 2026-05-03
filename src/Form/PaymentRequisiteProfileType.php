<?php

namespace App\Form;

use App\Entity\PaymentRequisiteProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class PaymentRequisiteProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Код',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'main',
                    'maxlength' => 64,
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите код профиля реквизитов.'),
                    new Length(max: 64, maxMessage: 'Код не должен быть длиннее {{ limit }} символов.'),
                    new Regex(
                        pattern: '/^[a-z0-9][a-z0-9_-]*$/',
                        message: 'Код должен начинаться с латинской буквы или цифры и содержать только строчные латинские буквы, цифры, дефис или подчеркивание.',
                    ),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Название',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите название профиля реквизитов.'),
                    new Length(max: 255, maxMessage: 'Название не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('recipientName', TextType::class, [
                'label' => 'Получатель',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите получателя платежа.'),
                    new Length(max: 500, maxMessage: 'Получатель не должен быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('recipientInn', TextType::class, [
                'label' => 'ИНН',
                'required' => false,
                'constraints' => [
                    new Regex(pattern: '/^(?:\d{10}|\d{12})$/', message: 'ИНН должен состоять из 10 или 12 цифр.'),
                ],
            ])
            ->add('recipientKpp', TextType::class, [
                'label' => 'КПП',
                'required' => false,
                'constraints' => [
                    new Regex(pattern: '/^\d{9}$/', message: 'КПП должен состоять из 9 цифр.'),
                ],
            ])
            ->add('bankName', TextType::class, [
                'label' => 'Банк',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите банк получателя.'),
                    new Length(max: 500, maxMessage: 'Название банка не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('bankBik', TextType::class, [
                'label' => 'БИК',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите БИК банка.'),
                    new Regex(pattern: '/^\d{9}$/', message: 'БИК должен состоять из 9 цифр.'),
                ],
            ])
            ->add('bankCorrespondentAccount', TextType::class, [
                'label' => 'Корреспондентский счет',
                'required' => false,
                'constraints' => [
                    new Regex(pattern: '/^\d{20}$/', message: 'Корреспондентский счет должен состоять из 20 цифр.'),
                ],
            ])
            ->add('bankAccount', TextType::class, [
                'label' => 'Расчетный счет',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите расчетный счет.'),
                    new Regex(pattern: '/^\d{20}$/', message: 'Расчетный счет должен состоять из 20 цифр.'),
                ],
            ])
            ->add('paymentPurposeTemplate', TextareaType::class, [
                'label' => 'Шаблон назначения платежа',
                'required' => false,
                'help' => 'Доступны {statement_number}, {account_number}, {statement_date}, {amount_to_pay}, {workspace_name}.',
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Шаблон назначения не должен быть длиннее {{ limit }} символов.'),
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
                    new NotBlank(message: 'Укажите дату начала действия.'),
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentRequisiteProfile::class,
        ]);
    }
}
