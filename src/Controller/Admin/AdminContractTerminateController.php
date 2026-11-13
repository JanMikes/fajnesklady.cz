<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\AdminTerminateContractCommand;
use App\Command\RecordPaymentRefundFailureCommand;
use App\Command\RefundContractPaymentCommand;
use App\Entity\Contract;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Enum\RefundMode;
use App\Exception\PaymentRefundFailed;
use App\Exception\RefundNotPossible;
use App\Repository\ContractRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Refund\RefundablePaymentResolver;
use App\Service\Refund\RefundCalculator;
use App\Service\Security\PasswordConfirmation;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
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
        private readonly RefundablePaymentResolver $refundablePaymentResolver,
        private readonly LoggerInterface $logger,
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

            return $this->redirectToOrder($contract);
        }

        $terminationType = $request->request->getString('termination_type');
        if (!in_array($terminationType, ['immediate', 'with_notice'], true)) {
            $this->addFlash('error', 'Neplatný typ ukončení.');

            return $this->redirectToOrder($contract);
        }

        $immediate = 'immediate' === $terminationType;

        if ($contract->isTerminated()) {
            $this->addFlash('error', 'Smlouva je již ukončena.');

            return $this->redirectToOrder($contract);
        }

        if (!$immediate && $contract->hasPendingTermination()) {
            $this->addFlash('error', 'Výpověď smlouvy již byla podána.');

            return $this->redirectToOrder($contract);
        }

        $reason = $request->request->getString('reason');
        if ('' === $reason) {
            $reason = null;
        }
        if (null !== $reason && mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        // Refund only accompanies an immediate termination (spec 092); with
        // notice the contract keeps running and billing for another month.
        $refundRequest = null;
        if ($immediate && $request->request->getBoolean('refund')) {
            try {
                $refundRequest = $this->parseRefundRequest($contract, $request, $reason, $user);
            } catch (RefundNotPossible $e) {
                // Validated BEFORE anything is dispatched: a bad refund input
                // must not leave a terminated contract with no refund.
                $this->addFlash('error', sprintf('Vrácení platby nelze provést: %s Smlouva nebyla ukončena.', $e->getMessage()));

                return $this->redirectToOrder($contract);
            }
        }

        $this->commandBus->dispatch(new AdminTerminateContractCommand(
            contract: $contract,
            immediate: $immediate,
            reason: $reason,
            // Only meaningful for immediate termination — with-notice keeps
            // billing until terminatesAt, where the overdue cron records debt.
            recordDebt: $immediate && $request->request->getBoolean('record_debt'),
        ));

        if (!$immediate) {
            $this->addFlash('success', sprintf(
                'Výpověď smlouvy byla podána. Smlouva bude ukončena k %s.',
                $contract->terminatesAt?->format('d.m.Y') ?? '',
            ));

            return $this->redirectToOrder($contract);
        }

        if (null === $refundRequest) {
            $this->addFlash('success', 'Smlouva byla okamžitě ukončena.');

            return $this->redirectToOrder($contract);
        }

        // The termination is committed; the refund runs in its own
        // transaction. A GoPay refund cannot be undone, so it must never share
        // a transaction that could roll the termination back.
        $this->refund($contract, $refundRequest);

        return $this->redirectToOrder($contract);
    }

    private function refund(Contract $contract, RefundContractPaymentCommand $command): void
    {
        try {
            $envelope = $this->commandBus->dispatch($command);
        } catch (\Throwable $e) {
            $cause = HandlerFailureUnwrap::unwrap($e);
            $failureReason = $cause instanceof PaymentRefundFailed || $cause instanceof RefundNotPossible
                ? $cause->getMessage()
                : sprintf('Neočekávaná chyba: %s', $cause->getMessage());

            $this->logger->error('Refund on admin termination failed', [
                'contract_id' => $contract->id->toRfc4122(),
                'payment_id' => $command->paymentId->toRfc4122(),
                'exception' => $cause,
            ]);

            // Its own transaction — the refund command rolled back
            // (MESSENGER.md §3); never audit-log here after a dispatch.
            $this->commandBus->dispatch(new RecordPaymentRefundFailureCommand(
                contractId: $contract->id,
                paymentId: $command->paymentId,
                amount: $cause instanceof PaymentRefundFailed ? $cause->amount : null,
                failureReason: $failureReason,
                reason: $command->reason,
            ));

            $this->addFlash('warning', sprintf(
                'Smlouva byla okamžitě ukončena, ale vrácení platby selhalo: %s Vraťte platbu ručně v administraci GoPay — aplikace ji pak sama dohledá a zapíše do historie.',
                $failureReason,
            ));

            return;
        }

        $refund = $envelope->last(HandledStamp::class)?->getResult();
        if (!$refund instanceof PaymentRefund) {
            $this->addFlash('success', 'Smlouva byla okamžitě ukončena a vrácení platby bylo odesláno do GoPay.');

            return;
        }

        $amount = number_format($refund->getAmountInCzk(), 0, ',', ' ');
        if ($refund->isSucceeded()) {
            $this->addFlash('success', sprintf(
                'Smlouva byla okamžitě ukončena a zákazníkovi bylo vráceno %s Kč na kartu.%s',
                $amount,
                $refund->issueCreditNote ? ' Dobropis se vystavuje a odejde zákazníkovi e-mailem.' : '',
            ));

            return;
        }

        $this->addFlash('success', sprintf(
            'Smlouva byla okamžitě ukončena. Vrácení %s Kč GoPay přijal ke zpracování — po potvrzení se zapíše do historie%s.',
            $amount,
            $refund->issueCreditNote ? ' a zákazníkovi odejde dobropis' : '',
        ));
    }

    private function parseRefundRequest(Contract $contract, Request $request, ?string $reason, User $admin): RefundContractPaymentCommand
    {
        $paymentId = $request->request->getString('refund_payment_id');
        if (!Uuid::isValid($paymentId)) {
            throw new RefundNotPossible('Chybí platba k vrácení.');
        }

        $refundable = $this->refundablePaymentResolver->resolve($contract);
        if (null === $refundable) {
            throw new RefundNotPossible('Smlouva nemá žádnou platbu kartou k vrácení.');
        }
        if (!$refundable->payment->id->equals(Uuid::fromString($paymentId))) {
            throw new RefundNotPossible('Platba k vrácení se mezitím změnila — načtěte stránku znovu.');
        }

        $mode = RefundMode::tryFrom($request->request->getString('refund_mode'));
        if (!in_array($mode, [RefundMode::FULL, RefundMode::PRORATED], true)) {
            throw new RefundNotPossible('Zvolte, zda vrátit celou platbu, nebo poměrnou část.');
        }

        $refundFrom = null;
        if (RefundMode::PRORATED === $mode) {
            $refundFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('refund_from')) ?: null;
            if (null === $refundFrom) {
                throw new RefundNotPossible('Zadejte platné datum, od kterého se má platba vrátit.');
            }
        }

        // Fails here (not after the termination) on an empty or past period.
        RefundCalculator::quote($refundable->payment->amount, $mode, $refundable->periodStart, $refundable->periodEnd, $refundFrom);

        return new RefundContractPaymentCommand(
            contractId: $contract->id,
            paymentId: $refundable->payment->id,
            mode: $mode,
            refundFrom: $refundFrom,
            issueCreditNote: $request->request->getBoolean('refund_credit_note') && $refundable->canIssueCreditNote(),
            reason: $reason,
            adminId: $admin->id,
        );
    }

    private function redirectToOrder(Contract $contract): Response
    {
        return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
    }
}
