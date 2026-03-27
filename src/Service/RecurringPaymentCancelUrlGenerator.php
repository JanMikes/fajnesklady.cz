<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class RecurringPaymentCancelUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(Contract $contract): string
    {
        $url = $this->urlGenerator->generate(
            'public_cancel_recurring_payment',
            ['contractId' => $contract->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }
}
