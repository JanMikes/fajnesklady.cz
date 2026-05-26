<?php

declare(strict_types=1);

namespace App\Service\Fine;

use App\Entity\Fine;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class FinePaymentUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generatePaymentUrl(Fine $fine): string
    {
        $url = $this->urlGenerator->generate(
            'public_fine_payment',
            ['id' => $fine->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function generateReturnUrl(Fine $fine): string
    {
        $url = $this->urlGenerator->generate(
            'public_fine_payment_return',
            ['id' => $fine->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }
}
