<?php

namespace App\Form;

use App\Service\UserPasswordManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Текущий пароль',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Укажите текущий пароль.'),
                ],
                'attr' => [
                    'autocomplete' => 'current-password',
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Пароли не совпадают.',
                'first_options' => [
                    'label' => 'Новый пароль',
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],
                'second_options' => [
                    'label' => 'Повтор нового пароля',
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите новый пароль.'),
                    new Length(
                        min: UserPasswordManager::MIN_PASSWORD_LENGTH,
                        max: 4096,
                        minMessage: 'Пароль должен быть не короче {{ limit }} символов.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
        ]);
    }
}
