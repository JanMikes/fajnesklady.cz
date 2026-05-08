<?php

declare(strict_types=1);

namespace App\Service\Messenger;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Unwrap the actual handler exception out of Symfony Messenger's
 * {@see HandlerFailedException} envelope.
 *
 * Symfony Messenger wraps any exception thrown by a message handler in a
 * {@see HandlerFailedException} before re-throwing on the bus. Callers that
 * want to branch on the original exception type (e.g. distinguish a
 * `GoPayException` from a generic error) MUST unwrap first — otherwise typed
 * `catch` blocks silently fall through to the generic `\Exception` branch and
 * domain-specific recovery never fires.
 *
 * See `.claude/MESSENGER.md` for the project-wide convention.
 */
final class HandlerFailureUnwrap
{
    public static function unwrap(\Throwable $exception): \Throwable
    {
        if ($exception instanceof HandlerFailedException) {
            return $exception->getPrevious() ?? $exception;
        }

        return $exception;
    }
}
