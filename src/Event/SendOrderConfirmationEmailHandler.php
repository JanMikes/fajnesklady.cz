<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\ContractDocumentGenerator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendOrderConfirmationEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private ContractDocumentGenerator $contractDocumentGenerator,
        private string $projectDir,
        private string $contractTemplatePath,
    ) {
    }

    public function __invoke(OrderPlaced $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $manageUrl = $this->urlGenerator->generate(
            'portal_user_order_detail',
            ['id' => $order->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Potvrzení objednávky - '.$place->name)
            ->htmlTemplate('email/order_confirmation.html.twig')
            ->context([
                'name' => $user->fullName,
                'orderNumber' => substr($order->id->toRfc4122(), 0, 8),
                'placeName' => $place->name,
                'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'startDate' => $order->startDate->format('d.m.Y'),
                'endDate' => $order->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
                'totalPrice' => number_format($order->getTotalPriceInCzk(), 2, ',', ' ').' Kč',
                'expiresAt' => $order->expiresAt->format('d.m.Y H:i'),
                'lockCode' => $storage->lockCode,
                'manageUrl' => $manageUrl,
            ]);

        // Attach the signed contract DOCX (the order is legally binding at this point).
        // Skipped only for orders without a signature (e.g. legacy admin migrations).
        if ($order->hasSignature()) {
            $contractBytes = $this->contractDocumentGenerator->renderBytesForOrder($order, $this->contractTemplatePath);
            $email->attach(
                $contractBytes,
                sprintf('smlouva-%s.docx', substr($order->id->toRfc4122(), 0, 8)),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            );
        }

        // Attach VOP
        $vopPath = $this->projectDir.'/public/documents/vop.pdf';
        if (file_exists($vopPath)) {
            $email->attachFromPath($vopPath, 'vop.pdf', 'application/pdf');
        }

        // Attach consumer notice
        $consumerNoticePath = $this->projectDir.'/public/documents/pouceni-spotrebitele.pdf';
        if (file_exists($consumerNoticePath)) {
            $email->attachFromPath($consumerNoticePath, 'pouceni-spotrebitele.pdf', 'application/pdf');
        }

        // Attach recurring payments terms (only for unlimited/recurring rentals)
        if (null === $order->endDate) {
            $recurringPaymentsPath = $this->projectDir.'/public/documents/podminky-opakovanych-plateb.pdf';
            if (file_exists($recurringPaymentsPath)) {
                $email->attachFromPath($recurringPaymentsPath, 'podminky-opakovanych-plateb.pdf', 'application/pdf');
            }
        }

        $this->mailer->send($email);
    }
}
