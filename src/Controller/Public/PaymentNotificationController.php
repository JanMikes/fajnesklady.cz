<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\ProcessPaymentNotificationCommand;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhook/gopay', name: 'public_payment_notification', methods: ['GET', 'POST'])]
final class PaymentNotificationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'limiter.gopay_webhook')]
        private readonly RateLimiterFactoryInterface $gopayWebhookLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Per-IP rate limit. Rate-limit rejection returns 429 (genuinely transient,
        // GoPay retry will back off). The 200-on-processing-error rule below applies
        // only to handler exceptions, not to rate-limit rejections.
        $limiter = $this->gopayWebhookLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $this->logger->warning('GoPay webhook rate-limited', [
                'ip' => $request->getClientIp(),
                'retryAfter' => $limit->getRetryAfter()->format(\DateTimeInterface::ATOM),
            ]);

            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        // GoPay sends payment ID as 'id' parameter
        $paymentId = $request->query->getString('id');

        if ('' === $paymentId) {
            return new Response('Invalid payment ID', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new ProcessPaymentNotificationCommand(
                goPayPaymentId: $paymentId,
            ));

            return new Response('OK', Response::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('GoPay payment notification processing failed', [
                'gopay_payment_id' => $paymentId,
                'exception' => $e,
            ]);

            // Return 200 to prevent GoPay retries for invalid payments
            return new Response('Processed', Response::HTTP_OK);
        }
    }
}
