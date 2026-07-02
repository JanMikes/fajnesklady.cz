<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\InitiateCardSetupCommand;
use App\Entity\User;
use App\Repository\ContractRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Value\GoPayPayment;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * JSON endpoint the card-setup page POSTs to (spec 077). Requires the
 * dedicated recurring consent to have been ticked client-side; the handler
 * writes the consent audit record.
 */
#[Route('/smlouva/{contractId}/prodlouzit/karta/iniciovat', name: 'public_contract_card_setup_initiate', requirements: ['contractId' => '[0-9a-f-]{36}'], methods: ['POST'])]
final class ContractCardSetupInitiateController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $contractId): Response
    {
        $contract = $this->contractRepository->get(Uuid::fromString($contractId));

        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User && $contract->user->id->equals($currentUser->id);
        if (!$isOwner && !$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        /** @var array{consent?: bool} $payload */
        $payload = $request->toArray();
        if (true !== ($payload['consent'] ?? false)) {
            return new JsonResponse(['error' => 'Potvrďte prosím souhlas s opakovanou platbou.'], Response::HTTP_BAD_REQUEST);
        }

        $returnUrl = $this->urlGenerator->generate(
            'public_contract_card_setup_return',
            ['contractId' => $contract->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $notificationUrl = $this->urlGenerator->generate(
            'public_payment_notification',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $envelope = $this->commandBus->dispatch(new InitiateCardSetupCommand(
                contract: $contract,
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
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            if ($exception instanceof \DomainException) {
                return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            $this->logger->error('Card setup payment initiation failed', [
                'contract_id' => $contractId,
                'exception' => $exception,
            ]);

            return new JsonResponse(
                ['error' => 'Chyba při vytváření platby. Zkuste to prosím znovu.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
