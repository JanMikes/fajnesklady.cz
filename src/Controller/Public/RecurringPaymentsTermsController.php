<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/podminky-opakovanych-plateb', name: 'public_recurring_payments_terms')]
final class RecurringPaymentsTermsController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('public/recurring_payments_terms.html.twig');
    }
}
