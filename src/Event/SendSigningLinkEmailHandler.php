<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\Order\SigningEmailContent;
use App\Service\Place\PlaceAddressFormatter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendSigningLinkEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private PlaceAddressFormatter $addressFormatter,
    ) {
    }

    public function __invoke(AdminOnboardingInitiated $event): void
    {
        if (null === $event->signingToken) {
            return;
        }

        $user = $this->userRepository->get($event->userId);
        $order = $this->orderRepository->get($event->orderId);
        $place = $order->storage->getPlace();
        $storageType = $order->storage->storageType;

        $signingUrl = $this->urlGenerator->generate(
            'public_customer_signing',
            ['token' => $event->signingToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $content = SigningEmailContent::fromOrder($order);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($event->customerEmail, $user->fullName))
            ->subject($content->subject)
            ->htmlTemplate('email/signing_link.html.twig')
            ->context([
                'name' => $user->fullName,
                'signingUrl' => $signingUrl,
                'content' => $content,
                'order' => $order,
                'storage' => $order->storage,
                'place' => $place,
                'storageType' => $storageType,
                'placeAddress' => $this->addressFormatter->format($place),
                'placeNavigationUrl' => $this->addressFormatter->navigationUrl($place),
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        $this->mailer->send($email);
    }
}
