<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Command\CancelRecurringPaymentCommand;
use App\Entity\User;
use App\Repository\ContractRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/smlouvy/{id}/ukoncit', name: 'portal_user_contract_terminate', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class ContractTerminateController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        try {
            $contract = $this->contractRepository->get(Uuid::fromString($id));
        } catch (\Exception) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$contract->user->id->equals($user->id)) {
            throw new AccessDeniedHttpException('Nemáte přístup k této smlouvě.');
        }

        if (!$contract->billingMode->isRecurring()) {
            $this->addFlash('error', 'Smlouvu s jednorázovou platbou nelze předčasně ukončit.');

            return $this->redirectToRoute('portal_user_order_detail', ['id' => $contract->order->id]);
        }

        if ($contract->isTerminated()) {
            $this->addFlash('error', 'Tato smlouva je již ukončena.');

            return $this->redirectToRoute('portal_user_order_detail', ['id' => $contract->order->id]);
        }

        if ($contract->hasPendingTermination()) {
            $this->addFlash('error', 'Výpověď smlouvy již byla podána.');

            return $this->redirectToRoute('portal_user_order_detail', ['id' => $contract->order->id]);
        }

        // Audit logging lives in CancelRecurringPaymentHandler — anything
        // persisted here after dispatch() returns would never be flushed.
        $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));

        $this->addFlash('success', sprintf(
            'Opakované platby byly zrušeny. Smlouva skončí %s.',
            $contract->endDate?->format('d.m.Y') ?? '',
        ));

        return $this->redirectToRoute('portal_user_order_detail', ['id' => $contract->order->id]);
    }
}
