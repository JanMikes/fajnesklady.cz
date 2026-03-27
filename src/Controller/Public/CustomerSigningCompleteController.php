<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/podpis/dokonceno/{id}', name: 'public_customer_signing_complete', requirements: ['id' => '[0-9a-f-]{36}'])]
final class CustomerSigningCompleteController extends AbstractController
{
    public function __invoke(string $id): Response
    {
        return $this->render('public/customer_signing_complete.html.twig');
    }
}
