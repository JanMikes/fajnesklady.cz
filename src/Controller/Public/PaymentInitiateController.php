<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\InitiatePaymentCommand;
use App\Repository\OrderRepository;
use App\Service\GoPay\GoPayException;
use App\Value\GoPayPayment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/platba/iniciovat', name: 'public_payment_initiate', methods: ['POST'])]
final class PaymentInitiateController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly UrlGeneratorInterface $urlGenerator,
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

        if (!$order->canBePaid()) {
            return new JsonResponse(['error' => 'Tuto objednávku nelze zaplatit.'], Response::HTTP_BAD_REQUEST);
        }

        $returnUrl = $this->urlGenerator->generate(
            'public_payment_return',
            ['id' => $id],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $notificationUrl = $this->urlGenerator->generate(
            'public_payment_notification',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $envelope = $this->commandBus->dispatch(new InitiatePaymentCommand(
                order: $order,
                returnUrl: $returnUrl,
                notificationUrl: $notificationUrl,
            ));

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);

            /** @var GoPayPayment $payment */
            $payment = $handledStamp->getResult();

            return new JsonResponse([
                'paymentId' => $payment->id,
                'gwUrl' => $payment->gwUrl,
            ]);
        } catch (GoPayException $e) {
            return new JsonResponse(
                ['error' => 'Chyba při vytváření platby. Zkuste to prosím znovu.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
