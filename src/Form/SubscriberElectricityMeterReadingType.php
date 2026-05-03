<?php

namespace App\Form;

use App\Entity\ElectricityTariffZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class SubscriberElectricityMeterReadingType extends AbstractType
{
    public static function readingFieldName(ElectricityTariffZone $tariffZone): string
    {
        return 'reading_'.str_replace('-', '_', $tariffZone->getUuid()->toRfc4122());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('takenOn', DateType::class, [
                'label' => 'Дата снятия',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'data' => $options['default_taken_on'],
                'invalid_message' => 'Укажите дату в формате дд.мм.гггг.',
                'attr' => [
                    'class' => 'js-date-picker',
                    'placeholder' => 'дд.мм.гггг',
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите дату снятия.'),
                ],
            ])
        ;

        foreach ($options['tariff_zones'] as $tariffZone) {
            if (!$tariffZone instanceof ElectricityTariffZone) {
                continue;
            }

            $builder->add(self::readingFieldName($tariffZone), TextType::class, [
                'label' => sprintf('%s, кВт⋅ч', $tariffZone->getName()),
                'mapped' => false,
                'empty_data' => '',
                'help' => $tariffZone->getCode(),
                'constraints' => [
                    new NotBlank(message: 'Укажите показание.'),
                    new Regex(
                        pattern: '/^\d+(?:[.,]\d{1,3})?$/',
                        message: 'Показание должно быть неотрицательным числом с точностью до 3 знаков.',
                    ),
                ],
            ]);
        }

        $builder->add('notes', TextareaType::class, [
            'label' => 'Заметки',
            'required' => false,
            'attr' => [
                'rows' => 3,
            ],
            'constraints' => [
                new Length(max: 2000, maxMessage: 'Заметка не должна быть длиннее {{ limit }} символов.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'tariff_zones' => [],
            'default_taken_on' => null,
        ]);
        $resolver->setAllowedTypes('tariff_zones', 'array');
        $resolver->setAllowedTypes('default_taken_on', ['null', \DateTimeImmutable::class]);
    }
}
