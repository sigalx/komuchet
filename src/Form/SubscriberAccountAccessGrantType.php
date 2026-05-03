<?php

namespace App\Form;

use App\Entity\Account;
use App\Enum\SubscriberAccountAccessRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class SubscriberAccountAccessGrantType extends AbstractType
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
            ->add('accessRole', EnumType::class, [
                'label' => 'Роль доступа',
                'class' => SubscriberAccountAccessRole::class,
                'choice_label' => static fn (SubscriberAccountAccessRole $role): string => $role->label(),
                'data' => SubscriberAccountAccessRole::Owner,
                'constraints' => [
                    new NotBlank(message: 'Выберите роль доступа.'),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Заметки',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Заметка не должна быть длиннее {{ limit }} символов.'),
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
