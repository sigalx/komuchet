<?php

namespace App\Form;

use App\Enum\BillingRunAccountIssueCloseReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class BillingRunAccountIssueCloseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'class' => BillingRunAccountIssueCloseReason::class,
                'choices' => [
                    BillingRunAccountIssueCloseReason::Resolved,
                    BillingRunAccountIssueCloseReason::Ignored,
                ],
                'choice_label' => static fn (BillingRunAccountIssueCloseReason $reason): string => $reason->label(),
                'label' => 'Причина',
                'constraints' => [
                    new NotBlank(message: 'Выберите причину закрытия.'),
                ],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                ],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'Комментарий не должен быть длиннее {{ limit }} символов.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'close_billing_run_account_issue',
        ]);
    }
}
