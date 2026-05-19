<?php

declare(strict_types=1);

namespace App\Service\Handover;

use App\Entity\HandoverProtocol;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mints HMAC-signed URLs for the public tenant-side handover protocol page.
 * The signature is the authorization — anyone holding the link can view the
 * protocol and fill the tenant section. Mirrors OrderStatusUrlGenerator.
 */
final readonly class HandoverUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generateTenantView(HandoverProtocol $protocol): string
    {
        $url = $this->urlGenerator->generate(
            'public_handover_view',
            ['id' => $protocol->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }
}
