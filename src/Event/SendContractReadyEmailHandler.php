<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\DocumentPdfConverter;
use App\Service\RecurringPaymentCancelUrlGenerator;
use App\Service\StorageMapImageGenerator;
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
        private StorageMapImageGenerator $mapImageGenerator,
        private DocumentPdfConverter $pdfConverter,
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

        // Attach the signed contract — prefer PDF, fall back to DOCX if conversion fails.
        if ($contract->hasDocument() && null !== $contract->documentPath && file_exists($contract->documentPath)) {
            $contractNumber = $this->formatContractNumber($contract);
            $pdfPath = $this->pdfConverter->convertToPdf($contract->documentPath);

            if (null !== $pdfPath) {
                $email->attachFromPath(
                    $pdfPath,
                    sprintf('smlouva_%s.pdf', $contractNumber),
                    'application/pdf',
                );
            } else {
                $email->attachFromPath(
                    $contract->documentPath,
                    sprintf('smlouva_%s.docx', $contractNumber),
                );
            }
        }

        // Attach the place map with the rented storage highlighted.
        // Triggered on OrderCompleted (first payment), so recurring monthly charges
        // (which do not fire OrderCompleted) won't re-attach this every month.
        $mapImageData = $this->mapImageGenerator->generate($storage);
        if (null !== $mapImageData) {
            $email->attach($mapImageData, 'mapa-skladu.png', 'image/png');
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
