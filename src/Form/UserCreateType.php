<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите email.'),
                    new Email(message: 'Укажите корректный email.'),
                    new Length(max: 320, maxMessage: 'Email не должен быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Пароли не совпадают.',
                'first_options' => [
                    'label' => 'Пароль',
                ],
                'second_options' => [
                    'label' => 'Повтор пароля',
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите пароль.'),
                    new Length(min: 12, max: 4096, minMessage: 'Пароль должен быть не короче {{ limit }} символов.'),
                ],
            ])
            ->add('approved', CheckboxType::class, [
                'label' => 'Одобрить вход сразу',
                'required' => false,
                'data' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
