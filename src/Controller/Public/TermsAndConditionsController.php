<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/obchodni-podminky', name: 'public_terms_and_conditions')]
final class TermsAndConditionsController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('public/terms_and_conditions.html.twig');
    }
}
