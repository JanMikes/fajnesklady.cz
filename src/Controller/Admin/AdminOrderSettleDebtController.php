<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\SettleContractDebtCommand;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/settle-debt', name: 'admin_order_settle_debt', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderSettleDebtController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $contract = $this->contractRepository->findByOrder($order);
        if (null === $contract || !$contract->isTerminated() || !$contract->hasOutstandingDebt()) {
            $this->addFlash('error', 'Tato smlouva nemá evidovaný dluh k úhradě.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $amountInHaler = $this->parseAmountInHaler($request->request->getString('amount'));
        if (null === $amountInHaler || $amountInHaler <= 0 || $amountInHaler > ($contract->outstandingDebtAmount ?? 0)) {
            $this->addFlash('error', 'Neplatná částka. Zadejte hodnotu do výše dluhu.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $issueInvoice = $request->request->getBoolean('issue_invoice');

        $this->commandBus->dispatch(new SettleContractDebtCommand(
            contract: $contract,
            amountInHaler: $amountInHaler,
            issueInvoice: $issueInvoice,
        ));

        $message = 'Dluh byl označen jako uhrazený.';
        if ($issueInvoice) {
            $message .= ' Faktura byla vystavena.';
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }

    private function parseAmountInHaler(string $raw): ?int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($raw));
        if ('' === $normalized || !is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized * 100);
    }
}
