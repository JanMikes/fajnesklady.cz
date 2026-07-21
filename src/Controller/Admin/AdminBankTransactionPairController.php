<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\PairBankTransactionCommand;
use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\Payment\PaymentAllocator;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Manual pairing of an incoming transfer to an order (spec 091 requirement 5).
 *
 * A page rather than a modal: the admin picks the order by search, then sees the
 * exact allocation plan that will be executed before committing to it. There is
 * deliberately no unpair — pairing dispatches payments, invoices and e-mails
 * that no button could reverse.
 */
#[Route('/portal/admin/bankovni-platby/{id}/sparovat', name: 'admin_bank_transaction_pair', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminBankTransactionPairController extends AbstractController
{
    private const int PICKER_LIMIT = 20;

    public function __construct(
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly PaymentAllocator $paymentAllocator,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $id, #[CurrentUser] User $admin): Response
    {
        $transaction = $this->bankTransactionRepository->find(Uuid::fromString($id));
        if (null === $transaction) {
            throw new NotFoundHttpException('Transakce nenalezena.');
        }

        $filter = $request->isMethod('POST')
            ? $request->request->getString('filter')
            : $request->query->getString('filter');
        $redirectParams = '' !== $filter && 'all' !== $filter ? ['filter' => $filter] : [];

        if (!$transaction->isUnmatched() && !$transaction->isAmountMismatch()) {
            $this->addFlash('error', 'Spárovat lze pouze nespárované nebo částečně uhrazené transakce.');

            return $this->redirectToRoute('admin_bank_payments', $redirectParams);
        }

        if ($request->isMethod('POST')) {
            return $this->pair($request, $transaction, $admin, $filter, $redirectParams);
        }

        $orderId = trim($request->query->getString('order'));
        if ('' !== $orderId) {
            return $this->renderConfirmation($transaction, $orderId, $filter);
        }

        $search = trim($request->query->getString('q'));

        return $this->render('admin/bank_payments/pair.html.twig', [
            'transaction' => $transaction,
            'filter' => $filter,
            'search' => $search,
            'orders' => $this->orderRepository->findAdminFiltered(
                $this->clock->now(),
                null,
                1,
                self::PICKER_LIMIT,
                '' === $search ? null : $search,
            ),
            'order' => null,
            'contract' => null,
            'plan' => null,
            'blockedForCard' => false,
        ]);
    }

    /**
     * @param array<string, string> $redirectParams
     */
    private function pair(Request $request, BankTransaction $transaction, User $admin, string $filter, array $redirectParams): Response
    {
        $order = $this->findOrder(trim($request->request->getString('order')));
        if (null === $order) {
            $this->addFlash('error', 'Objednávka nenalezena.');

            return $this->redirectToRoute('admin_bank_transaction_pair', ['id' => $transaction->id->toRfc4122(), 'filter' => $filter]);
        }

        // Mirror the handler's plan-based guard rather than blocking the whole
        // order: a card order may still owe an onboarding debt, and settling that
        // by wire is exactly what spec 089 allows. Only the first-payment step is
        // ever refused, and the allocator does that itself.
        $contract = $this->contractRepository->findByOrder($order);
        $plan = $this->paymentAllocator->plan($order, $contract, $transaction->amount, $this->clock->now());

        if ([] === $plan->obligationSteps() && 0 === $plan->creditAdded()) {
            $this->addFlash('error', $this->paymentAllocator->isFirstPaymentBlockedForCard($order, $contract)
                ? 'První platbu karetní objednávky nelze uhradit převodem. Vraťte prosím částku zákazníkovi a nechte ho zaplatit kartou.'
                : 'Tato objednávka nemá žádný otevřený závazek, který by platba mohla uhradit.');

            return $this->redirectToRoute('admin_bank_payments', $redirectParams);
        }

        // Cap to the note's audit payload — the textarea maxlength is client-side only.
        $note = mb_substr(trim($request->request->getString('note')), 0, 500);

        $this->commandBus->dispatch(new PairBankTransactionCommand(
            transactionId: $transaction->id,
            orderId: $order->id,
            adminId: $admin->id,
            rememberSenderAccount: $request->request->getBoolean('rememberSenderAccount'),
            note: '' === $note ? null : $note,
        ));

        $this->addFlash('success', 'Transakce byla spárována s objednávkou.');

        return $this->redirectToRoute('admin_bank_payments', $redirectParams);
    }

    private function renderConfirmation(BankTransaction $transaction, string $orderId, string $filter): Response
    {
        $order = $this->findOrder($orderId);
        if (null === $order) {
            $this->addFlash('error', 'Objednávka nenalezena.');

            return $this->redirectToRoute('admin_bank_transaction_pair', ['id' => $transaction->id->toRfc4122(), 'filter' => $filter]);
        }

        $contract = $this->contractRepository->findByOrder($order);

        // The plan is pure, so what is rendered here is exactly what the handler
        // will execute on submit.
        $plan = $this->paymentAllocator->plan($order, $contract, $transaction->amount, $this->clock->now());

        // Nothing to settle. Either the allocator refused a card order's first
        // payment (spec 091 D1) or the order simply owes nothing — say which.
        $nothingToSettle = [] === $plan->obligationSteps() && 0 === $plan->creditAdded();

        return $this->render('admin/bank_payments/pair.html.twig', [
            'transaction' => $transaction,
            'filter' => $filter,
            'search' => '',
            'orders' => [],
            'order' => $order,
            'contract' => $contract,
            'plan' => $nothingToSettle ? null : $plan,
            'blockedForCard' => $nothingToSettle
                && $this->paymentAllocator->isFirstPaymentBlockedForCard($order, $contract),
            'nothingToSettle' => $nothingToSettle,
        ]);
    }

    private function findOrder(string $orderId): ?Order
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }

        return $this->orderRepository->find(Uuid::fromString($orderId));
    }
}
