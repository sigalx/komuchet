<?php

namespace App\Form;

use App\Entity\BillingSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class BillingSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('associationName', TextType::class, [
                'label' => 'Название хозяйства',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите название хозяйства.'),
                    new Length(max: 500, maxMessage: 'Название не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'Часовой пояс',
                'help' => 'Основные российские часовые зоны.',
                'mapped' => false,
                'data' => $options['workspace_timezone'],
                'choices' => RussianTimezoneChoices::choices(),
                'choice_translation_domain' => false,
                'invalid_message' => 'Выберите часовой пояс из списка.',
                'constraints' => [
                    new NotBlank(message: 'Укажите часовой пояс.'),
                ],
            ])
            ->add('invoiceGenerationDay', IntegerType::class, [
                'label' => 'День формирования квитанций',
                'help' => 'Число месяца от 1 до 28.',
                'constraints' => [
                    new NotBlank(message: 'Укажите день формирования квитанций.'),
                    new Range(
                        notInRangeMessage: 'День формирования должен быть от {{ min }} до {{ max }}.',
                        min: 1,
                        max: 28,
                    ),
                ],
            ])
            ->add('readingFreshnessWindowDays', IntegerType::class, [
                'label' => 'Актуальность показаний, дней',
                'help' => 'Сколько дней до даты формирования показание считается актуальным.',
                'constraints' => [
                    new NotBlank(message: 'Укажите окно актуальности показаний.'),
                    new Range(
                        notInRangeMessage: 'Окно актуальности должно быть от {{ min }} до {{ max }} дней.',
                        min: 1,
                        max: 60,
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BillingSettings::class,
            'workspace_timezone' => 'Europe/Moscow',
        ]);
        $resolver->setAllowedTypes('workspace_timezone', 'string');
    }
}
