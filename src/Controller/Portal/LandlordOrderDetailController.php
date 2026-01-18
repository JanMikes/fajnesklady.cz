<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Service\ContractService;
use App\Service\Security\ContractVoter;
use App\Service\Security\OrderVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/landlord/orders/{id}', name: 'portal_landlord_order_detail')]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordOrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ContractService $contractService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();
        $contract = $this->contractRepository->findByOrder($order);
        $invoice = $this->invoiceRepository->findByOrder($order);

        $daysRemaining = null;
        $canTerminate = false;

        if (null !== $contract) {
            $now = $this->clock->now();
            $daysRemaining = $this->contractService->getDaysRemaining($contract, $now);
            $canTerminate = $this->isGranted(ContractVoter::TERMINATE, $contract);
        }

        return $this->render('portal/landlord/order/detail.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'contract' => $contract,
            'invoice' => $invoice,
            'daysRemaining' => $daysRemaining,
            'canTerminate' => $canTerminate,
        ]);
    }
}
