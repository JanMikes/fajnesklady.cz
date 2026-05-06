<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Service\PriceCalculator;
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
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PriceCalculator $priceCalculator,
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

        // DB is the source of truth: COMPLETED can be reached via GoPay (webhook
        // already verified the payment with GoPay before flipping status), via
        // admin manual completion, or via the onboarding flow — none of which
        // need re-verification at view time.
        if (OrderStatus::COMPLETED !== $order->status) {
            $this->addFlash('error', 'Tato objednávka nebyla dokončena.');

            return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $contract = $this->contractRepository->findByOrder($order);
        $invoice = $this->invoiceRepository->findByOrder($order);

        $isRecurring = $this->priceCalculator->needsRecurringBilling($order->startDate, $order->endDate);

        return $this->render('public/order_complete.html.twig', [
            'order' => $order,
            'contract' => $contract,
            'invoice' => $invoice,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'isRecurring' => $isRecurring,
        ]);
    }
}
