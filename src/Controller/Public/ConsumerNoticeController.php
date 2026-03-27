<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pouceni-spotrebitele', name: 'public_consumer_notice')]
final class ConsumerNoticeController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('public/consumer_notice.html.twig');
    }
}
