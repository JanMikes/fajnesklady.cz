<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\WaiveContractDebtCommand;
use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use App\Service\Security\PasswordConfirmation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/waive-debt', name: 'admin_order_waive_debt', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderWaiveDebtController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PasswordConfirmation $passwordConfirmation,
    ) {
    }

    public function __invoke(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $contract = $this->contractRepository->findByOrder($order);
        if (null === $contract || !$contract->isTerminated() || !$contract->hasOutstandingDebt()) {
            $this->addFlash('error', 'Tato smlouva nemá evidovaný dluh k odepsání.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        if (!$this->passwordConfirmation->isValid($user, $request->request->getString('password'))) {
            $this->addFlash('error', 'Zadané heslo není správné. Akce nebyla provedena.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $amountInHaler = $this->parseAmountInHaler($request->request->getString('amount'));
        if (null === $amountInHaler || $amountInHaler <= 0 || $amountInHaler > ($contract->outstandingDebtAmount ?? 0)) {
            $this->addFlash('error', 'Neplatná částka. Zadejte hodnotu do výše dluhu.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $reason = trim($request->request->getString('reason'));
        if ('' === $reason) {
            $reason = null;
        } elseif (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        $this->commandBus->dispatch(new WaiveContractDebtCommand(
            contract: $contract,
            amountInHaler: $amountInHaler,
            reason: $reason,
        ));

        $this->addFlash('success', 'Dluh byl odepsán.');

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
