<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\AdvanceNoticeReason;
use App\Event\RecurringPaymentAdvanceNoticeNeeded;
use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Admin trigger for the 7-business-day advance notice required by Podmínky
 * opakovaných plateb čl. V whenever recurring-payment parameters change
 * (price, frequency). Fires the same event the daily cron uses, so the
 * customer-facing e-mail is identical regardless of source.
 */
#[Route('/portal/admin/contracts/{id}/advance-notice', name: 'admin_contract_advance_notice', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminContractAdvanceNoticeController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $contract = $this->contractRepository->get(Uuid::fromString($id));

        if (!$contract->hasActiveRecurringPayment()) {
            $this->addFlash('error', 'Smlouva nemá aktivní opakovanou platbu — upozornění nelze odeslat.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id->toRfc4122()]);
        }

        $newAmountCzk = $request->request->get('new_amount_czk');
        $adminNote = trim((string) $request->request->get('admin_note', ''));

        $newAmountInHaler = null;
        if (null !== $newAmountCzk && '' !== $newAmountCzk) {
            $parsed = (float) str_replace([',', ' '], ['.', ''], (string) $newAmountCzk);
            if ($parsed <= 0) {
                $this->addFlash('error', 'Nová částka musí být kladné číslo.');

                return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id->toRfc4122()]);
            }
            // Display max is 15 000 Kč per Podmínky čl. III; reject above to avoid drift.
            if ($parsed > 15000) {
                $this->addFlash('error', 'Nová částka přesahuje legální maximum 15 000 Kč.');

                return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id->toRfc4122()]);
            }
            $newAmountInHaler = (int) round($parsed * 100);
        }

        if (null === $newAmountInHaler && '' === $adminNote) {
            $this->addFlash('error', 'Vyplňte buď novou částku, nebo důvod změny.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id->toRfc4122()]);
        }

        $this->eventBus->dispatch(new RecurringPaymentAdvanceNoticeNeeded(
            contractId: $contract->id,
            reason: AdvanceNoticeReason::PARAMETER_CHANGE,
            occurredOn: $this->clock->now(),
            newAmount: $newAmountInHaler,
            adminNote: '' !== $adminNote ? $adminNote : null,
        ));

        $this->addFlash('success', 'Upozornění bylo odesláno zákazníkovi.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id->toRfc4122()]);
    }
}
