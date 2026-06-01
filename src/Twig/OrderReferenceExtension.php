<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Order\OrderReferenceFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OrderReferenceExtension extends AbstractExtension
{
    public function __construct(
        private readonly OrderReferenceFormatter $formatter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('order_reference', $this->formatter->format(...)),
        ];
    }
}
