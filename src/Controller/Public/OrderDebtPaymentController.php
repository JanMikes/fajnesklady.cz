<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\OrderRepository;
use App\Service\GoPay\GoPayClient;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/platba/dluh', name: 'public_order_debt_payment')]
final class OrderDebtPaymentController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly GoPayClient $goPayClient,
        private readonly OrderStatusUrlGenerator $orderStatusUrlGenerator,
        private readonly QrPaymentGenerator $qrPaymentGenerator,
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

        if (!$order->hasAcceptedTerms() || !$order->hasSignature()) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        if (!$order->hasUnpaidDebt()) {
            if (OrderStatus::COMPLETED === $order->status || OrderStatus::PAID === $order->status) {
                return new RedirectResponse($this->orderStatusUrlGenerator->generate($order));
            }

            if ($order->canBePaid()) {
                return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
            }

            return new RedirectResponse($this->orderStatusUrlGenerator->generate($order));
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $isBankTransfer = PaymentMethod::BANK_TRANSFER === $order->paymentMethod;

        return $this->render('public/order_debt_payment.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'debtAmountCzk' => $order->getDebtAmountInCzk(),
            'goPayEmbedJs' => $isBankTransfer ? null : $this->goPayClient->getEmbedJsUrl(),
            'isBankTransfer' => $isBankTransfer,
            'bankAccount' => $isBankTransfer ? $this->qrPaymentGenerator->getBankAccountFormatted() : null,
            'qrCodeDataUri' => $isBankTransfer && null !== $order->variableSymbol && null !== $order->onboardingDebtInHaler
                ? $this->qrPaymentGenerator->generateDataUri($order->variableSymbol, $order->onboardingDebtInHaler)
                : null,
            'statusUrl' => $this->orderStatusUrlGenerator->generate($order),
        ]);
    }
}
