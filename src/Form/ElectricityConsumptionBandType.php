<?php

namespace App\Form;

use App\Entity\ElectricityConsumptionBand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Regex;

class ElectricityConsumptionBandType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Код',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'social_norm',
                    'maxlength' => 64,
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите код диапазона потребления.'),
                    new Length(max: 64, maxMessage: 'Код диапазона потребления не должен быть длиннее {{ limit }} символов.'),
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
                    new NotBlank(message: 'Укажите название диапазона потребления.'),
                    new Length(max: 255, maxMessage: 'Название диапазона потребления не должно быть длиннее {{ limit }} символов.'),
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
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Порядок сортировки',
                'constraints' => [
                    new PositiveOrZero(message: 'Порядок сортировки не может быть отрицательным.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ElectricityConsumptionBand::class,
        ]);
    }
}
