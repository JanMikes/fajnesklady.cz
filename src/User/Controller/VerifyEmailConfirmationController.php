<?php

declare(strict_types=1);

namespace App\User\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/verify-email/confirmation', name: 'app_verify_email_confirmation', methods: ['GET'])]
final class VerifyEmailConfirmationController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('user/verify_email_confirmation.html.twig');
    }
}
