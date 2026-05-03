<?php

namespace App\Form;

use App\Entity\Subscriber;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserSubscriberLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subscriber', EntityType::class, [
                'label' => 'Абонент',
                'class' => Subscriber::class,
                'choices' => $options['active_subscribers'],
                'choice_label' => 'displayName',
                'placeholder' => 'Выберите абонента',
                'attr' => [
                    'class' => 'js-searchable-select',
                    'data-search-placeholder' => 'Начните вводить ФИО абонента',
                ],
                'constraints' => [
                    new NotBlank(message: 'Выберите абонента.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'active_subscribers' => [],
        ]);
        $resolver->setAllowedTypes('active_subscribers', 'array');
    }
}
