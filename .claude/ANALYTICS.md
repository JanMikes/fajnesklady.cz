# Measurement events (GTM dataLayer)

The marketing side configures GA4 and Google Ads themselves. The application's job is to put two
server-confirmed events into `window.dataLayer` with trustworthy values. **The payload keys below are
an external contract** — their GTM triggers and Ads conversion actions are wired to these exact
names. Renaming one silently breaks their reporting, with no error anywhere.

## The two events

| Event | Fired when | Source of truth |
|---|---|---|
| `order_created` | the binding order row exists | `OrderCreated`, recorded in `Order::__construct` |
| `first_payment_success` | money for the first payment actually arrived | `OrderPaid`, recorded in `Order::markPaid()` |

`OrderPaid` is the single point every payment path converges on: the GoPay webhook, the return page
(which re-queries GoPay instead of trusting the redirect), the FIO bank-transfer cron, and the admin
external-payment flow. Neither event can be produced by a customer clicking a button or landing on a
URL.

### Deliberate non-conversions

`first_payment_success` is skipped for:

- `PaymentMethod::EXTERNAL` — money taken outside the system by the operator;
- an explicit zero amount (`OrderPaid::$amountOverride === 0`) — the free/prepaid formality that
  auto-completes an order without money moving.

Both mirror the rule `OrderService::confirmPayment()` already applies to the audit log. Reporting
them would bill the campaign for conversions that carried no payment. Admin-onboarding orders
(`Order::$createdByAdmin !== null`) are skipped by **both** events — no customer browser, no campaign.

## Why there is an outbox table

Two of the three payment paths have no browser attached: the GoPay webhook is server-to-server and a
bank transfer is matched days later by the cron. `dataLayer.push()` at the moment of truth is
therefore impossible — there is nothing to push into.

So the moment of truth writes an `analytics_event` row with the payload snapshotted right then, and
the next page that customer loads flushes whatever is unpushed
(`AnalyticsEventFlusher::flushFor()`, currently wired into `OrderPaymentController` and
`OrderStatusController` — the latter is also the link in the confirmation e-mail, which is how a
bank-transfer conversion eventually lands). `pushedAt` is never reset: a conversion must not be
counted twice.

Snapshotting matters — the order can change afterwards (price edits, prolongation) and a conversion
must report what was true when it happened.

## Money

**Prices are stored in haléře. The payload carries CZK.** `AnalyticsPayloadFactory::toMajorUnits()`
owns the `/100`. Getting this wrong reports every conversion at 100× its value and nothing in the
app looks wrong — only the agency's bidding quietly goes mad.
`AnalyticsPayloadFactoryTest` pins it.

## Consent

The push is unconditional and that is correct. `dataLayer` is a plain JavaScript array in the
visitor's own page: pushing to it neither stores anything on the device nor transmits anything, so
ZEK § 89 odst. 3 — which governs storing on / reading from terminal equipment — does not reach it.
What consent gates is the GTM **tags** that read the dataLayer and send, and those are already held
by Consent Mode v2 (`components/_gtm_head.html.twig`, see `.claude/COMPLIANCE.md`). Pushing
unconditionally is also what lets a tag fire correctly for someone who accepts later in the session.

## Payload

`order_created`: `order_id`, `order_number` (variable symbol), `place_id`, `place_name`,
`storage_type_id`, `storage_type_name`, `value`, `currency`, `rental_start_date`, `rental_end_date`,
`rental_days`, `payment_frequency`.

`first_payment_success`: `order_id`, `order_number`, `transaction_id` (GoPay payment id),
`payment_method`, `value`, `currency`, `place_id`, `place_name`, `storage_type_id`,
`storage_type_name`.

`value` is the first payment, not the whole rental. For a bank transfer `transaction_id` is null —
there is no GoPay payment.
