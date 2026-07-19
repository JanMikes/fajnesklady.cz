<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractPriceChangeRepository;
use App\Repository\ContractProlongationRepository;
use App\Repository\ContractRepository;
use App\Repository\EmailLogRepository;
use App\Repository\FineRepository;
use App\Repository\HandoverProtocolRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\ContractService;
use App\Service\Order\AdminOrderStageFactory;
use App\Service\Order\OrderPaymentOverviewFactory;
use App\Service\Overdue\OverdueChecker;
use App\Service\PriceCalculator;
use App\Service\Security\OrderVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}', name: 'admin_order_detail', requirements: ['id' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly ContractPriceChangeRepository $priceChangeRepository,
        private readonly ContractProlongationRepository $prolongationRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly FineRepository $fineRepository,
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly ContractService $contractService,
        private readonly PriceCalculator $priceCalculator,
        private readonly OverdueChecker $overdueChecker,
        private readonly OrderPaymentOverviewFactory $paymentOverviewFactory,
        private readonly AdminOrderStageFactory $stageFactory,
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
        $invoices = $this->invoiceRepository->findAllByOrder($order);
        $handoverProtocol = null !== $contract
            ? $this->handoverProtocolRepository->findByContract($contract)
            : null;
        $now = $this->clock->now();

        $daysRemaining = null;
        $canTerminate = false;
        $potentialTerminationDebtInHaler = 0;

        if (null !== $contract) {
            $daysRemaining = $this->contractService->getDaysRemaining($contract, $now);
            $canTerminate = $this->isGranted('CONTRACT_TERMINATE', $contract);
            if (!$contract->isTerminated()) {
                $potentialTerminationDebtInHaler = $this->contractService->calculateOutstandingDebt($contract, $now);
            }
        }

        $paymentSchedule = $this->priceCalculator->buildScheduleFromOrder($order);
        $totalPaid = $this->paymentRepository->sumPaidForOrder($order, $contract);

        $isUserOverdue = [] !== $this->overdueChecker->filterOverdueUserIds(
            $now,
            [$order->user->id],
        );

        $priceChanges = null !== $contract
            ? $this->priceChangeRepository->findByContractOrderedByDate($contract)
            : [];

        $prolongations = null !== $contract
            ? $this->prolongationRepository->findByContractOrderedByDate($contract)
            : [];

        $fines = null !== $contract
            ? $this->fineRepository->findByContract($contract)
            : [];
        $fineInvoices = $this->invoiceRepository->findByFines($fines);

        $emailLogs = $this->emailLogRepository->findByOrderId($order->id);
        $auditLogs = $this->auditLogRepository->findForOrderTimeline($order->id);

        $pendingManualPayment = null;
        if (null !== $contract && $contract->usesManualBillingTrack()) {
            $pendingManualPayment = $this->manualPaymentRequestRepository->findPendingForCurrentCycle($contract, $now);
        }

        $mismatchTransactions = $this->bankTransactionRepository->findAmountMismatchByOrder($order);
        $bankTransferReceivedTotal = $this->bankTransactionRepository->sumReceivedByOrder($order);

        // The overdue view of THIS contract (the user-level flag above also
        // fires for the customer's other rentals).
        $orderOverdueView = null;
        if (null !== $contract) {
            foreach ($this->overdueChecker->findOverdueViewsForUser($now, $order->user->id) as $view) {
                if ($view->contract->id->equals($contract->id)) {
                    $orderOverdueView = $view;

                    break;
                }
            }
        }

        $stage = $this->stageFactory->build(
            order: $order,
            contract: $contract,
            overdueView: $orderOverdueView,
            pendingManualPayment: $pendingManualPayment,
            amountMismatchCount: count($mismatchTransactions),
            now: $now,
        );

        $paymentOverview = $this->paymentOverviewFactory->build(
            order: $order,
            contract: $contract,
            payments: $this->paymentRepository->findAllForOrder($order, $contract),
            requests: null !== $contract ? $this->manualPaymentRequestRepository->findAllByContract($contract) : [],
            now: $now,
        );

        return $this->render('admin/order/detail.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'contract' => $contract,
            'invoices' => $invoices,
            'daysRemaining' => $daysRemaining,
            'canTerminate' => $canTerminate,
            'potentialTerminationDebtInHaler' => $potentialTerminationDebtInHaler,
            'paymentSchedule' => $paymentSchedule,
            'totalPaid' => $totalPaid,
            'isUserOverdue' => $isUserOverdue,
            'priceChanges' => $priceChanges,
            'prolongations' => $prolongations,
            'now' => $now,
            'handoverProtocol' => $handoverProtocol,
            'fines' => $fines,
            'fineInvoices' => $fineInvoices,
            'emailLogs' => $emailLogs,
            'auditLogs' => $auditLogs,
            'hasPendingManualPayment' => null !== $pendingManualPayment,
            'mismatchTransactions' => $mismatchTransactions,
            'bankTransferReceivedTotal' => $bankTransferReceivedTotal,
            'stage' => $stage,
            'paymentOverview' => $paymentOverview,
        ]);
    }
}
