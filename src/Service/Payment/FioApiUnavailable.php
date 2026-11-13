<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * FIO's API could not be used this run — unreachable, or it answered with
 * something that is not a transaction statement (typically FIO's own
 * maintenance window, e.g. Sunday evenings). An outage on FIO's side: the
 * cron still fails loudly, but with this one readable error instead of a
 * PHP warning + TypeError from inside the FIO library.
 */
final class FioApiUnavailable extends \RuntimeException
{
    public static function invalidResponse(int $statusCode, string $body): self
    {
        $snippet = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '', 0, 200));

        return new self(sprintf(
            'FIO API vrátilo neplatnou odpověď (HTTP %d, %s) — pravděpodobně údržba na straně FIO.',
            $statusCode,
            '' === $snippet ? 'prázdné tělo' : sprintf('„%s"', $snippet),
        ));
    }

    public static function unreachable(\Throwable $previous): self
    {
        return new self(sprintf('FIO API je nedostupné: %s', $previous->getMessage()), 0, $previous);
    }
}
