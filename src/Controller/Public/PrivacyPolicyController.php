<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ochrana-osobnich-udaju', name: 'public_privacy_policy')]
final class PrivacyPolicyController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('public/privacy_policy.html.twig');
    }
}
