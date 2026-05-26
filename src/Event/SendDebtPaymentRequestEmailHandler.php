<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\PaymentMethod;
use App\Repository\OrderRepository;
use App\Service\Payment\QrPaymentGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendDebtPaymentRequestEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private PlaceAddressFormatter $addressFormatter,
        private QrPaymentGenerator $qrPaymentGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OnboardingDebtPaymentRequested $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $place = $order->storage->getPlace();

        $debtAmountCzk = $order->getDebtAmountInCzk();
        if (null === $debtAmountCzk || $debtAmountCzk <= 0) {
            return;
        }

        $debtPaymentUrl = $this->urlGenerator->generate(
            'public_order_debt_payment',
            ['id' => $order->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $isBankTransfer = PaymentMethod::BANK_TRANSFER === $order->paymentMethod;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject(sprintf('Dluh z předchozí smlouvy — %s Kč', number_format($debtAmountCzk, 0, ',', ' ')))
            ->htmlTemplate('email/debt_payment_request.html.twig')
            ->context([
                'name' => $user->fullName,
                'debtAmountCzk' => number_format($debtAmountCzk, 0, ',', ' '),
                'debtPaymentUrl' => $debtPaymentUrl,
                'placeName' => $place->name,
                'placeAddress' => $this->addressFormatter->format($place),
                'isBankTransfer' => $isBankTransfer,
                'bankAccount' => $isBankTransfer ? $this->qrPaymentGenerator->getBankAccountFormatted() : null,
                'variableSymbol' => $order->variableSymbol,
                'qrCodeDataUri' => $isBankTransfer && null !== $order->variableSymbol && null !== $order->onboardingDebtInHaler
                    ? $this->qrPaymentGenerator->generateDataUri($order->variableSymbol, $order->onboardingDebtInHaler)
                    : null,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send debt payment request email', [
                'order_id' => $order->id->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
