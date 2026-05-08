<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Messenger;

use App\Service\Messenger\HandlerFailureUnwrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class HandlerFailureUnwrapTest extends TestCase
{
    public function testReturnsOriginalExceptionWhenNotWrapped(): void
    {
        $original = new \RuntimeException('boom');

        $this->assertSame($original, HandlerFailureUnwrap::unwrap($original));
    }

    public function testUnwrapsHandlerFailedException(): void
    {
        $original = new \DomainException('original cause');
        $envelope = new Envelope(new \stdClass());
        $wrapped = new HandlerFailedException($envelope, [$original]);

        $this->assertSame($original, HandlerFailureUnwrap::unwrap($wrapped));
    }
}
