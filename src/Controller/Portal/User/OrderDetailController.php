<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\User;
use App\Enum\BillingMode;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\HandoverProtocolRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\OrderRepository;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\ContractService;
use App\Service\Fine\FinePaymentUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use App\Service\PriceCalculator;
use App\Service\Security\ContractVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/objednavky/{id}', name: 'portal_user_order_detail')]
#[IsGranted('ROLE_USER')]
final class OrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly ContractRepository $contractRepository,
        private readonly FineRepository $fineRepository,
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private readonly ContractService $contractService,
        private readonly FinePaymentUrlGenerator $finePaymentUrlGenerator,
        private readonly PriceCalculator $priceCalculator,
        private readonly QrPaymentGenerator $qrPaymentGenerator,
        private readonly ClockInterface $clock,
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

        /** @var User $user */
        $user = $this->getUser();

        if (!$order->user->id->equals($user->id)) {
            throw new AccessDeniedHttpException('Nemáte přístup k této objednávce.');
        }

        $contract = $this->contractRepository->findByOrder($order);
        $invoices = $this->invoiceRepository->findAllByOrder($order);
        $handoverProtocol = null !== $contract
            ? $this->handoverProtocolRepository->findByContract($contract)
            : null;
        $now = $this->clock->now();

        $daysRemaining = null;
        $canTerminate = false;

        if (null !== $contract) {
            $daysRemaining = $this->contractService->getDaysRemaining($contract, $now);
            $canTerminate = $this->isGranted(ContractVoter::TERMINATE, $contract);
        }

        $paymentSchedule = $this->priceCalculator->buildScheduleFromOrder($order);

        $manualNowVariableSymbol = null;
        $manualNowBankAccount = null;
        $manualNowAmount = null;
        $manualNowPeriodStart = null;
        $nextManualDate = null;
        if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode) {
            $pending = $this->manualPaymentRequestRepository->findPendingForCurrentCycle($contract, $now);
            // Spec 076: manual cycles are paid by bank transfer — surface VS + QR.
            if (null !== $pending && null !== $order->variableSymbol) {
                $manualNowVariableSymbol = $order->variableSymbol;
                $manualNowBankAccount = $this->qrPaymentGenerator->getBankAccountFormatted();
                $manualNowAmount = $pending->amount;
                $manualNowPeriodStart = $pending->periodStart;
            }
            if (null === $pending && null !== $contract->nextBillingDate) {
                $schedule = ManualBillingReminderSchedule::fromOrder($contract->order);
                $nextManualDate = $contract->nextBillingDate
                    ->setTime(0, 0, 0)
                    ->modify(sprintf('%d days', $schedule->offsetInitial));
            }
        }

        $fines = null !== $contract
            ? $this->fineRepository->findByContract($contract)
            : [];

        $finePaymentUrls = [];
        foreach ($fines as $fine) {
            if ($fine->isPayable()) {
                $finePaymentUrls[$fine->id->toRfc4122()] = $this->finePaymentUrlGenerator->generatePaymentUrl($fine);
            }
        }

        $mismatchTransactions = $this->bankTransactionRepository->findAmountMismatchByOrder($order);

        return $this->render('portal/user/order/detail.html.twig', [
            'order' => $order,
            'contract' => $contract,
            'invoices' => $invoices,
            'storage' => $order->storage,
            'storageType' => $order->storage->storageType,
            'place' => $order->storage->getPlace(),
            'daysRemaining' => $daysRemaining,
            'canTerminate' => $canTerminate,
            'paymentSchedule' => $paymentSchedule,
            'now' => $now,
            'manualNowVariableSymbol' => $manualNowVariableSymbol,
            'manualNowBankAccount' => $manualNowBankAccount,
            'manualNowAmount' => $manualNowAmount,
            'manualNowPeriodStart' => $manualNowPeriodStart,
            'nextManualDate' => $nextManualDate,
            'handoverProtocol' => $handoverProtocol,
            'fines' => $fines,
            'finePaymentUrls' => $finePaymentUrls,
            'mismatchTransactions' => $mismatchTransactions,
        ]);
    }
}
