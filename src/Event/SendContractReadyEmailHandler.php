<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\RecurringPaymentCancelUrlGenerator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendContractReadyEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private string $uploadsDirectory,
    ) {
    }

    public function __invoke(OrderCompleted $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $portalUrl = $this->urlGenerator->generate(
            'portal_dashboard',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $isRecurring = $contract->hasActiveRecurringPayment();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Smlouva připravena - '.$place->name)
            ->htmlTemplate('email/contract_ready.html.twig')
            ->context([
                'name' => $user->fullName,
                'contractNumber' => $this->formatContractNumber($contract),
                'placeName' => $place->name,
                'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'startDate' => $contract->startDate->format('d.m.Y'),
                'endDate' => $contract->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
                'portalUrl' => $portalUrl,
                'isRecurring' => $isRecurring,
                'monthlyAmount' => $isRecurring ? number_format($contract->order->getTotalPriceInCzk(), 2, ',', ' ').' Kč' : null,
                'nextBillingDate' => $isRecurring && null !== $contract->nextBillingDate ? $contract->nextBillingDate->format('d.m.Y') : null,
                'cancelUrl' => $isRecurring ? $this->cancelUrlGenerator->generate($contract) : null,
            ]);

        // Attach the contract document if available
        if ($contract->hasDocument() && null !== $contract->documentPath && file_exists($contract->documentPath)) {
            $email->attachFromPath(
                $contract->documentPath,
                sprintf('smlouva_%s.docx', $this->formatContractNumber($contract)),
            );
        }

        // Attach operating rules document if available for this place
        if ($place->hasOperatingRules() && null !== $place->operatingRulesPath) {
            $operatingRulesFullPath = $this->uploadsDirectory.'/'.$place->operatingRulesPath;
            if (file_exists($operatingRulesFullPath)) {
                $extension = pathinfo($operatingRulesFullPath, PATHINFO_EXTENSION);
                $email->attachFromPath(
                    $operatingRulesFullPath,
                    'provozni_rad.'.$extension,
                );
            }
        }

        $this->mailer->send($email);
    }

    private function formatContractNumber(\App\Entity\Contract $contract): string
    {
        $date = $contract->createdAt;
        $uuidShort = substr($contract->id->toRfc4122(), 0, 8);

        return sprintf(
            '%s-%s-%s',
            $date->format('Y'),
            $date->format('md'),
            strtoupper($uuidShort),
        );
    }
}
