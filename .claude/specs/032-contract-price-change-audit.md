# 032 — Contract price-change audit trail

**Status:** done
**Type:** feature (audit trail / financial observability)
**Scope:** small (~10 files: 1 entity + 1 event + 1 handler + repo + migration + listener-trigger inside `Contract` + admin partial + tests + PROJECT_MAP/BACKLOG)
**Depends on:** spec 025 (introduced `Contract.individualMonthlyAmount` and `applyIndividualMonthlyAmount()` — this spec adds history on top)

## Problem

Spec 025 added `Contract.individualMonthlyAmount` as a per-customer monthly override that survives storage-price changes. Today `Contract::applyIndividualMonthlyAmount(?int $amount)` silently overwrites the previous value with no record of who changed it, when, from what to what, or why. This is a **financial mutation** — it directly drives the recurring `ChargeRecurringPaymentHandler` and the customer's monthly invoice. Before we ship more admin tooling that lets users edit billing post-onboarding, every change to the override must be auditable.

There is also no visibility into the current value's provenance: the admin order-detail page shows "Individuální měsíční cena: 800 Kč" with no indication that it used to be 1 200 Kč in February or who set it.

## Goal

Append-only `ContractPriceChange` entity records every call to `applyIndividualMonthlyAmount`. Each row captures previous amount, new amount, timestamp, the `User` who triggered it (nullable for system / cron / fixture), and an optional free-text `reason`. Admin order-detail page gains a "Historie ceny" panel listing all changes for the contract, newest first.

No UI for editing `individualMonthlyAmount` after onboarding ships in this spec — the foundation goes in first so the future edit UI is auditable from day one. Backfill at migration time so contracts that already have a non-null override (created via spec 025 onboarding) start with a single "Initial value" row instead of an empty history.

## Context (current state)

- `src/Entity/Contract.php:263` — `applyIndividualMonthlyAmount(?int $amount): void`. Current signature is `?int` only; this spec extends it to also take the actor + reason. Domain guards already exist (rejects negative, rejects > `PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER`). **No setter exists** — only this behaviour method. Good: any audit trail attached to the method covers every legitimate mutation.
- `src/Entity/Contract.php:276` — `getEffectiveMonthlyAmount(): int` is the read accessor.
- `src/Entity/Contract.php:286` — `isFree(): bool` returns true for explicit zero.

### Existing call sites of `applyIndividualMonthlyAmount`

Production:
- `src/Service/OrderService.php:158` — only production caller. Inside `completeOrder()`, after creating the contract; reads `$order->individualMonthlyAmount` (set during admin onboarding by `AdminCreateOnboardingHandler` / `AdminMigrateCustomerHandler` via `Order::setOnboardingBillingTerms()`). The actor at this point is the admin who created the order — but `OrderService::completeOrder()` runs from multiple contexts: `CustomerSignOnboardingHandler` (signing on behalf of the customer), `AdminMigrateCustomerHandler` (admin signs paper migration), `OrderService` itself called from `ConfirmOrderPaymentHandler` (no admin involved — GoPay webhook). For an external-prepaid digital onboarding the customer signs but the admin chose the price, so the actor of the price decision is the admin. To keep the audit faithful we pass the actor explicitly into `applyIndividualMonthlyAmount` — see Requirement 2.

Tests + fixtures:
- `tests/Unit/Entity/ContractTest.php` (lines 621, 632, 644, 653, 660, 668, 670)
- `tests/Unit/Service/Overdue/OverdueCheckerTest.php:168`
- `tests/Integration/Repository/ContractRepositoryTest.php:546`
- `tests/Integration/Command/ChargeRecurringPaymentHandlerTest.php:118, 131`
- `fixtures/OnboardingFixtures.php:150`

All existing tests pass `?int` only. They will need to be updated to pass `null` for actor and `null`/some reason — see Requirement 8.

### Pattern reference: append-only audit via entity + listener

Spec 004 (`.claude/specs/004-email-audit-log.md`) is the closest analogue — a write-only audit entity (`EmailLog`) populated by a listener (`EmailLogger`) on every `SentMessageEvent`. We mirror its principles:
- Final, append-only entity (no behaviour methods that mutate fields after construction).
- Repository composes `EntityManager`, builds queries via `createQueryBuilder()`.
- Listener / handler must not break the originating flow if persistence fails.

The mechanism here is a **domain event** (`ContractPriceChanged`) recorded by the entity in the `applyIndividualMonthlyAmount` method, persisted by an event handler. That matches the existing pattern used for `OrderCreated` / `OrderPaid` / `EmailVerified` etc. (`src/Entity/Order.php:122` for the canonical example).

### Domain event plumbing

- `Contract` does NOT currently implement `EntityWithEvents` / use `HasEvents`. Other entities that record events: `Order`, `User`, `PlaceAccessRequest`, `Invoice`, `HandoverProtocol` (`src/Entity/HandoverProtocol.php:17`). Adding the trait + interface to `Contract` is mechanical (`use HasEvents;` + `implements EntityWithEvents`).
- Event bus auto-dispatches recorded events after entity persist via `App\Service\DomainEventDispatcher` (mirrored across the project; see `RegisterLandlordHandler.php:79`'s explicit `$user->recordThat(...)` for the pattern).

### Admin order-detail surface

- `templates/admin/order/detail.html.twig:65` already includes `_onboarding_banner.html.twig`. The new "Historie ceny" panel goes **after** the onboarding banner, gated on `priceChanges|length > 0` so vanilla customer-created orders show nothing.
- The detail controller is `App\Controller\Admin\AdminOrderDetailController`. It already loads the order; we extend it to also load the contract's price-change history (read-only).

### Migration constraint

`CLAUDE.md` mandates `bin/console make:migration` — never handwritten. Backfill SQL (one row per contract with non-null `individual_monthly_amount`) goes inside the generated migration's `up()` after the schema-change DDL. The `make:migration` skeleton allows arbitrary SQL inside `up()`, so we run a single `INSERT INTO contract_price_change (...) SELECT ... FROM contract WHERE individual_monthly_amount IS NOT NULL`. The schema diff itself (table creation) is fully generated; we only handwrite the data backfill block.

## Architecture

```
                ┌──────────────────────────────────────────────┐
                │  Contract::applyIndividualMonthlyAmount(     │
                │     ?int $amount,                            │
                │     ?User $changedBy,                        │
                │     ?string $reason,                         │
                │     \DateTimeImmutable $now,                 │
                │  )                                           │
                │                                              │
                │  - Captures $previous = individualMonthly... │
                │  - Validates (existing guards)               │
                │  - Writes new value                          │
                │  - $this->recordThat(new ContractPriceChanged│
                │       (contractId, previous, new, changedBy, │
                │        reason, occurredOn=$now))             │
                └────────────────────┬─────────────────────────┘
                                     │
                          (event bus, after persist)
                                     │
                ┌────────────────────▼─────────────────────────┐
                │  PersistContractPriceChangeHandler           │
                │  (#[AsMessageHandler] on event bus)          │
                │  - new ContractPriceChange(...)              │
                │  - $repository->save($change)                │
                └──────────────────────────────────────────────┘

           Read path:
                ┌──────────────────────────────────────────────┐
                │  AdminOrderDetailController                  │
                │  → ContractPriceChangeRepository             │
                │      ::findByContractOrderedByDate($contract)│
                │  → templates/admin/order/                    │
                │      _price_change_history.html.twig         │
                └──────────────────────────────────────────────┘
```

## Requirements

### 1. New entity `ContractPriceChange`

`src/Entity/ContractPriceChange.php`. Append-only — all fields set in the constructor, no behaviour methods, no setters.

```php
#[ORM\Entity]
#[ORM\Table(name: 'contract_price_change')]
#[ORM\Index(columns: ['contract_id', 'changed_at'], name: 'idx_contract_price_change_contract_changed')]
class ContractPriceChange
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,

        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Contract $contract,

        /** halere; null = was using storage default (no override) */
        #[ORM\Column(nullable: true)]
        private(set) ?int $previousAmount,

        /** halere; null = back to storage default; 0 = made free */
        #[ORM\Column(nullable: true)]
        private(set) ?int $newAmount,

        #[ORM\Column]
        private(set) \DateTimeImmutable $changedAt,

        /** null = system / cron / fixture / migration backfill */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private(set) ?User $changedBy,

        /** free-text, e.g. "Sleva sjednána s majitelem", "Initial value (backfill)" */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $reason,
    ) {
    }
}
```

Notes:
- `onDelete: 'CASCADE'` on the contract FK so deleting a contract cascades. Aligns with the existing `Contract` ↔ child relationships (e.g. `Invoice`, `Payment`) where contract removal is a destructive admin op.
- `onDelete: 'SET NULL'` on `changedBy` — if the admin user is later deleted we keep the change record but lose the actor. Matches `AuditLog`'s nullable-user pattern.
- No domain events on this entity (it IS the audit record — recording its own change would loop).

### 2. Extend `Contract::applyIndividualMonthlyAmount()` signature

`src/Entity/Contract.php:263` — change to:

```php
public function applyIndividualMonthlyAmount(
    ?int $amount,
    ?User $changedBy,
    ?string $reason,
    \DateTimeImmutable $now,
): void {
    if (null !== $amount && $amount < 0) {
        throw new \InvalidArgumentException('Individual monthly amount cannot be negative.');
    }

    if (null !== $amount && $amount > PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER) {
        throw new \DomainException(sprintf(
            'Individual monthly amount %d Kč exceeds the legal recurring-payment maximum of %d Kč.',
            intdiv($amount, 100),
            intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
        ));
    }

    $previous = $this->individualMonthlyAmount;
    $this->individualMonthlyAmount = $amount;

    $this->recordThat(new ContractPriceChanged(
        contractId: $this->id,
        previousAmount: $previous,
        newAmount: $amount,
        changedBy: $changedBy,
        reason: $reason,
        occurredOn: $now,
    ));
}
```

Add to the class declaration:

```php
class Contract implements EntityWithEvents
{
    use HasEvents;
    // …
}
```

**Idempotence guard — design decision:** if `$previous === $amount` we still record the event. Rationale: the act of an admin re-affirming the price (with a fresh `reason` like "Confirmed during 2026 review") is itself audit-worthy and lets the operator see when the value was last touched even if it didn't change. If the implementer disagrees they should surface it as a follow-up question — current default ships every call.

**No-op pass-through is impossible here**: the method always records, callers always pass `$now` from a clock service. There is no "silent" form.

### 3. New domain event `ContractPriceChanged`

`src/Event/ContractPriceChanged.php`:

```php
final readonly class ContractPriceChanged
{
    public function __construct(
        public Uuid $contractId,
        public ?int $previousAmount,
        public ?int $newAmount,
        public ?User $changedBy,
        public ?string $reason,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

`User` is referenced by object (not just `?Uuid $changedById`) so the handler doesn't have to re-load it from the repo on the hot path. Since this is a synchronous in-process event (handled in the same request as the entity mutation), holding the entity reference is safe and matches how `OrderPaid` / `LandlordRegistered` reference their actors.

### 4. New event handler `PersistContractPriceChangeHandler`

`src/Event/PersistContractPriceChangeHandler.php`. Mirrors `IssueInvoiceOnPaymentHandler` and other event-bus handlers:

```php
#[AsMessageHandler]
final readonly class PersistContractPriceChangeHandler
{
    public function __construct(
        private ContractPriceChangeRepository $repository,
        private ContractRepository $contractRepository,
        private ProvideIdentity $identityProvider,
    ) {}

    public function __invoke(ContractPriceChanged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);

        $change = new ContractPriceChange(
            id: $this->identityProvider->next(),
            contract: $contract,
            previousAmount: $event->previousAmount,
            newAmount: $event->newAmount,
            changedAt: $event->occurredOn,
            changedBy: $event->changedBy,
            reason: $event->reason,
        );

        $this->repository->save($change);
    }
}
```

The handler runs on the event bus inside the same request transaction (via `doctrine_transaction` on the command bus); the `ContractPriceChange` flushes alongside the contract that produced the event. Failure to persist the audit row rolls back the original mutation — **deliberate**: a financial change MUST be auditable; if we can't write the audit row we can't accept the change.

### 5. New repository `ContractPriceChangeRepository`

`src/Repository/ContractPriceChangeRepository.php`. Composition pattern (no `ServiceEntityRepository`).

```php
final class ContractPriceChangeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(ContractPriceChange $change): void
    {
        $this->entityManager->persist($change);
        // No flush — handler runs under doctrine_transaction middleware.
    }

    /**
     * @return ContractPriceChange[]  newest first
     */
    public function findByContractOrderedByDate(Contract $contract): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('cpc')
            ->from(ContractPriceChange::class, 'cpc')
            ->where('cpc.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('cpc.changedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
```

### 6. Update the production caller `OrderService::completeOrder()`

`src/Service/OrderService.php:158`. Today:

```php
if (null !== $order->individualMonthlyAmount) {
    $contract->applyIndividualMonthlyAmount($order->individualMonthlyAmount);
}
```

After this spec:

```php
if (null !== $order->individualMonthlyAmount) {
    $contract->applyIndividualMonthlyAmount(
        amount: $order->individualMonthlyAmount,
        changedBy: $order->createdByAdmin,  // see below
        reason: 'Initial value (admin onboarding)',
        now: $now,
    );
}
```

Two sub-tasks:

(a) **Source of `$changedBy`.** `OrderService::completeOrder()` already takes `\DateTimeImmutable $now` — keep it. It does NOT take a `User` actor today. We have two options:

- **Option A (recommended)**: persist the admin actor on `Order` at onboarding time. Add a nullable `Order::createdByAdmin` (`ManyToOne(User::class), JoinColumn(nullable: true)`). Set it in `AdminCreateOnboardingHandler` and `AdminMigrateCustomerHandler` from the security token (controller passes it down via the command, since handlers don't have access to the security stack). For non-admin-created orders (vanilla customer orders) it stays null — and so does the contract's individualMonthlyAmount, so we never read it on those flows. **This is the right shape**: the admin is part of the order's provenance, not just the price.
- Option B: pass `?User` as a new parameter through `OrderService::completeOrder()`. Cleaner method signature but worse provenance — the callers (`ConfirmOrderPaymentHandler`, `CustomerSignOnboardingHandler`, `AdminMigrateCustomerHandler`) each have a different idea of "who did this", and the admin who set the price is not the same actor as the GoPay webhook that confirms it.

Pick Option A. Implementation:

`Order` entity — add:

```php
#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(nullable: true)]
public private(set) ?User $createdByAdmin = null;

public function setOnboardingBillingTerms(
    ?int $individualMonthlyAmount,
    ?\DateTimeImmutable $paidThroughDate,
    ?User $createdByAdmin = null,
): void {
    $this->individualMonthlyAmount = $individualMonthlyAmount;
    $this->paidThroughDate = $paidThroughDate;
    if (null !== $createdByAdmin) {
        $this->createdByAdmin = $createdByAdmin;
    }
}
```

Default to `null` so any existing call site keeps compiling. `AdminCreateOnboardingHandler` and `AdminMigrateCustomerHandler` get the actor via a new field on their `Command` DTO, populated by the controller from `$this->getUser()`:

```php
// Command DTO extension
public ?Uuid $createdByAdminId,

// Controller
new AdminCreateOnboardingCommand(
    // … existing fields …
    createdByAdminId: $admin->id,
);

// Handler
$createdByAdmin = $this->userRepository->get($command->createdByAdminId);
$order->setOnboardingBillingTerms(
    $command->individualMonthlyAmount,
    $command->paidThroughDate,
    $createdByAdmin,
);
```

(b) **Generate migration:** `docker compose exec web bin/console make:migration`. The diff covers BOTH new tables/columns: `contract_price_change` table AND `order.created_by_admin_id` FK column. Inside the same migration, after the DDL, append the backfill SQL — see Requirement 7.

### 7. Migration with data backfill

After running `make:migration`, edit the generated `up()` body to append:

```php
// Backfill: every contract that already has a non-null individualMonthlyAmount
// (created via spec 025 onboarding) gets one history row marking the initial
// value. previousAmount is NULL — we have no record of the prior state, but
// the reason string makes the provenance clear.
$this->addSql(<<<'SQL'
    INSERT INTO contract_price_change
        (id, contract_id, previous_amount, new_amount, changed_at, changed_by_id, reason)
    SELECT
        gen_random_uuid(),
        c.id,
        NULL,
        c.individual_monthly_amount,
        c.created_at,
        NULL,
        'Initial value (backfill)'
    FROM contract c
    WHERE c.individual_monthly_amount IS NOT NULL
SQL);
```

`down()` keeps the auto-generated DROP TABLE (the data goes with the table — no manual cleanup needed).

`gen_random_uuid()` is Postgres-native and returns UUIDv4. We accept v4 IDs for backfill rows since `ProvideIdentity` (UUIDv7) is unavailable from SQL. The `id` column type is `uuid` so storage is identical.

### 8. Update existing test + fixture call sites

The current `Contract::applyIndividualMonthlyAmount(?int)` signature will not compile after Requirement 2. Update every call site to pass `($amount, $changedBy, $reason, $now)`:

- `tests/Unit/Entity/ContractTest.php:621, 632, 644, 653, 660, 668, 670` — fixture-style tests; pass `null` for `$changedBy` and `'Test'` (or a meaningful per-test string) for `$reason`, plus the `MockClock` fixed `$now`.
- `tests/Unit/Service/Overdue/OverdueCheckerTest.php:168` — `null, null, $now`.
- `tests/Integration/Repository/ContractRepositoryTest.php:546` — `null, null, $now`.
- `tests/Integration/Command/ChargeRecurringPaymentHandlerTest.php:118, 131` — `null, null, $now`.
- `fixtures/OnboardingFixtures.php:150` — `changedBy: $admin` (the fixture has access to the admin user); `reason: 'Initial value (fixture)'`; `now: $now`.

These updates are mechanical and do not change test intent.

### 9. Admin order-detail panel

#### Controller

`src/Controller/Admin/AdminOrderDetailController.php` — inject `ContractPriceChangeRepository`. After loading the order:

```php
$priceChanges = null !== $order->contract
    ? $this->priceChangeRepository->findByContractOrderedByDate($order->contract)
    : [];

return $this->render('admin/order/detail.html.twig', [
    'order' => $order,
    // … existing vars …
    'priceChanges' => $priceChanges,
]);
```

(Verify the controller's existing access path to the contract — the repo method on `OrderRepository` may already eager-load it; if not, look up via `$contractRepository->findByOrder($order)` which exists for spec 023.)

#### Template partial

New `templates/admin/order/_price_change_history.html.twig`:

```twig
{% if priceChanges|length > 0 %}
    <div class="card bg-base-100 shadow mb-4">
        <div class="card-body">
            <h3 class="card-title text-base">Historie ceny</h3>
            <ul class="divide-y divide-base-200 text-sm">
                {% for change in priceChanges %}
                    <li class="py-2 flex flex-col gap-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-base-content/70">{{ change.changedAt|date('d.m.Y H:i') }}</span>
                            <span class="text-base-content/50">·</span>
                            <span>{{ change.changedBy ? change.changedBy.email : 'systém' }}</span>
                            <span class="text-base-content/50">·</span>
                            <span>
                                {% if change.previousAmount is null %}
                                    standardní cena
                                {% else %}
                                    {{ (change.previousAmount/100)|number_format(0, ',', ' ') }} Kč
                                {% endif %}
                                <span class="mx-1">→</span>
                                {% if change.newAmount is null %}
                                    standardní cena
                                {% elseif change.newAmount == 0 %}
                                    zdarma
                                {% else %}
                                    {{ (change.newAmount/100)|number_format(0, ',', ' ') }} Kč
                                {% endif %}
                            </span>
                            {% if change.reason %}
                                <span class="text-base-content/70">({{ change.reason }})</span>
                            {% endif %}
                        </div>
                    </li>
                {% endfor %}
            </ul>
        </div>
    </div>
{% endif %}
```

Include from `templates/admin/order/detail.html.twig` immediately after `_onboarding_banner.html.twig` (around line 66):

```twig
{% include 'admin/order/_price_change_history.html.twig' with {priceChanges: priceChanges} %}
```

The empty-list guard inside the partial means vanilla orders (no individual price) render nothing, no empty card.

### 10. PROJECT_MAP.md update

Append to `.claude/specs/PROJECT_MAP.md`:
- Entities — add `ContractPriceChange` row: "Append-only audit row per `applyIndividualMonthlyAmount` call · contract, changedBy:User?".
- Domain Events — add `ContractPriceChanged`.

### 11. Tests

Place per existing conventions.

#### Unit

`tests/Unit/Entity/ContractPriceChangedEventTest.php` (new):
- Calling `applyIndividualMonthlyAmount(80_000, $admin, 'Sleva', $now)` records exactly one `ContractPriceChanged` event with `previousAmount=null, newAmount=80_000, changedBy=$admin, reason='Sleva', occurredOn=$now`.
- Calling it twice in a row records TWO events; the second has `previousAmount=80_000, newAmount=...`.
- Calling with `amount=null` after `80_000` records `previousAmount=80_000, newAmount=null`.
- Calling with `amount=0` records `newAmount=0` (free contract — distinct from null).
- Re-affirming the same amount (`80_000` → `80_000`) still records (per Requirement 2 design decision).
- Negative amount throws and records NO event.
- Over-cap amount throws and records NO event.

#### Integration

`tests/Integration/Event/PersistContractPriceChangeHandlerTest.php` (new):
- Fire `Contract::applyIndividualMonthlyAmount(50_000, $admin, 'Test', $now)` via a real contract committed to the DB; flush; assert one `ContractPriceChange` row exists with the expected fields and FK to the contract.
- Fire twice; assert `findByContractOrderedByDate` returns two rows, newest first.

`tests/Integration/Repository/ContractPriceChangeRepositoryTest.php` (new):
- `findByContractOrderedByDate` returns rows for the asked contract only (does not leak across contracts).
- Order is DESC by `changedAt`.

`tests/Integration/Migration/BackfillContractPriceChangeTest.php` — **skip**. Migration backfill is verified by running `composer db:reset` against a snapshot of pre-migration data — that's an operational check, not a test. The fixture (`OnboardingFixtures`) creates contracts via the normal flow which ALREADY records changes (because Requirement 8 updates the fixture to pass real args). So after `db:reset` the rows come from the fixture path, not the backfill SQL. To verify the backfill SQL itself we rely on the Acceptance step that runs against an existing-data DB.

#### Controller

`tests/Integration/Controller/Admin/AdminOrderDetailControllerTest.php` (extend existing if present, otherwise new):
- Visiting `/portal/admin/orders/{id}` for an order whose contract has ≥1 price change shows the "Historie ceny" panel with the right text format.
- Visiting for a vanilla order shows no panel (selector `.card-title:contains("Historie ceny")` is absent).

## Acceptance

- [ ] `docker compose exec web composer quality` is green (cs:fix, phpstan level 8, test:unit, test).
- [ ] `docker compose exec web bin/console doctrine:schema:validate` reports no diff after the new migration.
- [ ] `docker compose exec web bin/console make:migration` was used; only the backfill `addSql(...)` block is handwritten — schema DDL is generator output.
- [ ] After `composer db:reset`, the `contract_price_change` table contains ≥1 row per fixture contract that has a non-null `individualMonthlyAmount` (matches the count from `OnboardingFixtures`).
- [ ] After the migration runs against a pre-existing DB (admin onboarding from spec 025 already produced contracts), every such contract has exactly one backfill row with `previousAmount=NULL, newAmount=contract.individualMonthlyAmount, changedAt=contract.createdAt, changedBy=NULL, reason='Initial value (backfill)'`.
- [ ] Calling `Contract::applyIndividualMonthlyAmount(50_000, $admin, 'Sleva', $now)` on a contract previously at `null` results in: `contract.individualMonthlyAmount=50_000`, AND one new `ContractPriceChange` row with `previousAmount=NULL, newAmount=50_000, changedBy=$admin, reason='Sleva'`.
- [ ] Calling it a second time with `(70_000, $admin2, 'Zvýšení', $later)` adds a second row with `previousAmount=50_000, newAmount=70_000`. `findByContractOrderedByDate` returns the two rows newest-first.
- [ ] Admin order-detail page (`/portal/admin/orders/{id}`) renders the "Historie ceny" panel for any contract with ≥1 price change. Format per row: `dd.mm.yyyy hh:mm · {email or 'systém'} · {old} → {new} ({reason})`. Verified manually in browser.
- [ ] Vanilla customer-created orders (no individual price ever set) render NO "Historie ceny" panel.
- [ ] Negative / over-cap arguments still throw the existing exceptions and DO NOT produce an audit row.

## Out of scope

- **A UI to edit `individualMonthlyAmount` after onboarding.** The audit foundation goes in first. The edit UI is a separate spec — when it ships, every save calls the same `applyIndividualMonthlyAmount` and is auditable for free.
- **Change history for `paidThroughDate`.** Different concern (external prepayment lifecycle, not pricing). Belongs in its own spec if needed.
- **Change history for storage `pricePerMonth` / storage-type defaults.** Different entity, different actors (landlord vs. admin), different audit needs.
- **Notification emails on price change.** No customer-visible notification, no admin CC. Audit-only.
- **Visibility on the customer-side portal.** The customer doesn't see the history of their own price changes — the value they pay shows on every invoice and the post-payment status page; the historical mutations are internal.
- **CSV / Excel export of price-change history.** Spec 028 covers list-page exports broadly; if needed later, the existing `ExcelExporter` works against `findByContractOrderedByDate(...)` with no further work.
- **Indexing on `changedBy`.** Leave the FK unindexed; queries are always per-contract, never per-user. Add later if a "all changes by user X" view ever lands.
- **Soft-delete / undo of a price change row.** Append-only — by design no edit, no delete. The way to "revert" is to issue a new change back to the previous value with `reason='Reverted'`.

## Open questions

None — proceed.

Resolved 2026-05-07:

1. **`reason` is optional (`?string`, default `null`).** Future edit UI may enforce non-null at the form layer; the entity stays flexible.
2. **Backfill existing contracts** per Requirement 7 (`previousAmount=NULL`, `reason='Initial value (backfill)'`).
3. **Always record an event** even when `previous === new`. Audit trail visibility wins over row-count parsimony; if no-op spam becomes real, add a `previous !== new` guard in the persist handler later.
