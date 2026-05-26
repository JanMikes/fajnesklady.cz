<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CancelOrderCommand;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\OrderRepository;
use App\Service\GoPay\GoPayClient;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use App\Service\PriceCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/platba', name: 'public_order_payment')]
final class OrderPaymentController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly GoPayClient $goPayClient,
        private readonly PriceCalculator $priceCalculator,
        private readonly OrderStatusUrlGenerator $orderStatusUrlGenerator,
        private readonly QrPaymentGenerator $qrPaymentGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // Terms and signature must be present before payment
        if (!$order->hasAcceptedTerms() || !$order->hasSignature()) {
            $storage = $order->storage;
            $this->addFlash('error', 'Objednávka nebyla dokončena. Prosím vytvořte ji znovu.');

            return $this->redirectToRoute('public_order_create', [
                'placeId' => $storage->place->id,
                'storageTypeId' => $storage->storageType->id,
                'storageId' => $storage->id,
            ]);
        }

        // Check if order can be paid
        if (!$order->canBePaid()) {
            if (OrderStatus::COMPLETED === $order->status || OrderStatus::PAID === $order->status) {
                return new RedirectResponse($this->orderStatusUrlGenerator->generate($order));
            }

            $this->addFlash('error', 'Tuto objednávku již nelze zaplatit.');

            return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
        }

        // Handle cancel action
        if ($request->isMethod('POST')) {
            $action = $request->request->getString('action');

            if ('cancel' === $action) {
                if (!$order->canBeCancelled()) {
                    $this->addFlash('error', $order->cancellationBlockedReason() ?? 'Objednávku nelze zrušit.');

                    return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
                }

                try {
                    $this->commandBus->dispatch(new CancelOrderCommand($order));
                    $this->addFlash('info', 'Objednávka byla zrušena.');

                    return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
                } catch (\Exception $e) {
                    $this->logger->error('Order cancellation failed', [
                        'order_id' => $id,
                        'exception' => $e,
                    ]);
                    $this->addFlash('error', 'Při rušení objednávky došlo k chybě.');
                }
            }
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $paymentSchedule = $this->priceCalculator->buildPaymentSchedule($storage, $order->startDate, $order->endDate);

        $isBankTransfer = PaymentMethod::BANK_TRANSFER === $order->paymentMethod;

        return $this->render('public/order_payment.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'paymentSchedule' => $paymentSchedule,
            'goPayEmbedJs' => $isBankTransfer ? null : $this->goPayClient->getEmbedJsUrl(),
            'isBankTransfer' => $isBankTransfer,
            'bankAccount' => $isBankTransfer ? $this->qrPaymentGenerator->getBankAccountFormatted() : null,
            'qrCodeDataUri' => $isBankTransfer && null !== $order->variableSymbol
                ? $this->qrPaymentGenerator->generateDataUri($order->variableSymbol, $order->firstPaymentPrice)
                : null,
            'statusUrl' => $this->orderStatusUrlGenerator->generate($order),
        ]);
    }
}
