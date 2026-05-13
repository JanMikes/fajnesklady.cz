<?php

declare(strict_types=1);

namespace App\Service\Vop;

use App\Entity\Order;
use App\Entity\Place;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renders the per-order VOP DOCX from the operator-maintained template,
 * substituting ${PRICELIST_URL} and ${OPERATING_RULES_URL} with absolute
 * URLs computed against the order's place. The DOCX is persisted under
 * var/vop/ so the exact version the customer signed stays recoverable.
 */
readonly class VopDocumentGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UrlHelper $urlHelper,
        private string $vopDocumentsDirectory,
    ) {
    }

    public function generate(Order $order, string $templatePath): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(sprintf('VOP template not found: %s', $templatePath));
        }

        $place = $order->storage->getPlace();
        $placeId = $place->id->toRfc4122();

        $processor = new TemplateProcessor($templatePath);
        $processor->setValue('PRICELIST_URL', $this->urlGenerator->generate(
            'public_place_pricelist',
            ['id' => $placeId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
        $processor->setValue('OPERATING_RULES_URL', $this->resolveOperatingRulesUrl($place));

        if (!is_dir($this->vopDocumentsDirectory)) {
            mkdir($this->vopDocumentsDirectory, 0755, true);
        }

        $outputPath = $this->pathFor($order);
        $processor->saveAs($outputPath);

        return $outputPath;
    }

    public function pathFor(Order $order): string
    {
        return sprintf('%s/vop_%s.docx', $this->vopDocumentsDirectory, $order->id->toRfc4122());
    }

    private function resolveOperatingRulesUrl(Place $place): string
    {
        if (null !== $place->operatingRulesPath && '' !== $place->operatingRulesPath) {
            return $this->urlHelper->getAbsoluteUrl('/uploads/'.$place->operatingRulesPath);
        }

        // Customers landing on the place detail page is the safe fallback —
        // never let the placeholder resolve to a 404 link.
        return $this->urlGenerator->generate(
            'public_place_detail',
            ['id' => $place->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
