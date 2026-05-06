# 014 — One-click "Prodloužit pronájem" from previous order

**Status:** done
**Type:** UX / feature
**Scope:** small (~5 files: 1 new single-action controller; 1 email template + handler updated; 1 portal template updated; 1 integration test)
**Depends on:** none. Independent of 010/011/012/013.

## Problem

When a customer's limited-term contract is about to expire (or has just expired) and they want to keep their stuff in the same unit, today the only path is:

1. Receive the "Vaše smlouva končí za X dní" email — but its CTA is a generic "Zobrazit smlouvu" link to `portal_dashboard`.
2. Open the dashboard, find the right place, find the right storage type, click "Objednat".
3. Re-enter every field — name, address, dates, etc. — even though the system already has all of that from the previous order.

The email's `<ul>` even says "Pokud chcete pokračovat v pronájmu, navštivte svůj účet a vytvořte novou objednávku" — i.e. the wording acknowledges renewal is what the customer wants but offers no friction-reducing path. The portal order detail page (`templates/portal/user/order/detail.html.twig:54-69`) shows a similar "Smlouva brzy končí — kontaktujte nás" line. **No CTA actually starts a renewal.**

There is no "prolong" entity in this domain (and that's fine) — every renewal is a brand-new `Order` → `Contract` → `Invoice` chain. But because the previous order has every datapoint we need, we can fast-path the customer from email to the recapitulation page in **one click**.

## Goal

A single URL — `/objednavka/prodlouzit/{previousOrderId}` — that:

1. Loads the previous order.
2. Computes a sensible default period (start = the day the previous order ends, or tomorrow if already past; duration = same as before).
3. Seeds the order-form session with the previous order's data merged with the user's current profile.
4. Redirects to `public_order_create` with the **same storage** pre-selected (or the controller's existing fallback to a sister storage of the same type if it's now occupied).

The customer arrives on the order form with: same place, same storage type, same specific unit (when free), correct billing address, correct rental type, correct dates. They can press "Rekapitulace" without changing a thing.

The CTA appears in two places:

- **The "smlouva končí" email** — a green "Prodloužit pronájem" button next to (and above) the existing "Zobrazit smlouvu" link.
- **`templates/portal/user/order/detail.html.twig`** — replacing the soft-warning "Smlouva brzy končí … kontaktujte nás" copy with a real CTA, and surfacing the same button on already-completed orders so the customer can return to renew at any time.

## Context (current state)

### The order form is already friendly to this design

- `templates/components/OrderForm.html.twig` is the existing Live Component (`src/Twig/Components/OrderForm.php`). Its `instantiateForm()` (line 68) reads `order_form_data` from the session **before** falling back to `OrderFormData::fromUser($user)`. Seeding the session is therefore the supported way to prefill.
- `OrderFormData::fromUser()` (line 174) fills email/name/phone/birthDate/billing/company from `User`. `OrderFormData::fromSessionArray()` (line 221) reverses `toSessionArray()` (line 196) — full round-trip, so any subset we put in we get back. Keys absent in the session array default to empty strings/null.
- `src/Controller/Public/OrderCreateController.php:18` already accepts `/objednavka/{placeId}/{storageTypeId}/{storageId?}`. When the requested `storageId` is no longer available it transparently redirects to `findFirstAvailableStorage` of the same `storageType` (line 88-105). So if the customer's old unit is currently rented to someone else, the renewal still lands them on a working order page — no extra logic needed in the renewal controller.
- The order form is **public / anonymous** (no `IsGranted` on the controller, no firewall block on the route). A renewal link from email therefore doesn't need to authenticate — the customer can land directly. Authentication still kicks in at the appropriate step (e.g. `OrderFormData::fromUser` only runs if `$this->getUser() instanceof User`).

### The expiring-contract email today

- `src/Event/SendContractExpiringReminderHandler.php` builds `templates/email/contract_expiring.html.twig` from a `ContractExpiringSoon` event. The handler has access to `$contract->order` (line 60: `#[ORM\OneToOne(targetEntity: Order::class)]`), so the previous-order ID is available without any new wiring.
- The template already has a `.button-extend { background-color: #22c55e; }` style class but it's currently applied to "Zobrazit smlouvu" — the CTA is misnamed / misdirected, not missing. We can repurpose it for the real CTA and downgrade "Zobrazit smlouvu" to a secondary link.

### The portal order detail today

- `templates/portal/user/order/detail.html.twig:54-69` shows a yellow soft warning when `daysRemaining <= 7` with copy "kontaktujte nás". This is the obvious place for the in-portal CTA.
- The same template renders for **every** user order (not just the expiring ones). For renewal availability, we want the CTA visible whenever the order is `paid`/`completed` and limited-term — i.e. always on a finished limited rental, with copy that adapts to "končí brzy", "skončila", or just "Prodloužit pronájem" depending on `daysRemaining` / past-expiry status.

### Storage availability, races, and the same-day boundary

- `src/Service/StorageAvailabilityChecker.php` checks for overlapping orders/contracts. Contracts use a half-open interval (`Contract::isActive` returns false when `$now > $this->endDate`). Setting the new order's `startDate = previousOrder.endDate` means the boundary day is the natural handoff: previous contract is over, new one begins. The existing `findOverlappingByStorage` query in `OrderRepository`/`ContractRepository` already handles this. (If a real same-day overlap ever surfaces, `OrderCreateController` falls back to a sister storage — silent, correct.)
- Storage may have been released to `AVAILABLE` after the old contract terminated, or may now be `OCCUPIED` by a different customer. Either way, the renewal controller doesn't need to care — the existing controller chain handles both.

### Czech wording (with diacritics)

- "Prodloužit pronájem" — primary button.
- "Pokračovat v pronájmu? Stačí jeden klik." — email subhead above the button.
- "Smlouva brzy končí" / "Smlouva skončila" / "Smlouva platí do {date}" — adaptive headers in portal.

## Architecture

```
                                                      ┌─────────────────────────────────────┐
   contract_expiring email                            │  GET /objednavka/prodlouzit/{id}    │
   ┌──────────────────────────┐                       │  OrderRenewController               │
   │ "Prodloužit pronájem"   ─┼──────────────────────►│  - load previous Order              │
   │ (renewalUrl)             │                       │  - guard rails (status/active/etc.) │
   └──────────────────────────┘                       │  - compute startDate / endDate      │
                                                      │  - merge user → fromUser            │
   portal/user/order/detail                           │            ↓                        │
   ┌──────────────────────────┐                       │     ↓ overlay rentalType/dates      │
   │ "Prodloužit pronájem"   ─┼──────────────────────►│  - $session->set('order_form_data') │
   └──────────────────────────┘                       │  - redirect to public_order_create  │
                                                      │      placeId/storageTypeId/storageId│
                                                      └────────────────┬────────────────────┘
                                                                       │
                                                                       ▼
                                                       OrderCreateController (existing)
                                                       - if storage gone → fallback to sister of same type
                                                       - render OrderForm component
                                                       - LiveComponent reads session → form fully prefilled
```

## Requirements

### 1. New single-action controller: `src/Controller/Public/OrderRenewController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/objednavka/prodlouzit/{previousOrderId}',
    name: 'public_order_renew',
    requirements: ['previousOrderId' => '[0-9a-f-]{36}'],
)]
final class OrderRenewController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $previousOrderId): RedirectResponse
    {
        if (!Uuid::isValid($previousOrderId)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $previous = $this->orderRepository->find(Uuid::fromString($previousOrderId));
        if (null === $previous) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $storage = $previous->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        if (!$place->isActive || !$storageType->isActive) {
            $this->addFlash('error', 'Tato pobočka nebo typ skladu už není v nabídce. Vyberte si prosím z aktuální nabídky.');

            return $this->redirectToRoute('app_home');
        }

        // Renewal only makes sense for orders the customer actually held
        // (paid / completed). Cancelled / expired / never-paid orders fall through
        // to "make a fresh order" — no PII prefill, no special routing.
        if (!in_array($previous->status, [OrderStatus::PAID, OrderStatus::COMPLETED], true)) {
            return $this->redirectToRoute('public_order_create', [
                'placeId' => $place->id->toRfc4122(),
                'storageTypeId' => $storageType->id->toRfc4122(),
                'storageId' => $storage->id->toRfc4122(),
            ]);
        }

        // Unlimited rentals don't expire on their own — there is nothing to "prolong".
        // Tell the customer and bail early; their existing contract still runs.
        if (RentalType::UNLIMITED === $previous->rentalType) {
            $this->addFlash('info', 'Vaše smlouva je na dobu neurčitou — pokračuje automaticky. Pokud si přejete změnit pronájem, vyberte si z aktuální nabídky.');

            return $this->redirectToRoute('public_place_detail', ['id' => $place->id->toRfc4122()]);
        }

        // Limited renewal: compute new period.
        // Start: the day the previous one ends, OR tomorrow (whichever is later).
        // This handles two cases — early-bird renewal (start = old endDate, continuous)
        // and post-expiry renewal (start = tomorrow, gap is on the customer).
        $today = $this->clock->now()->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $previousEnd = $previous->endDate ?? $tomorrow;
        $newStart = $previousEnd > $tomorrow ? $previousEnd : $tomorrow;

        // Same duration as the previous order.
        $previousDays = (int) $previous->startDate->diff($previous->endDate)->days;
        if ($previousDays < 1) {
            $previousDays = 30; // defensive default — shouldn't happen, but no zero-day periods
        }
        $newEnd = $newStart->modify(sprintf('+%d days', $previousDays));

        // Merge: start with PII-rich snapshot from the user (works even when guest),
        // then overlay the renewal-specific fields. The OrderForm Live Component
        // reads this back via OrderFormData::fromSessionArray.
        $user = $previous->user;
        $formData = OrderFormData::fromUser($user);
        $formData->rentalType = RentalType::LIMITED;
        $formData->startDate = $newStart;
        $formData->endDate = $newEnd;

        $this->requestStack->getSession()->set('order_form_data', $formData->toSessionArray());

        return $this->redirectToRoute('public_order_create', [
            'placeId' => $place->id->toRfc4122(),
            'storageTypeId' => $storageType->id->toRfc4122(),
            'storageId' => $storage->id->toRfc4122(),
        ]);
    }
}
```

**Notes for the implementer:**

- Follows the existing single-action / `__invoke` / class-level route convention (CLAUDE.md).
- No `IsGranted` — the route is intentionally anonymous, mirroring `public_order_create`. The PII we seed comes from the previous order's `User`, which is implicit auth-by-link (anyone with the previous order's UUID v7 can already check the order via the existing `portal_user_order_detail` flow if logged in; here it just prefills a public form). The seeded session is per-browser, not shared.
- Use `OrderFormData::fromUser($previous->user)` even when the visitor isn't logged in. The data ends up only in their session (their browser). It's the same data the email sender would have seen. If the visitor is in fact a different person sharing the email link, they'd be prefilling someone else's billing address into their own session — a small leak. **This matches the existing privacy posture** of `OrderFormData::fromUser` (which is also called for any logged-in user when they re-enter the order flow). If we wanted stricter isolation, we'd require login on this route — explicit decision **not** to, because that breaks the "one click" promise.

### 2. Update `SendContractExpiringReminderHandler` to compute `renewalUrl`

In `src/Event/SendContractExpiringReminderHandler.php`, alongside the existing `$portalUrl` (around line 35-38), generate a renewal URL from the contract's `order` (`$contract->order`):

```php
$renewalUrl = $this->urlGenerator->generate(
    'public_order_renew',
    ['previousOrderId' => $contract->order->id->toRfc4122()],
    UrlGeneratorInterface::ABSOLUTE_URL,
);
```

Add it to the `->context([...])` block alongside `portalUrl`:

```php
'renewalUrl' => $renewalUrl,
'isLimited' => RentalType::LIMITED === $contract->rentalType,
```

`isLimited` gates the CTA — the renewal button only makes sense when the contract is fixed-term. Unlimited contracts never trigger this email at all today (`ContractExpiringSoon` is dispatched from a check that needs `endDate`), but exposing the flag keeps the template defensive and self-documenting.

### 3. Update `templates/email/contract_expiring.html.twig`

Replace the existing single-button block at the bottom of `.content` (around lines 161-171) with:

```twig
<h3>Pokračovat v pronájmu?</h3>
<p>Stačí jeden klik — všechny údaje doplníme za Vás. Stejný sklad (pokud bude volný), stejné období, stejné fakturační údaje.</p>

<div style="text-align: center;">
    {% if isLimited %}
        <a href="{{ renewalUrl }}" class="button button-extend">Prodloužit pronájem</a>
    {% endif %}
    <br>
    <a href="{{ portalUrl }}" style="color: #570df8; text-decoration: underline; font-size: 14px;">Zobrazit smlouvu v portálu</a>
</div>
```

The existing `.button-extend` (green `#22c55e`) becomes the right colour for the right CTA. Drop the `<ul>` of generic suggestions ("Prodloužit smlouvu", "Vyklidit sklad", "Kontaktovat nás") — the renewal CTA replaces the first item, the second is mentioned in the warning box, and "Kontaktovat nás" is in the footer.

Inline styles only (Outlook strips body `<style>` blocks for some clients — the template already takes that constraint into account in places, match it for the new bits).

### 4. Update `templates/portal/user/order/detail.html.twig`

**Replace lines 54-69 (the soft "Smlouva brzy končí" warning):**

```twig
{# Renewal CTA — visible whenever there's a finished limited rental.
   The wording adapts based on whether the contract still has time on it,
   has expired, or has been formally terminated. #}
{% set canRenew = order.status.value in ['paid', 'completed']
    and order.rentalType.value == 'limited'
    and order.endDate is not null %}

{% if canRenew %}
    {% set isExpiringSoon = daysRemaining is not null and daysRemaining <= 14 and daysRemaining > 0 and contract is not null and contract.terminatedAt is null %}
    {% set hasExpired = daysRemaining is not null and daysRemaining <= 0 %}

    <div class="mb-6 rounded-lg border p-4
                {% if isExpiringSoon or hasExpired %}bg-yellow-50 border-yellow-200{% else %}bg-green-50 border-green-200{% endif %}">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-sm font-semibold {% if isExpiringSoon or hasExpired %}text-yellow-800{% else %}text-green-800{% endif %}">
                    {% if hasExpired %}
                        Smlouva skončila
                    {% elseif isExpiringSoon %}
                        Smlouva brzy končí
                    {% else %}
                        Pokračovat v pronájmu?
                    {% endif %}
                </h3>
                <p class="mt-1 text-sm {% if isExpiringSoon or hasExpired %}text-yellow-700{% else %}text-green-700{% endif %}">
                    Stačí jeden klik — všechny údaje doplníme za Vás. Stejný sklad (pokud bude volný), stejné období, stejné fakturační údaje.
                </p>
            </div>
            <a href="{{ path('public_order_renew', {previousOrderId: order.id}) }}"
               class="btn btn-primary whitespace-nowrap">
                Prodloužit pronájem
            </a>
        </div>
    </div>
{% endif %}
```

The `canRenew` flag intentionally uses **both** the `paid` and `completed` order statuses (matching the controller's `OrderStatus::PAID|COMPLETED` guard) so the CTA shows up the moment payment lands and stays visible after expiry. Limited-only — unlimited rentals have nothing to renew.

### 5. Audit other email mentions of contract endings

`templates/email/contract_terminated.html.twig` and `templates/email/termination_notice.html.twig` are about user-initiated termination, not natural expiry — out of scope. `recurring_payment_*` and `payment_default_*` are about failed payments, not renewals — out of scope. Confirmed via `grep -rln "smlouva\|končí\|prodloužit" templates/email/` that only `contract_expiring.html.twig` is in scope for this spec.

### 6. Tests

- **Integration** `tests/Integration/Controller/Public/OrderRenewControllerTest.php` (new):
  - Visit `/objednavka/prodlouzit/{paidLimitedOrderId}` (anonymous client) → assert 302 to `public_order_create` with the previous storage's place/type/storage IDs.
  - Pull the session via `$client->getRequest()->getSession()->get('order_form_data')` → assert keys: `email`, `firstName`, `lastName`, `billingStreet`, `rentalType=limited`, `startDate=<previousEndDate>`, `endDate=<previousEndDate + duration>`.
  - For an unlimited order: assert 302 to `public_place_detail` and a flash message containing "neurčitou".
  - For a cancelled order: assert 302 to `public_order_create` (fresh path, no session prefill) — `order_form_data` session key is **not** set.
  - For an unknown UUID: 404.
- **Integration** `tests/Integration/Event/SendContractExpiringReminderHandlerTest.php` (extend or create):
  - Dispatch `ContractExpiringSoon` for a limited contract → assert email body contains `Prodloužit pronájem` AND a URL matching `/objednavka/prodlouzit/<uuid>`.
  - Confirm the `Zobrazit smlouvu v portálu` secondary link still renders.
- Reuse `OrderFixtures` / `ContractFixtures` for both test files.

## Acceptance

- `docker compose exec web composer quality` is green.
- A "Vaše smlouva končí za N dní" email arrives in mailpit:
  - Contains a green button "Prodloužit pronájem" linking to `https://.../objednavka/prodlouzit/<previous-order-uuid>`.
  - Clicking it lands on `/objednavka/{place}/{type}/{storage}`. The form is fully prefilled — first name, last name, email, phone, birth date, billing street/city/PSČ, company info if applicable.
  - Rental type radio is set to "Na dobu určitou" (limited). Start date = the day the previous contract ends. End date = start + same number of days as the previous order.
  - The sidebar "Shrnutí" shows the same place, same storage type, and the same storage number (when still available; otherwise a sister unit silently selected by `OrderCreateController`'s fallback).
  - Pressing "Rekapitulace" without changing any field proceeds to `public_order_accept` with the prefilled data.
- On `/portal/objednavky/{paidLimitedOrderId}`:
  - The CTA card "Pokračovat v pronájmu?" / "Smlouva brzy končí" / "Smlouva skončila" appears (wording adapts to `daysRemaining`).
  - Clicking "Prodloužit pronájem" produces the same prefilled flow as the email link.
- Edge cases:
  - Unlimited rental → email button is hidden (`isLimited` is false in template); portal CTA is hidden (`canRenew` excludes them); direct visit to the renewal URL redirects to place detail with the "neurčitou" flash.
  - Cancelled / never-paid order → renewal URL falls through to a fresh `public_order_create` (no PII prefill, no error).
  - Storage occupied at the moment of click → `OrderCreateController`'s existing fallback redirects to another available unit of the same type (no extra logic in the renewal controller).
  - Place or storage type deactivated → flash message + redirect to home.
- A logged-in user on a different account who somehow obtains the URL: lands on the order form with the **previous customer's** PII in the session. **This is the existing privacy boundary** of the public order form (any anonymous visitor of `public_order_create` for a logged-in user already sees `fromUser` data in the session). Documented in the controller; not changed by this spec.

## Out of scope

- **A "Renew" CTA on the dashboard, the user order list, or the contracts page.** Order detail + email is enough to land the feature; if metrics show low click-through we can extend later. Adding it to a list cell would crowd the layout.
- **A separate `RenewalRequested` domain event / audit log entry.** The renewal still emits the standard `OrderCreated` event when the customer presses Rekapitulace + Objednat — that's the audit trail. There's no business meaning to "I clicked prolong but didn't finish" beyond what session telemetry shows.
- **Carrying the previous storage's specific lock code, photos, signing-method preference forward.** Each renewal is a distinct contract; the customer signs again, gets a new lock code, etc. (Same-storage continuity is purely a UX nicety — it doesn't imply contract continuity.)
- **Differential pricing** ("you've been a customer for 6 months, here's a discount"). Out of scope; renewal uses the storage's current effective price.
- **Auto-renewal** ("renew automatically without my involvement"). Explicitly different feature — requires GoPay token + customer pre-authorisation. The existing unlimited rental + recurring payment flow is the existing answer to that need.
- **Backfill** of the new email CTA into already-sent expiring-soon emails. Going forward only.
- **Pre-selecting `selectionMode = 'manual'`** on the order form so the map opens. The pre-selected storage is already shown in the sidebar summary; opening the map would be visual noise. Spec 009 keeps `auto` as the default; honour that.
- **Multi-language captions.** Czech-only stack today.

## Open questions

None — proceed.
