<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\RecordExternalPaymentCommand;
use App\Entity\Contract;
use App\Entity\Order;
use App\Form\ExternalPaymentFormData;
use App\Form\ExternalPaymentFormType;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Security\OrderVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/record-external-payment', name: 'admin_order_record_external_payment', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderRecordExternalPaymentController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly RecurringAmountCalculator $recurringAmountCalculator,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $contract = $this->contractRepository->findByOrder($order);
        $now = $this->clock->now();

        if (!$this->isEligible($order, $contract)) {
            $this->addFlash('error', 'Tuto objednávku nelze označit jako externě zaplacenou.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $defaultAmountInHaler = null !== $contract
            ? $this->recurringAmountCalculator->calculate($contract, $now)
            : $order->firstPaymentPrice;

        $formData = new ExternalPaymentFormData();
        $formData->amountInCzk = $defaultAmountInHaler / 100;

        $form = $this->createForm(ExternalPaymentFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $wholeCycle = ExternalPaymentFormData::COVERAGE_WHOLE_CYCLE === $formData->coverage;

            $this->commandBus->dispatch(new RecordExternalPaymentCommand(
                order: $order,
                wholeCycle: $wholeCycle,
                paidThroughDate: $wholeCycle ? null : $formData->paidThroughDate,
                amount: (int) round($formData->amountInCzk * 100),
                issueInvoice: $formData->issueInvoice,
            ));

            $message = 'Platba byla zaznamenána.';
            if ($formData->issueInvoice) {
                $message .= ' Faktura byla vystavena.';
            }
            $this->addFlash('success', $message);

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        return $this->render('admin/order/record_external_payment.html.twig', [
            'order' => $order,
            'contract' => $contract,
            'form' => $form->createView(),
        ]);
    }

    /**
     * The customer must have signed the contract (never activate / advance a
     * rental nobody agreed to), and there must be something to pay: a running
     * contract with an open cycle, or an order still awaiting its first payment.
     */
    private function isEligible(Order $order, ?Contract $contract): bool
    {
        if (!$order->hasSignature()) {
            return false;
        }

        if (null !== $contract && !$contract->isTerminated() && null !== $contract->nextBillingDate) {
            return true;
        }

        return $order->canBePaid();
    }
}
