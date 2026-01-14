<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\ProcessPaymentNotificationCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhook/gopay', name: 'public_payment_notification', methods: ['GET', 'POST'])]
final class PaymentNotificationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // GoPay sends payment ID as 'id' parameter
        $paymentId = $request->query->getInt('id');

        if ($paymentId <= 0) {
            return new Response('Invalid payment ID', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new ProcessPaymentNotificationCommand(
                goPayPaymentId: $paymentId,
            ));

            return new Response('OK', Response::HTTP_OK);
        } catch (\Exception) {
            // Return 200 to prevent GoPay retries for invalid payments
            // Errors are logged internally
            return new Response('Processed', Response::HTTP_OK);
        }
    }
}
