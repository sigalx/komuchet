<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\PasswordChangeType;
use App\Service\AuditLogger;
use App\Service\PasswordExpirationChecker;
use App\Service\UserPasswordManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PasswordController extends AbstractController
{
    #[Route('/password/change', name: 'app_password_change', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function change(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UserPasswordManager $passwordManager,
        PasswordExpirationChecker $passwordExpirationChecker,
        AuditLogger $auditLogger,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $passwordExpired = $passwordExpirationChecker->isPasswordExpired($user);
        $form = $this->createForm(PasswordChangeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $currentPassword = (string) $form->get('currentPassword')->getData();

            if ($user->getPassword() === null || !$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $form->get('currentPassword')->addError(new FormError('Текущий пароль указан неверно.'));
            }

            if ($form->isValid()) {
                $oldExpiresAt = $user->getPasswordCredential()?->getExpiresAt();
                $passwordManager->setPassword($user, (string) $form->get('plainPassword')->getData(), $user);
                $auditLogger->record(
                    action: 'user.password_changed',
                    entityTable: 'user_password_credentials',
                    entityPk: ['user_uuid' => $user->getUuid()->toRfc4122()],
                    oldValues: [
                        'expires_at' => $oldExpiresAt?->format(\DateTimeInterface::ATOM),
                    ],
                    newValues: [
                        'expires_at' => null,
                    ],
                    changedFields: ['password_hash', 'expires_at'],
                    reason: 'Пользователь сменил свой пароль.',
                );
                $entityManager->flush();

                $this->addFlash('success', 'Пароль изменен.');

                return $this->redirectToRoute($passwordExpired ? 'app_dashboard' : 'app_profile', status: Response::HTTP_SEE_OTHER);
            }

            return $this->render('password/change.html.twig', [
                'form' => $form,
                'password_expired' => $passwordExpired,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('password/change.html.twig', [
            'form' => $form,
            'password_expired' => $passwordExpired,
        ]);
    }
}
