<?php

declare(strict_types=1);

namespace App\User\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile', name: 'app_profile')]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('user/profile.html.twig');
    }
}
