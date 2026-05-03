<?php

namespace App\Form;

use App\Entity\Subscriber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class SubscriberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastName', TextType::class, [
                'label' => 'Фамилия',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 255),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Имя',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 255),
                ],
            ])
            ->add('secondName', TextType::class, [
                'label' => 'Отчество',
                'required' => false,
                'constraints' => [
                    new Length(max: 255),
                ],
            ])
            ->add('contactEmail', EmailType::class, [
                'label' => 'Контактный email',
                'required' => false,
                'constraints' => [
                    new Email(),
                    new Length(max: 255),
                ],
            ])
            ->add('contactPhone', TextType::class, [
                'label' => 'Контактный телефон',
                'required' => false,
                'constraints' => [
                    new Length(max: 64),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Заметки',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'constraints' => [
                    new Length(max: 2000),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Subscriber::class,
        ]);
    }
}
