<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnalyticsEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An outbox of measurement events waiting to reach a browser's dataLayer.
 *
 * The events the marketing side needs ("order confirmed", "first payment
 * received") are settled on the server, and two of the three payment paths
 * have no browser attached at all: the GoPay webhook is server-to-server, and
 * a bank transfer is matched days later by the FIO cron. Pushing to dataLayer
 * at the moment of truth is therefore impossible — there is nothing to push
 * into.
 *
 * So the moment of truth writes a row here instead, with the payload snapshot
 * taken right then, and the next page that customer loads flushes whatever is
 * still unpushed. The event still *means* "the backend confirmed it"; only its
 * delivery is deferred to the next available browser.
 *
 * Snapshotting matters: the order can change afterwards (price edits,
 * prolongation), and a conversion must report what was true when it happened,
 * not what the order looks like at render time.
 *
 * @see AnalyticsEventRepository::findUnpushedForOrder()
 */
#[ORM\Entity]
#[ORM\Index(fields: ['pushedAt'])]
class AnalyticsEvent
{
    public const string ORDER_CREATED = 'order_created';
    public const string FIRST_PAYMENT_SUCCESS = 'first_payment_success';

    /**
     * Null until a browser has actually received it. Never reset — a conversion
     * must not be counted twice.
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $pushedAt = null;

    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Order $order,
        #[ORM\Column(length: 50)]
        private(set) string $name,
        #[ORM\Column(type: Types::JSON)]
        private(set) array $payload,
        #[ORM\Column]
        private(set) \DateTimeImmutable $occurredAt,
    ) {
    }

    public function markPushed(\DateTimeImmutable $now): void
    {
        $this->pushedAt = $now;
    }
}
