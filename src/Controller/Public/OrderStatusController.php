<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\OrderRepository;
use App\Service\Order\OrderStatusViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/stav', name: 'public_order_status', requirements: ['id' => '[0-9a-f-]{36}'])]
final class OrderStatusController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly UriSigner $uriSigner,
        private readonly OrderStatusViewModelFactory $viewModelFactory,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));
        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $viewModel = $this->viewModelFactory->build($order);

        return $this->render('public/order_status.html.twig', ['vm' => $viewModel]);
    }
}
