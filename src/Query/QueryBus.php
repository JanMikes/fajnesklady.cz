<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class QueryBus
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queryBus,
    ) {
    }

    /**
     * @template TResult of object
     *
     * @param QueryMessage<TResult> $query
     *
     * @return TResult
     */
    public function handle(QueryMessage $query): object
    {
        $envelope = $this->queryBus->dispatch($query);
        $handledStamp = $envelope->last(HandledStamp::class);

        if (null === $handledStamp) {
            throw new \LogicException(sprintf('Query "%s" was not handled. Did you forget to register a handler?', $query::class));
        }

        /* @var TResult */
        return $handledStamp->getResult();
    }
}
