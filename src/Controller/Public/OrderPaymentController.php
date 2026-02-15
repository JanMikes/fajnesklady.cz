<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CancelOrderCommand;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\GoPay\GoPayClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        // Terms must be accepted before payment
        if (!$order->hasAcceptedTerms()) {
            return $this->redirectToRoute('public_order_accept', ['id' => $order->id]);
        }

        // Check if order can be paid
        if (!$order->canBePaid()) {
            if (OrderStatus::COMPLETED === $order->status) {
                return $this->redirectToRoute('public_order_complete', ['id' => $order->id]);
            }

            if (OrderStatus::PAID === $order->status) {
                return $this->redirectToRoute('public_order_complete', ['id' => $order->id]);
            }

            $this->addFlash('error', 'Tuto objednávku již nelze zaplatit.');

            return $this->redirectToRoute('app_home');
        }

        // Handle cancel action
        if ($request->isMethod('POST')) {
            $action = $request->request->getString('action');

            if ('cancel' === $action) {
                try {
                    $this->commandBus->dispatch(new CancelOrderCommand($order));
                    $this->addFlash('info', 'Objednávka byla zrušena.');

                    return $this->redirectToRoute('app_home');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Při rušení objednávky došlo k chybě.');
                }
            }
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        return $this->render('public/order_payment.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'goPayEmbedJs' => $this->goPayClient->getEmbedJsUrl(),
        ]);
    }
}
