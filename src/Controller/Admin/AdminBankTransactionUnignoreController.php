<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\UnignoreBankTransactionCommand;
use App\Entity\User;
use App\Repository\BankTransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/bankovni-platby/{id}/obnovit', name: 'admin_bank_transaction_unignore', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminBankTransactionUnignoreController extends AbstractController
{
    public function __construct(
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, #[CurrentUser] User $admin): Response
    {
        $transaction = $this->bankTransactionRepository->find(Uuid::fromString($id));
        if (null === $transaction) {
            throw new NotFoundHttpException('Transakce nenalezena.');
        }

        if (!$transaction->isIgnored()) {
            $this->addFlash('error', 'Obnovit lze pouze ignorované transakce.');

            return $this->redirectToRoute('admin_bank_payments', ['filter' => 'ignored']);
        }

        $this->commandBus->dispatch(new UnignoreBankTransactionCommand(
            transactionId: $transaction->id,
            adminId: $admin->id,
        ));

        $this->addFlash('success', 'Transakce byla vrácena mezi nespárované.');

        return $this->redirectToRoute('admin_bank_payments', ['filter' => 'ignored']);
    }
}
