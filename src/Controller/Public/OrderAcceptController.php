<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\ContractService;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/prijmout', name: 'public_order_accept')]
final class OrderAcceptController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderService $orderService,
        private readonly ContractService $contractService,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // Check if order is paid and ready for completion
        if (OrderStatus::PAID !== $order->status) {
            if (OrderStatus::COMPLETED === $order->status) {
                return $this->redirectToRoute('public_order_complete', ['id' => $order->id]);
            }

            $this->addFlash('error', 'Tuto objednávku nelze dokončit.');

            return $this->redirectToRoute('app_home');
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storageType->place;

        // Handle contract acceptance
        if ($request->isMethod('POST')) {
            $accepted = $request->request->getBoolean('accept_contract');

            if (!$accepted) {
                $this->addFlash('error', 'Pro dokončení objednávky je nutné souhlasit se smluvními podmínkami.');

                return $this->render('public/order_accept.html.twig', [
                    'order' => $order,
                    'storage' => $storage,
                    'storageType' => $storageType,
                    'place' => $place,
                ]);
            }

            try {
                // Complete the order and create contract
                $contract = $this->orderService->completeOrder($order);

                // Generate contract document if template exists
                if ($place->hasContractTemplate()) {
                    try {
                        $this->contractService->generateDocument($contract);
                    } catch (\Exception $e) {
                        // Log error but don't fail the order
                    }
                }

                // Sign the contract
                $this->contractService->signContract($contract);

                $this->addFlash('success', 'Objednávka byla úspěšně dokončena. Smlouva byla vytvořena.');

                return $this->redirectToRoute('public_order_complete', ['id' => $order->id]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Při dokončování objednávky došlo k chybě.');
            }
        }

        return $this->render('public/order_accept.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
        ]);
    }
}
