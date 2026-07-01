<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * Test env only: the UX Live Component test helper
     * ({@see \Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents}) fetches
     * these services from the container by id. They are private and referenced
     * once, so the optimizer inlines them away and the test-container locator
     * can no longer provide them — making them public keeps them addressable.
     */
    public function process(ContainerBuilder $container): void
    {
        if ('test' !== $this->environment) {
            return;
        }

        foreach ([
            'ux.twig_component.component_factory',
            'ux.live_component.component_hydrator',
            'ux.live_component.metadata_factory',
        ] as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->getDefinition($serviceId)->setPublic(true);
            }
        }
    }
}
