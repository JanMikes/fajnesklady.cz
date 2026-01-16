<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokonceno', name: 'public_order_complete')]
final class OrderCompleteController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
    ) {
    }

    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // Only show completion page for completed orders
        if (OrderStatus::COMPLETED !== $order->status) {
            $this->addFlash('error', 'Tato objednávka nebyla dokončena.');

            return $this->redirectToRoute('app_home');
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Get the contract
        $contract = $this->contractRepository->findByOrder($order);

        return $this->render('public/order_complete.html.twig', [
            'order' => $order,
            'contract' => $contract,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
        ]);
    }
}
