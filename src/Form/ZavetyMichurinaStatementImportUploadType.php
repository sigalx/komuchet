<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ZavetyMichurinaStatementImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название пачки',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Например: Архив квитанций за 2024',
                    'maxlength' => 255,
                ],
                'constraints' => [
                    new Length(max: 255, maxMessage: 'Название не должно быть длиннее {{ limit }} символов.'),
                ],
            ])
            ->add('files', FileType::class, [
                'label' => 'PDF-файлы',
                'mapped' => false,
                'multiple' => true,
                'required' => true,
                'help' => 'Можно выбрать несколько файлов сразу. Оригиналы PDF на этом шаге не сохраняются.',
                'attr' => [
                    'accept' => 'application/pdf,.pdf',
                ],
                'constraints' => [
                    new NotBlank(message: 'Выберите хотя бы один файл.'),
                    new Count(min: 1, minMessage: 'Выберите хотя бы один файл.'),
                    new All([
                        new File(
                            maxSize: '20M',
                            maxSizeMessage: 'Файл не должен быть больше {{ limit }} {{ suffix }}.',
                        ),
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
