<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Place\PlaceAddressFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PlaceAddressExtension extends AbstractExtension
{
    public function __construct(
        private readonly PlaceAddressFormatter $formatter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('place_address', $this->formatter->format(...)),
            new TwigFunction('place_navigation_url', $this->formatter->navigationUrl(...)),
            new TwigFunction('place_has_navigation', $this->formatter->hasNavigation(...)),
        ];
    }
}
