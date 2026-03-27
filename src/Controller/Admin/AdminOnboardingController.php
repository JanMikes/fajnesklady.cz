<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/onboarding', name: 'admin_onboarding')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOnboardingController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('admin/onboarding/index.html.twig');
    }
}
