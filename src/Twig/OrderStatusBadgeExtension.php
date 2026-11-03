<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Contract;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Service\Order\OrderDisplayStatusResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Status badge for an order row. OrderStatus::COMPLETED only means "the order
 * flow finished and a contract exists" — rendering it raw as "Dokončeno" made
 * every running rental look finished. For completed orders the badge shows the
 * CONTRACT's state instead (Aktivní / výpověď podána / platba selhala /
 * ukončeno …) via {@see OrderDisplayStatusResolver}; pre-completion statuses
 * keep their granular admin labels.
 *
 * Rendered by components/order_status_badge.html.twig.
 */
final class OrderStatusBadgeExtension extends AbstractExtension
{
    private const array PRE_COMPLETION = [
        'created' => ['Vytvořeno', 'gray'],
        'reserved' => ['Rezervováno', 'yellow'],
        'awaiting_payment' => ['Čeká na platbu', 'amber'],
        'paid' => ['Zaplaceno', 'green'],
        'cancelled' => ['Zrušeno', 'red'],
        'expired' => ['Expirováno', 'gray'],
    ];

    private const array VARIANT_TONES = [
        'success' => 'green',
        'info' => 'blue',
        'warning' => 'amber',
        'error' => 'red',
        'neutral' => 'gray',
    ];

    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly OrderDisplayStatusResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('order_status_badge', $this->badge(...)),
        ];
    }

    /**
     * @param ?Contract $contract pass it when the caller already has it (list
     *                            pages batch-load contracts); looked up otherwise
     *
     * @return array{label: string, tone: string, title: ?string}
     */
    public function badge(Order $order, ?Contract $contract = null): array
    {
        if (OrderStatus::COMPLETED !== $order->status) {
            [$label, $tone] = self::PRE_COMPLETION[$order->status->value];

            return ['label' => $label, 'tone' => $tone, 'title' => null];
        }

        $contract ??= $this->contractRepository->findByOrder($order);
        $display = $this->resolver->resolve($order, $contract);

        $title = $display->description;
        if (null !== $contract?->terminatedAt) {
            $title = sprintf('%s (%s)', $title ?? 'Smlouva ukončena', $contract->terminatedAt->format('d.m.Y'));
        }

        return [
            'label' => $display->label,
            'tone' => self::VARIANT_TONES[$display->variant] ?? 'gray',
            'title' => $title,
        ];
    }
}
