<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\AdminTerminateContractCommand;
use App\Entity\User;
use App\Repository\ContractRepository;
use App\Service\Security\PasswordConfirmation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/contracts/{id}/terminate', name: 'admin_contract_terminate', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminContractTerminateController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PasswordConfirmation $passwordConfirmation,
    ) {
    }

    public function __invoke(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        try {
            $contract = $this->contractRepository->get(Uuid::fromString($id));
        } catch (\Exception) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        if (!$this->passwordConfirmation->isValid($user, $request->request->getString('password'))) {
            $this->addFlash('error', 'Zadané heslo není správné. Akce nebyla provedena.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
        }

        $terminationType = $request->request->getString('termination_type');
        if (!in_array($terminationType, ['immediate', 'with_notice'], true)) {
            $this->addFlash('error', 'Neplatný typ ukončení.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
        }

        $immediate = 'immediate' === $terminationType;

        if ($contract->isTerminated()) {
            $this->addFlash('error', 'Smlouva je již ukončena.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
        }

        if (!$immediate && $contract->hasPendingTermination()) {
            $this->addFlash('error', 'Výpověď smlouvy již byla podána.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
        }

        $reason = $request->request->getString('reason');
        if ('' === $reason) {
            $reason = null;
        }
        if (null !== $reason && mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        $this->commandBus->dispatch(new AdminTerminateContractCommand(
            contract: $contract,
            immediate: $immediate,
            reason: $reason,
            // Only meaningful for immediate termination — with-notice keeps
            // billing until terminatesAt, where the overdue cron records debt.
            recordDebt: $immediate && $request->request->getBoolean('record_debt'),
        ));

        if ($immediate) {
            $this->addFlash('success', 'Smlouva byla okamžitě ukončena.');
        } else {
            $this->addFlash('success', sprintf(
                'Výpověď smlouvy byla podána. Smlouva bude ukončena k %s.',
                $contract->terminatesAt?->format('d.m.Y') ?? '',
            ));
        }

        return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
    }
}
