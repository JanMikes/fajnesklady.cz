<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\StorageMapImageGenerator;
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
        private StorageMapImageGenerator $mapImageGenerator,
        private string $projectDir,
    ) {
    }

    public function __invoke(OrderCreated $event): void
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

        $mapImageData = $this->mapImageGenerator->generate($storage);

        if (null !== $mapImageData) {
            $email->attach($mapImageData, 'mapa-skladu.png', 'image/png');
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

        // Attach operating rules (if place has one)
        if (null !== $place->operatingRulesPath) {
            $operatingRulesPath = $this->projectDir.'/public/uploads/'.$place->operatingRulesPath;
            if (file_exists($operatingRulesPath)) {
                $extension = pathinfo($place->operatingRulesPath, \PATHINFO_EXTENSION);
                $mimeType = 'pdf' === strtolower($extension) ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                $email->attachFromPath($operatingRulesPath, 'provozni-rad.'.$extension, $mimeType);
            }
        }

        $this->mailer->send($email);
    }
}
