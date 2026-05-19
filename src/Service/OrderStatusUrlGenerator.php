<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mints HMAC-signed URLs for the public order-status permalink and the
 * three document download endpoints reachable from it. Signatures cover
 * the full URL (path + query); query parameters that are part of the
 * route's contract MUST be added before signing — the consumer must not
 * append params (utm_source, etc.) to a signed URL.
 *
 * This generator is the only code path that signs these routes; Twig
 * should never call `path()` for them directly.
 */
final readonly class OrderStatusUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(Order $order): string
    {
        $url = $this->urlGenerator->generate(
            'public_order_status',
            ['id' => $order->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function generateContractDownload(Order $order, bool $forDownload = false): string
    {
        $url = $this->urlGenerator->generate(
            'public_order_contract_download',
            array_filter([
                'id' => $order->id->toRfc4122(),
                'download' => $forDownload ? 1 : null,
            ], static fn ($v) => null !== $v),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function generateVopDownload(Order $order, bool $forDownload = false): string
    {
        $url = $this->urlGenerator->generate(
            'public_order_vop_download',
            array_filter([
                'id' => $order->id->toRfc4122(),
                'download' => $forDownload ? 1 : null,
            ], static fn ($v) => null !== $v),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function generateInvoiceDownload(Order $order, Invoice $invoice, bool $forDownload = false): string
    {
        $url = $this->urlGenerator->generate(
            'public_order_invoice_download',
            array_filter([
                'id' => $order->id->toRfc4122(),
                'invoiceId' => $invoice->id->toRfc4122(),
                'download' => $forDownload ? 1 : null,
            ], static fn ($v) => null !== $v),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function generateMapDownload(Order $order, bool $forDownload = false): string
    {
        $url = $this->urlGenerator->generate(
            'public_order_map_download',
            array_filter([
                'id' => $order->id->toRfc4122(),
                'download' => $forDownload ? 1 : null,
            ], static fn ($v) => null !== $v),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }
}
