<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        if ($this->isGranted('SUBSCRIBER_PORTAL_ACCESS') && !$this->isGranted('WORKSPACE_ACCESS')) {
            return $this->redirectToRoute('app_subscriber_portal');
        }

        return $this->render('dashboard/index.html.twig');
    }
}
