<?php

namespace App\Form;

use App\Entity\Workspace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class WorkspaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Код',
                'help' => 'Короткий технический код: латиница, цифры, дефис или подчеркивание.',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите код хозяйства.'),
                    new Length(max: 100, maxMessage: 'Код не должен быть длиннее {{ limit }} символов.'),
                    new Regex(
                        pattern: '/^[a-z0-9_-]+$/',
                        message: 'Код может содержать только строчные латинские буквы, цифры, дефис и подчеркивание.',
                    ),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Название',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'Укажите название хозяйства.'),
                    new Length(max: 500, maxMessage: 'Название не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'constraints' => [
                    new Length(max: 4000, maxMessage: 'Описание не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'Часовой пояс',
                'help' => 'Основные российские часовые зоны.',
                'choices' => RussianTimezoneChoices::choices(),
                'choice_translation_domain' => false,
                'invalid_message' => 'Выберите часовой пояс из списка.',
                'constraints' => [
                    new NotBlank(message: 'Укажите часовой пояс.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Workspace::class,
        ]);
    }
}
