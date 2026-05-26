<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\InitiateFinePaymentCommand;
use App\Repository\FineRepository;
use App\Service\Fine\FinePaymentUrlGenerator;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Value\GoPayPayment;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/pokuta/{id}/platba/iniciovat', name: 'public_fine_payment_initiate', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
final class FinePaymentInitiateController extends AbstractController
{
    public function __construct(
        private readonly FineRepository $fineRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly FinePaymentUrlGenerator $paymentUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        $fine = $this->fineRepository->findById(Uuid::fromString($id));
        if (null === $fine || !$fine->isPayable()) {
            return new JsonResponse(['error' => 'Pokuta nenalezena nebo již byla zaplacena.'], Response::HTTP_NOT_FOUND);
        }

        $returnUrl = $this->paymentUrlGenerator->generateReturnUrl($fine);
        $notificationUrl = $this->urlGenerator->generate(
            'public_payment_notification',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $envelope = $this->commandBus->dispatch(new InitiateFinePaymentCommand(
                fineId: $fine->id,
                returnUrl: $returnUrl,
                notificationUrl: $notificationUrl,
            ));

            /** @var GoPayPayment $payment */
            $payment = $envelope->last(HandledStamp::class)?->getResult();

            return new JsonResponse(['gwUrl' => $payment->gwUrl]);
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            $this->logger->error('Failed to initiate fine payment', [
                'fine_id' => $fine->id->toRfc4122(),
                'exception' => $exception,
            ]);

            return new JsonResponse(
                ['error' => 'Nepodařilo se vytvořit platbu. Zkuste to prosím znovu.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
