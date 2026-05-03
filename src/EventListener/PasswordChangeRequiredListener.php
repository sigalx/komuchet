<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\PasswordExpirationChecker;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class PasswordChangeRequiredListener
{
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_password_change',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly PasswordExpirationChecker $passwordExpirationChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(event: 'kernel.request')]
    public function onRequestEvent(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (is_string($route) && (in_array($route, self::ALLOWED_ROUTES, true) || str_starts_with($route, '_'))) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->passwordExpirationChecker->isPasswordExpired($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_password_change'),
            Response::HTTP_SEE_OTHER,
        ));
    }
}
