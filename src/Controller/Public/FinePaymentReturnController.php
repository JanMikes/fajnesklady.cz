<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\FineRepository;
use App\Service\OrderStatusUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/pokuta/{id}/platba/navrat', name: 'public_fine_payment_return', requirements: ['id' => '[0-9a-f-]{36}'])]
final class FinePaymentReturnController extends AbstractController
{
    public function __construct(
        private readonly FineRepository $fineRepository,
        private readonly OrderStatusUrlGenerator $orderStatusUrlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        $fine = $this->fineRepository->findById(Uuid::fromString($id));
        if (null === $fine) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        $statusUrl = $this->orderStatusUrlGenerator->generate($fine->contract->order);

        if ($fine->isPaid()) {
            $this->addFlash('success', 'Pokuta byla úspěšně zaplacena.');
        } else {
            $this->addFlash('info', 'Platba se zpracovává. Stav bude aktualizován po potvrzení platby.');
        }

        return $this->redirect($statusUrl);
    }
}
