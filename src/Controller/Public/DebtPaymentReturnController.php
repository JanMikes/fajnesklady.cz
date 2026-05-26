<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\ProcessPaymentNotificationCommand;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\OrderStatusUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/platba/dluh/navrat', name: 'public_debt_payment_return')]
final class DebtPaymentReturnController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly OrderStatusUrlGenerator $orderStatusUrlGenerator,
        private readonly LoggerInterface $logger,
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

        if (null !== $order->debtGoPayPaymentId) {
            try {
                $this->commandBus->dispatch(new ProcessPaymentNotificationCommand(
                    goPayPaymentId: $order->debtGoPayPaymentId,
                ));
            } catch (\Throwable $rawException) {
                $this->logger->error('Debt payment status check failed on return', [
                    'order_id' => $id,
                    'gopay_payment_id' => $order->debtGoPayPaymentId,
                    'exception' => HandlerFailureUnwrap::unwrap($rawException),
                ]);
            }

            $order = $this->orderRepository->get($order->id);
        }

        if (OrderStatus::COMPLETED === $order->status) {
            $this->addFlash('success', 'Dluh byl uhrazen a objednávka dokončena.');

            return new RedirectResponse($this->orderStatusUrlGenerator->generate($order));
        }

        if (!$order->hasUnpaidDebt()) {
            if ($order->canBePaid()) {
                $this->addFlash('success', 'Dluh byl úspěšně uhrazen. Nyní pokračujte k platbě nájemného.');

                return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
            }

            return new RedirectResponse($this->orderStatusUrlGenerator->generate($order));
        }

        $this->addFlash('info', 'Platba se zpracovává. Vyčkejte prosím.');

        return $this->redirectToRoute('public_order_debt_payment', ['id' => $order->id]);
    }
}
