<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pronajimatel/cekani-na-overeni', name: 'app_landlord_awaiting_verification', methods: ['GET'])]
final class LandlordAwaitingVerificationController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('user/landlord_awaiting_verification.html.twig');
    }
}
