# Symfony Messenger gotchas (project-wide)

Conventions and traps that affect every command/event/query bus call site.
Read before working on anything that dispatches messages or handles their failures.

## 1. Exceptions thrown by handlers are wrapped in `HandlerFailedException`

When a handler throws, Symfony Messenger wraps the exception in
`Symfony\Component\Messenger\Exception\HandlerFailedException` before re-throwing
on the bus. **Typed `catch` blocks at the dispatch site never match the original
exception class** — they fall through to the generic `\Throwable` / `\Exception`
branch and any domain-specific recovery silently dies.

This caused [the 2026-05-08 recurring-payments incident][incident-2026-05-08]
where `PaymentNotConfirmedException` failures bypassed
`recordFailedBillingAttempt()` and the retry-then-cancel ladder never engaged.

### Always unwrap at dispatch sites

```php
use App\Service\Messenger\HandlerFailureUnwrap;

try {
    $this->commandBus->dispatch(new SomeCommand(...));
} catch (\Throwable $rawException) {
    $exception = HandlerFailureUnwrap::unwrap($rawException);

    if ($exception instanceof DomainSpecificException) {
        // domain-specific recovery
    } else {
        $this->logger->error('Unexpected failure', ['exception' => $exception]);
    }
}
```

The helper lives at `src/Service/Messenger/HandlerFailureUnwrap.php` and just
returns `$exception->getPrevious() ?? $exception` for `HandlerFailedException`,
otherwise the exception itself.

### How to spot the bug in code review

A `catch (DomainException $e)` directly after a `commandBus->dispatch()` is
**always wrong**. The matched exception will only ever be the wrapper.

## 2. The default command bus uses `doctrine_transaction` — handlers must NOT call `flush()`

Configured in `config/packages/messenger.php`. The middleware opens a transaction
before the handler runs and commits on success / rolls back on exception.

- Handlers persist entities; the middleware flushes.
- Calling `flush()` inside a handler nests transactions and breaks rollback.
- `EntityManager::flush()` is only legitimate inside `DataFixtures` and inside
  console commands **outside** the per-message catch (e.g. when recording a
  failure that must survive the rollback that just happened).

## 3. Failure recording in cron loops belongs OUTSIDE the rolled-back transaction

When a console command iterates over entities and dispatches a per-entity
command, the doctrine_transaction rolls back any handler exception. Recording
the failure (`recordFailedBillingAttempt`, etc.) must happen in the catch block
on the console command, then `EntityManager::flush()` explicitly.

A follow-up failure inside that catch (closed EntityManager, event-bus error)
must NOT kill the rest of the loop. Wrap the failure-recording in its own
`try/catch` and call `ManagerRegistry::resetManager()` on critical errors.

See `src/Console/ProcessRecurringPaymentsCommand.php` for the canonical pattern.

## 4. Async transport uses `MESSENGER_TRANSPORT_DSN` (Doctrine queue)

`Symfony\Component\Mailer\Messenger\SendEmailMessage`,
`Symfony\Component\Notifier\Message\ChatMessage`, and
`Symfony\Component\Notifier\Message\SmsMessage` are routed to the `async`
transport. Everything else runs synchronously on the bus. The retry strategy is
`max_retries: 3`, exponential backoff multiplier 2.

## 5. GoPay webhooks are NOT registered globally

There is no GoPay-side webhook configuration. Each `createPayment` /
`createRecurringPayment` call passes a `notification_url` field built from the
Symfony route `public_payment_notification` (`/webhook/gopay`), see
`src/Service/GoPay/GoPayApiClient.php::buildPaymentData()`. Recurring child
charges (`createRecurrence()`) inherit the parent payment's `notification_url`
automatically.

The webhook handler (`PaymentNotificationController` →
`ProcessPaymentNotificationCommand` → `ProcessPaymentNotificationHandler`) is
the **authoritative source of truth** for payment outcomes — synchronous polling
in handlers is best-effort. When in doubt, the webhook reconciles.

[incident-2026-05-08]: see `git log --grep="recurring payment"` around 2026-05-08
