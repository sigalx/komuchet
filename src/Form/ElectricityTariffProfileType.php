<?php

namespace App\Form;

use App\Entity\ElectricityTariffProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ElectricityTariffProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Код',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'snt',
                    'maxlength' => 64,
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите код тарифного профиля.'),
                    new Length(max: 64, maxMessage: 'Код тарифного профиля не должен быть длиннее {{ limit }} символов.'),
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
                    new NotBlank(message: 'Укажите название тарифного профиля.'),
                    new Length(max: 255, maxMessage: 'Название тарифного профиля не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Описание не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ElectricityTariffProfile::class,
        ]);
    }
}
