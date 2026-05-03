<?php

namespace App\Form;

use App\Enum\WorkspaceUserRoleCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class WorkspaceUserRoleGrantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('roleCode', EnumType::class, [
                'label' => 'Роль',
                'class' => WorkspaceUserRoleCode::class,
                'choice_label' => static fn (WorkspaceUserRoleCode $roleCode): string => $roleCode->label(),
                'placeholder' => 'Выберите роль',
                'constraints' => [
                    new NotBlank(message: 'Выберите роль.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
