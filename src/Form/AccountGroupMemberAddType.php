<?php

namespace App\Form;

use App\Entity\Account;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class AccountGroupMemberAddType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('account', EntityType::class, [
                'label' => 'Участок',
                'class' => Account::class,
                'choices' => $options['active_accounts'],
                'choice_label' => 'number',
                'placeholder' => 'Выберите участок',
                'attr' => [
                    'class' => 'js-searchable-select',
                    'data-search-placeholder' => 'Начните вводить номер участка',
                ],
                'constraints' => [
                    new NotBlank(message: 'Выберите участок.'),
                ],
            ])
            ->add('validFrom', DateType::class, [
                'label' => 'Действует с',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'data' => new DateTimeImmutable('today'),
                'invalid_message' => 'Укажите дату в формате дд.мм.гггг.',
                'attr' => [
                    'class' => 'js-date-picker',
                    'placeholder' => 'дд.мм.гггг',
                    'inputmode' => 'numeric',
                ],
                'constraints' => [
                    new NotBlank(message: 'Укажите дату начала.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'active_accounts' => [],
        ]);
        $resolver->setAllowedTypes('active_accounts', 'array');
    }
}
