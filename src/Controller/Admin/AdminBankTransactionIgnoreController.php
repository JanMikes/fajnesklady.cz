<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\IgnoreBankTransactionCommand;
use App\Entity\User;
use App\Repository\BankTransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/bankovni-platby/{id}/ignorovat', name: 'admin_bank_transaction_ignore', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminBankTransactionIgnoreController extends AbstractController
{
    public function __construct(
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id, #[CurrentUser] User $admin): Response
    {
        $transaction = $this->bankTransactionRepository->find(Uuid::fromString($id));
        if (null === $transaction) {
            throw new NotFoundHttpException('Transakce nenalezena.');
        }

        $filter = $request->request->getString('filter');
        $redirectParams = '' !== $filter && 'all' !== $filter ? ['filter' => $filter] : [];

        if (!$transaction->isUnmatched()) {
            $this->addFlash('error', 'Ignorovat lze pouze nespárované transakce.');

            return $this->redirectToRoute('admin_bank_payments', $redirectParams);
        }

        // Cap to the ignore_reason column length (500) — the textarea maxlength is client-side only.
        $reason = mb_substr(trim($request->request->getString('reason')), 0, 500);

        $this->commandBus->dispatch(new IgnoreBankTransactionCommand(
            transactionId: $transaction->id,
            adminId: $admin->id,
            reason: '' === $reason ? null : $reason,
        ));

        $this->addFlash('success', 'Transakce byla označena jako nesouvisející.');

        return $this->redirectToRoute('admin_bank_payments', $redirectParams);
    }
}
