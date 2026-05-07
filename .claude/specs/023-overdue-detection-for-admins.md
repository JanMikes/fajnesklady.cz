# 023 — Po splatnosti detection for admins

**Status:** done
**Type:** feature (admin UX + financial visibility)
**Scope:** medium (~17 files: 1 service + 1 view-model + 1 enum + 1 Twig extension + repo additions on Contract & User + 4 controller edits + 5 template edits + 1 nav edit + tests + fixture top-up)
**Depends on:** none

## Problem

Detecting overdue customers ("po splatnosti" / "dlužníci") is core daily admin work — failed recurring charges, unpaid debts after termination — but the surface is invisible today. The plumbing exists: `ContractRepository::findWithPaymentIssues()` already encodes the right definition, and `Admin\AdminPaymentIssuesController` renders a basic list at `/portal/admin/payment-issues`. **But the page is unlinked from the admin nav** (`templates/portal/layout.html.twig:93-141` — no entry between Audit log and Email log), the dashboard surfaces nothing, and admins can't spot a debtor while browsing the user list or admin orders list. There is no derived "days overdue" or "amount overdue" — the admin has to eyeball `failedBillingAttempts` + `lastBillingFailedAt` + `outstandingDebtAmount` and compute it mentally on every visit.

## Goal

Daily admin workflow becomes trivial:

1. **Land on `/portal/dashboard`** → red "Po splatnosti" tile with **count + total Kč overdue** + a top-5 most-overdue debtors quick-list. If zero, a green "Žádné platby po splatnosti" tile.
2. **Click → `/portal/admin/po-splatnosti`** (renamed from `payment-issues`): single sortable table with severity-coloured rows. Columns: zákazník · sklad/pobočka · důvod · po splatnosti (X dní) · částka · akce. Default sort = severity DESC, then days DESC.
3. **Browse `/portal/users`** → debtor users get a red "Dlužník" badge in the Stav column. Filter strip above the table: `Vše` / `Pouze dlužníci (N)`.
4. **Browse `/portal/admin/orders`** → debtor's name cell carries a small "Dlužník" badge. The detail page (`/portal/admin/orders/{id}`) shows a red banner above the content when the customer has any overdue contract, with a link to the overdue page.
5. **Admin nav** → a permanent "Po splatnosti" entry with a live count badge (red pill, only rendered when count > 0).

## Context (current state)

### Dormant infrastructure already in place

- **Route + controller**: `src/Controller/Admin/AdminPaymentIssuesController.php` at `/portal/admin/payment-issues` (route name `admin_payment_issues`). Functional. **Unlinked from admin nav** — verified by grep across `templates/`.
- **Repository query**: `ContractRepository::findWithPaymentIssues(\DateTimeImmutable $now)` (`src/Repository/ContractRepository.php:330`). Already encodes the canonical definition we want to keep:
  ```dql
  (c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR
      (c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :now-1day)))
  OR (c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)
  ```
- **Aggregate queries**: `ContractRepository::sumOutstandingDebt()` and `countWithOutstandingDebt()` — only cover the **terminated** branch. We will add the active-branch counterparts in req. 3.
- **Existing template**: `templates/admin/payment_issues.html.twig` — table with columns Customer / Storage / Place / Failed attempts / Debt / Status. Will be replaced.

### Anchor data on `Contract`

- `nextBillingDate` (`src/Entity/Contract.php:36`) — when the next recurring charge should hit. Anchor for active failing contracts.
- `failedBillingAttempts` (`Contract.php:42`) + `lastBillingFailedAt` (`Contract.php:45`).
- `outstandingDebtAmount` (`Contract.php:30`) + `terminatedAt` (`Contract.php:24`). Anchor for terminated debt = `terminatedAt`.
- `Contract.order.firstPaymentPrice` — locked-in monthly rate in halíře (after rename in spec 014/021).

### What's missing

- A derived view-model surfacing `daysOverdue`, `overdueAmount`, `severity`, `reasonLabel` per row.
- A way to ask "is THIS user a debtor?" / "which of THIS page's users are debtors?" without N+1.
- Admin-nav entry + Twig-side count badge.
- Dashboard tile + top-5 preview.
- Term consistency (everywhere we say "Po splatnosti" / "Dlužník", with full Czech diacritics — per memory rule).

### Existing conventions worth mirroring

- Single-action controllers (`final` + `__invoke`); route at class level — see `AdminPaymentIssuesController.php:14-16`.
- `final readonly` for DTOs and value objects — see `src/Value/PaymentSchedule.php`.
- Service co-location: subject-specific services live in `src/Service/<Subject>/` (see `src/Service/Order/`).
- Repositories use `EntityManager` composition; never `flush()`.
- `ClockInterface` injected, `MockClock` in tests pinned at `2025-06-15 12:00:00 UTC`.
- Filter strip pattern on lists: simple anchor buttons toggling query params (no form). The codebase has none yet — define the pattern here.

### Fresh-start note

User confirmed `db:reset` is the deploy path; **no migration, no 301 redirect** required. Old route `/portal/admin/payment-issues` and old name `admin_payment_issues` are deleted outright, not redirected.

## Architecture

```
                        ┌─────────────────────────────────────────┐
                        │  ContractRepository                     │
                        │  - findWithPaymentIssues  (existing)    │
                        │  + countOverdueContracts        (NEW)   │
                        │  + sumOverdueAmount             (NEW)   │
                        │  + findOverdueUserIds(?subset)  (NEW)   │
                        └─────────────────────────────────────────┘
                                          ▲
                                          │
                        ┌─────────────────────────────────────────┐
                        │  src/Service/Overdue/OverdueChecker     │
                        │  + findOverdueViews(now): View[]        │
                        │  + summarise(now): OverdueSummary       │
                        │  + filterOverdueUserIds(now, Uuid[])    │
                        │      → string[] (RFC-4122)              │
                        └─────────────────────────────────────────┘
                                          ▲
        ┌─────────────────────────────────┼──────────────────────────────────┐
        │                       │         │           │                      │
        ▼                       ▼         ▼           ▼                      ▼
  AdminOverdueCtrl    UserListController  AdminOrder  AdminOrderDetail   GetDashboard
  (renamed page)      (filter + badges)   ListCtrl    (warning banner)   StatsQuery
                                          (badges)                       (extended)
                                          
                                          App\Twig\OverdueExtension
                                          { overdue_count() } — admin-nav badge
```

## Requirements

### 1. Value objects

#### `src/Service/Overdue/OverdueSeverity.php`

```php
namespace App\Service\Overdue;

enum OverdueSeverity: string
{
    case WARNING  = 'warning';   // active contract, nextBillingDate < now-1d, no failure yet (cron-drift bucket)
    case ERROR    = 'error';     // active contract, failedBillingAttempts >= 1
    case CRITICAL = 'critical';  // contract terminated with outstandingDebtAmount > 0

    public function badgeClass(): string
    {
        return match ($this) {
            self::WARNING  => 'badge-warning',
            self::ERROR    => 'badge-error',
            self::CRITICAL => 'badge-error', // critical also red, distinguished by darker row + "ukončeno" reason
        };
    }

    public function rowClass(): string
    {
        return match ($this) {
            self::WARNING  => '',
            self::ERROR    => 'bg-red-50',
            self::CRITICAL => 'bg-red-100',
        };
    }

    public function sortRank(): int
    {
        return match ($this) {
            self::CRITICAL => 3,
            self::ERROR    => 2,
            self::WARNING  => 1,
        };
    }
}
```

#### `src/Service/Overdue/OverdueContractView.php`

```php
namespace App\Service\Overdue;

use App\Entity\Contract;

final readonly class OverdueContractView
{
    public function __construct(
        public Contract $contract,
        public int $daysOverdue,                 // >= 1
        public int $overdueAmount,               // halíře
        public OverdueSeverity $severity,
        public string $reasonLabel,              // Czech, full diacritics — see req. 2
        public \DateTimeImmutable $anchorDate,   // for sort/display
    ) {}

    public function getOverdueAmountInCzk(): float
    {
        return $this->overdueAmount / 100;
    }
}
```

#### `src/Service/Overdue/OverdueSummary.php`

```php
namespace App\Service\Overdue;

final readonly class OverdueSummary
{
    public function __construct(
        public int $count,
        public int $totalAmount, // halíře
        /** @var OverdueContractView[] up to 5 highest-severity, then highest-days */
        public array $top,
    ) {}
}
```

### 2. `OverdueChecker` service

`src/Service/Overdue/OverdueChecker.php`:

```php
namespace App\Service\Overdue;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use Symfony\Component\Uid\Uuid;

readonly class OverdueChecker
{
    public function __construct(
        private ContractRepository $contractRepository,
    ) {}

    /** @return OverdueContractView[] sorted: severity DESC, daysOverdue DESC */
    public function findOverdueViews(\DateTimeImmutable $now): array
    {
        $contracts = $this->contractRepository->findWithPaymentIssues($now);
        $views = array_map(fn (Contract $c): OverdueContractView => $this->buildView($c, $now), $contracts);

        usort($views, function (OverdueContractView $a, OverdueContractView $b): int {
            $bySeverity = $b->severity->sortRank() <=> $a->severity->sortRank();
            return 0 !== $bySeverity ? $bySeverity : ($b->daysOverdue <=> $a->daysOverdue);
        });

        return $views;
    }

    public function summarise(\DateTimeImmutable $now): OverdueSummary
    {
        $views = $this->findOverdueViews($now);
        $totalAmount = array_sum(array_map(fn (OverdueContractView $v) => $v->overdueAmount, $views));

        return new OverdueSummary(
            count: count($views),
            totalAmount: $totalAmount,
            top: array_slice($views, 0, 5),
        );
    }

    /**
     * Subset of given user IDs that currently have ≥1 overdue contract.
     *
     * @param Uuid[] $userIds
     * @return string[] RFC-4122 strings — for cheap template membership tests
     *                  via array_flip + isset(..)
     */
    public function filterOverdueUserIds(\DateTimeImmutable $now, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        return $this->contractRepository->findOverdueUserIds($now, $userIds);
    }

    private function buildView(Contract $contract, \DateTimeImmutable $now): OverdueContractView
    {
        // CRITICAL: terminated with debt
        if (null !== $contract->terminatedAt && null !== $contract->outstandingDebtAmount && $contract->outstandingDebtAmount > 0) {
            $anchor = $contract->terminatedAt;
            return new OverdueContractView(
                contract: $contract,
                daysOverdue: max(1, (int) $anchor->diff($now)->days),
                overdueAmount: $contract->outstandingDebtAmount,
                severity: OverdueSeverity::CRITICAL,
                reasonLabel: 'Dluh — smlouva ukončena',
                anchorDate: $anchor,
            );
        }

        // ERROR or WARNING: active failing or cron-drift
        $anchor = $contract->nextBillingDate ?? $now;
        $monthlyRate = $contract->order->firstPaymentPrice;
        $attempts = $contract->failedBillingAttempts;

        if ($attempts >= 1) {
            return new OverdueContractView(
                contract: $contract,
                daysOverdue: max(1, (int) $anchor->diff($now)->days),
                overdueAmount: $monthlyRate, // one period unpaid; retries don't accrue new periods
                severity: OverdueSeverity::ERROR,
                reasonLabel: sprintf('Selhání platby (%d×)', $attempts),
                anchorDate: $anchor,
            );
        }

        return new OverdueContractView(
            contract: $contract,
            daysOverdue: max(1, (int) $anchor->diff($now)->days),
            overdueAmount: $monthlyRate,
            severity: OverdueSeverity::WARNING,
            reasonLabel: 'Strhnutí splatné',
            anchorDate: $anchor,
        );
    }
}
```

**Note on `overdueAmount` for active-failing contracts.** Set to one monthly rate, regardless of `failedBillingAttempts`. GoPay retries don't accrue a new period — the same charge is being retried. Showing `monthlyRate × attempts` would lie. The `reasonLabel` separately surfaces the attempt count.

### 3. `ContractRepository` additions

Add to `src/Repository/ContractRepository.php` after `countWithOutstandingDebt()` (line 394):

```php
public function countOverdueContracts(\DateTimeImmutable $now): int
{
    return (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(c.id)')
        ->from(Contract::class, 'c')
        ->where(
            '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
            .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
            .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
        )
        ->setParameter('overdueThreshold', $now->modify('-1 day'))
        ->getQuery()
        ->getSingleScalarResult();
}

public function sumOverdueAmount(\DateTimeImmutable $now): int
{
    // SUM = outstandingDebt for terminated, else order.firstPaymentPrice (one unpaid month).
    $result = $this->entityManager->createQueryBuilder()
        ->select(
            'SUM(CASE WHEN c.terminatedAt IS NOT NULL AND c.outstandingDebtAmount > 0 '
            .'THEN c.outstandingDebtAmount ELSE o.firstPaymentPrice END)'
        )
        ->from(Contract::class, 'c')
        ->join('c.order', 'o')
        ->where(
            '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
            .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
            .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
        )
        ->setParameter('overdueThreshold', $now->modify('-1 day'))
        ->getQuery()
        ->getSingleScalarResult();

    return (int) ($result ?? 0);
}

/**
 * Returns RFC-4122 user UUID strings of users who have ≥1 overdue contract.
 *
 * @param Uuid[]|null $restrictToUserIds  When non-null, the result is the
 *                                        intersection of overdue users and
 *                                        this list — used by paginated lists
 *                                        to badge only the visible page's users.
 * @return string[]
 */
public function findOverdueUserIds(\DateTimeImmutable $now, ?array $restrictToUserIds = null): array
{
    if (null !== $restrictToUserIds && [] === $restrictToUserIds) {
        return [];
    }

    $qb = $this->entityManager->createQueryBuilder()
        ->select('DISTINCT IDENTITY(c.user) AS userId')
        ->from(Contract::class, 'c')
        ->where(
            '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
            .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
            .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
        )
        ->setParameter('overdueThreshold', $now->modify('-1 day'));

    if (null !== $restrictToUserIds) {
        $qb->andWhere('c.user IN (:ids)')->setParameter('ids', $restrictToUserIds);
    }

    /** @var array<int, array{userId: string}> $rows */
    $rows = $qb->getQuery()->getArrayResult();

    return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
}
```

### 4. `UserRepository` additions for the filter

Add to `src/Repository/UserRepository.php`:

```php
/** @return User[] */
public function findOverduePaginated(int $page, int $limit, \DateTimeImmutable $now): array
{
    $offset = ($page - 1) * $limit;

    return $this->entityManager->createQueryBuilder()
        ->select('u')
        ->from(User::class, 'u')
        ->where('u.id IN (:overdueIds)')
        ->setParameter('overdueIds', $this->overdueUserIdsSubquery($now))
        ->orderBy('u.createdAt', 'DESC')
        ->addOrderBy('u.id', 'DESC')
        ->setFirstResult($offset)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

public function countOverdueUsers(\DateTimeImmutable $now): int
{
    return (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(u.id)')
        ->from(User::class, 'u')
        ->where('u.id IN (:overdueIds)')
        ->setParameter('overdueIds', $this->overdueUserIdsSubquery($now))
        ->getQuery()
        ->getSingleScalarResult();
}

/** @return string[] RFC-4122 user UUID strings */
private function overdueUserIdsSubquery(\DateTimeImmutable $now): array
{
    /** @var array<int, array{userId: string}> $rows */
    $rows = $this->entityManager->createQueryBuilder()
        ->select('DISTINCT IDENTITY(c.user) AS userId')
        ->from(\App\Entity\Contract::class, 'c')
        ->where(
            '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
            .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
            .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
        )
        ->setParameter('overdueThreshold', $now->modify('-1 day'))
        ->getQuery()
        ->getArrayResult();

    if ([] === $rows) {
        // returning [] in `IN (:overdueIds)` would error at DBAL — return a sentinel UUID instead
        return ['00000000-0000-0000-0000-000000000000'];
    }

    return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
}
```

The duplication of the WHERE clause across `ContractRepository` and the private `UserRepository` helper is intentional and bounded. Extracting a shared "overdue criteria builder" is a cosmetic refactor we don't take on here.

### 5. Rename payment-issues → po-splatnosti

#### Delete

- `src/Controller/Admin/AdminPaymentIssuesController.php`
- `templates/admin/payment_issues.html.twig`

#### Create `src/Controller/Admin/AdminOverdueController.php`

```php
namespace App\Controller\Admin;

use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/po-splatnosti', name: 'admin_overdue')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOverdueController extends AbstractController
{
    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(): Response
    {
        $now = $this->clock->now();
        $views = $this->overdueChecker->findOverdueViews($now);
        $summary = $this->overdueChecker->summarise($now);

        return $this->render('admin/overdue/list.html.twig', [
            'views' => $views,
            'summary' => $summary,
        ]);
    }
}
```

#### Create `templates/admin/overdue/list.html.twig`

Tailwind + DaisyUI, mirroring `payment_issues.html.twig` structure but redesigned:

```twig
{% extends 'portal/layout.html.twig' %}

{% block title %}Po splatnosti — Admin{% endblock %}

{% block content %}
    <h1 class="text-3xl font-bold text-gray-900 mb-6">Po splatnosti</h1>

    {% if summary.count == 0 %}
        <div class="card bg-green-50 border-green-200">
            <div class="card-body text-center">
                <p class="text-green-700 text-lg">Žádné platby po splatnosti.</p>
            </div>
        </div>
    {% else %}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="card bg-red-50 border-red-300">
                <div class="card-body">
                    <h2 class="text-sm font-medium text-red-700 uppercase">Celková částka po splatnosti</h2>
                    <p class="text-3xl font-bold text-red-700">
                        {{ (summary.totalAmount / 100)|number_format(0, ',', ' ') }} Kč
                    </p>
                </div>
            </div>
            <div class="card bg-red-50 border-red-300">
                <div class="card-body">
                    <h2 class="text-sm font-medium text-red-700 uppercase">Smluv po splatnosti</h2>
                    <p class="text-3xl font-bold text-red-700">{{ summary.count }}</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>Zákazník</th>
                            <th>Sklad</th>
                            <th>Důvod</th>
                            <th>Po splatnosti</th>
                            <th>Částka</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for view in views %}
                            <tr class="{{ view.severity.rowClass }}">
                                <td>
                                    <a href="{{ path('portal_users_view', {id: view.contract.user.id}) }}" class="link font-semibold">
                                        {{ view.contract.user.fullName }}
                                    </a>
                                    <div class="text-sm text-gray-500">{{ view.contract.user.email }}</div>
                                    {% if view.contract.user.phone %}
                                        <div class="text-sm text-gray-500">{{ view.contract.user.phone }}</div>
                                    {% endif %}
                                </td>
                                <td>
                                    <div>{{ view.contract.storage.storageType.name }} #{{ view.contract.storage.number }}</div>
                                    <div class="text-sm text-gray-500">{{ view.contract.storage.place.name }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ view.severity.badgeClass }}">{{ view.reasonLabel }}</span>
                                </td>
                                <td>
                                    <div class="font-bold">{{ view.daysOverdue }} {{ view.daysOverdue == 1 ? 'den' : (view.daysOverdue < 5 ? 'dny' : 'dní') }}</div>
                                    <div class="text-xs text-gray-500">od {{ view.anchorDate|date('d.m.Y') }}</div>
                                </td>
                                <td>
                                    <span class="text-red-700 font-bold">{{ (view.overdueAmount / 100)|number_format(0, ',', ' ') }} Kč</span>
                                </td>
                                <td>
                                    <a href="{{ path('admin_order_detail', {id: view.contract.order.id}) }}" class="link text-sm">Detail objednávky</a>
                                </td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

The list is pre-sorted by the service. **No client-side sortable headers in v1** — the user said "sort by days/amount" is implicit in the dashboard tile and the service-side sort. Adding header click-to-sort is a separate UX increment; out of scope.

### 6. Admin nav entry with count badge

#### `src/Twig/OverdueExtension.php` (new)

```php
namespace App\Twig;

use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OverdueExtension extends AbstractExtension
{
    private ?int $cachedCount = null;

    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {}

    /** @return TwigFunction[] */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('overdue_count', $this->overdueCount(...)),
        ];
    }

    public function overdueCount(): int
    {
        return $this->cachedCount ??= $this->overdueChecker->summarise($this->clock->now())->count;
    }
}
```

The `?int` memoization keeps the badge cheap — the layout is rendered once per request and the function is called twice (sidebar + mobile menu, see layout.html.twig:93-141 vs :276-340).

Service registration is auto-wired by `services.yaml`'s `App\:` glob (verify by running `composer quality`); no manual tag needed.

#### `templates/portal/layout.html.twig` — add nav entry

In the admin `{% if is_granted('ROLE_ADMIN') %}` block (around line 122, between Audit log and Email log), add a new entry. Same edit must be repeated in the mobile-menu admin block around line 305. Snippet:

```twig
<a href="{{ path('admin_overdue') }}" class="{% if app.request.attributes.get('_route') == 'admin_overdue' %}bg-gray-900 text-white{% else %}text-gray-300 hover:bg-gray-700 hover:text-white{% endif %} group flex items-center px-3 py-2 text-sm font-medium rounded-md">
    <svg class="text-gray-400 mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
    <span>Po splatnosti</span>
    {% set count = overdue_count() %}
    {% if count > 0 %}
        <span class="ml-auto inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-4 bg-red-600 text-white rounded-full">
            {{ count }}
        </span>
    {% endif %}
</a>
```

The triangular alert icon mirrors the existing "Neověření uživatelé" tile icon used elsewhere — visually distinct from the "Audit log" / "Email log" lock/mail icons.

### 7. Dashboard tile + top-5 preview

#### Extend `src/Query/GetDashboardStatsResult.php`

Add three fields:

```php
public int $overdueCount,
public int $overdueAmount,
/** @var \App\Service\Overdue\OverdueContractView[] */ public array $overdueTop,
```

#### Extend `src/Query/GetDashboardStatsQuery.php` (the handler)

Inject `OverdueChecker`, after the existing `$activeRecurringContracts = ...` line, call:

```php
$overdueSummary = $this->overdueChecker->summarise($now);
```

and pass `overdueCount: $overdueSummary->count`, `overdueAmount: $overdueSummary->totalAmount`, `overdueTop: $overdueSummary->top` into the result constructor.

#### `templates/portal/dashboard_admin.html.twig` — tile + preview

Insert a new tile in the **first** stats row (currently 4 cards: lastMonthRevenue / lastMonthCommission / expectedThisMonthRevenue / occupancy). Replace the 4-col grid with **a 5-col grid on lg** (or place the new tile between revenue and occupancy as a full row's first card) — implementer's UI judgement, but the spec requires:

- Always rendered (even at 0).
- Red theme when `stats.overdueCount > 0`; green theme + "Žádné platby po splatnosti" when 0.
- Linked: clicking the tile goes to `path('admin_overdue')`.
- Body shows count as the headline + total Kč underneath.

Then add a new section **above Quick Actions**, only when `stats.overdueCount > 0`:

```twig
<div class="card bg-white shadow-md mb-8">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-semibold text-gray-900">
                Nejvíc po splatnosti
            </h2>
            <a href="{{ path('admin_overdue') }}" class="link text-sm">Zobrazit všechny ({{ stats.overdueCount }})</a>
        </div>
        <ul class="divide-y divide-gray-200">
            {% for view in stats.overdueTop %}
                <li class="py-3 flex items-center justify-between">
                    <div>
                        <a href="{{ path('admin_order_detail', {id: view.contract.order.id}) }}" class="font-semibold link">
                            {{ view.contract.user.fullName }}
                        </a>
                        <div class="text-sm text-gray-500">
                            {{ view.reasonLabel }} · {{ view.daysOverdue }} {{ view.daysOverdue == 1 ? 'den' : (view.daysOverdue < 5 ? 'dny' : 'dní') }}
                        </div>
                    </div>
                    <div class="text-red-700 font-bold">
                        {{ (view.overdueAmount / 100)|number_format(0, ',', ' ') }} Kč
                    </div>
                </li>
            {% endfor %}
        </ul>
    </div>
</div>
```

### 8. User list filter + "Dlužník" badge

#### `src/Controller/Portal/UserListController.php`

```php
public function __construct(
    private readonly UserRepository $userRepository,
    private readonly OverdueChecker $overdueChecker,
    private readonly ClockInterface $clock,
) {}

public function __invoke(Request $request): Response
{
    $page = max(1, (int) $request->query->get('page', '1'));
    $limit = 20;
    $filter = 'overdue' === $request->query->get('filter') ? 'overdue' : null;
    $now = $this->clock->now();

    if ('overdue' === $filter) {
        $users = $this->userRepository->findOverduePaginated($page, $limit, $now);
        $totalUsers = $this->userRepository->countOverdueUsers($now);
    } else {
        $users = $this->userRepository->findAllPaginated($page, $limit);
        $totalUsers = $this->userRepository->countTotal();
    }

    $totalPages = (int) ceil($totalUsers / $limit);
    $overdueUserCount = $this->userRepository->countOverdueUsers($now);

    $pageUserIds = array_map(static fn ($u) => $u->id, $users);
    $debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($now, $pageUserIds));

    return $this->render('portal/user/list.html.twig', [
        'users' => $users,
        'currentPage' => $page,
        'totalPages' => $totalPages,
        'totalUsers' => $totalUsers,
        'filter' => $filter,
        'overdueUserCount' => $overdueUserCount,
        'debtorIdSet' => $debtorIdSet, // [rfc4122 => 0]
    ]);
}
```

#### `templates/portal/user/list.html.twig`

Above the `<div class="card">` (before the table), insert a filter strip:

```twig
<div class="mb-4 flex items-center gap-2">
    <a href="{{ path('portal_users_list') }}" class="btn btn-sm {{ filter ? 'btn-ghost' : 'btn-primary' }}">Vše</a>
    <a href="{{ path('portal_users_list', {filter: 'overdue'}) }}" class="btn btn-sm {{ filter == 'overdue' ? 'btn-error' : 'btn-ghost' }}">
        Pouze dlužníci ({{ overdueUserCount }})
    </a>
</div>
```

Inside the Stav column (around line 46-58, after the existing verified/deactivated badges), append:

```twig
{% if debtorIdSet[user.id.toRfc4122()] is defined %}
    <span class="badge badge-error" title="Smlouva po splatnosti">
        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l6.518 11.59c.75 1.335-.213 2.98-1.742 2.98H3.481c-1.53 0-2.493-1.645-1.743-2.98L8.257 3.099zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
        </svg>
        Dlužník
    </span>
{% endif %}
```

Update the colspan in the empty-row fallback if needed (still `6` — column count unchanged).

The pagination component takes `route` — when `filter=overdue`, the pagination must preserve the filter:

```twig
{% include 'components/pagination.html.twig' with {
    currentPage: currentPage,
    totalPages: totalPages,
    totalItems: totalUsers,
    itemLabel: 'uživatelů',
    route: 'portal_users_list',
    routeParams: filter ? {filter: filter} : {},
} %}
```

(Verify `components/pagination.html.twig` accepts `routeParams` — if not, extend it. Quick check during implementation.)

### 9. Admin orders list: badge on user-name cell

#### `src/Controller/Admin/AdminOrderListController.php`

Add `OverdueChecker` and `ClockInterface` to the constructor. After fetching `$orders`, compute:

```php
$pageUserIds = array_map(static fn ($o) => $o->user->id, $orders);
$debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($this->clock->now(), $pageUserIds));
```

Pass `debtorIdSet` to the template render call.

#### `templates/admin/order/list.html.twig`

In the user cell (line 30-35), append:

```twig
{% if debtorIdSet[order.user.id.toRfc4122()] is defined %}
    <span class="badge badge-error badge-sm" title="Zákazník má platbu po splatnosti">Dlužník</span>
{% endif %}
```

(Place inline on the same row as the email line, or right after the user-name link — implementer's call, but make sure it's visible at a glance.)

### 10. Admin order detail: warning banner

#### `src/Controller/Portal/Admin/AdminOrderDetailController.php`

Inject `OverdueChecker` + `ClockInterface`. Compute once:

```php
$isUserOverdue = [] !== $this->overdueChecker->filterOverdueUserIds(
    $this->clock->now(),
    [$order->user->id],
);
```

Pass `isUserOverdue` to the render context.

#### `templates/admin/order/detail.html.twig`

Just below the header (after the closing `</div>` of the flex row at line 51 or thereabouts), insert:

```twig
{% if isUserOverdue %}
    <div class="alert alert-error mb-6 flex items-start gap-3">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l6.518 11.59c.75 1.335-.213 2.98-1.742 2.98H3.481c-1.53 0-2.493-1.645-1.743-2.98L8.257 3.099zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
        </svg>
        <div>
            <strong>Tento zákazník má platbu po splatnosti.</strong>
            <a href="{{ path('admin_overdue') }}" class="link ml-2">Zobrazit přehled po splatnosti</a>
        </div>
    </div>
{% endif %}
```

### 11. Fixtures top-up

`src/DataFixtures/ContractFixtures.php` (or wherever the fixture for `tenant@example.com`'s contract lives) — extend so the dev DB has at least:

- One ACTIVE contract with `failedBillingAttempts = 2`, `lastBillingFailedAt = now-3d`, `nextBillingDate = now-5d`. Use one of the existing tenant fixtures; pick the user who is **not** `landlord@` or `admin@` (probably `tenant@example.com`'s primary contract).
- One TERMINATED contract with `terminatedAt = now-20d`, `outstandingDebtAmount = 350000` (3 500 Kč), `terminationReason = TerminationReason::PAYMENT_FAILURE`. New fixture row with a fresh user (`debtor@example.com` / `password`) is acceptable, or reuse `landlord2@example.com` if the fixture has spare slots.

Without these the dashboard tile and the page show empty in dev — bad for manual verification. **Don't** flush the existing healthy contracts; the goal is mixed state.

If the implementer judges adding a new fixture user (`debtor@example.com`) cleaner than mutating an existing one, that's fine — call it out in the PR description.

### 12. Tests

#### Unit — `tests/Unit/Service/Overdue/OverdueCheckerTest.php`

Use `MockClock` (`2025-06-15 12:00:00 UTC`) and raw `Contract` construction (or test-only factory):

| Scenario | severity | daysOverdue | overdueAmount | reasonLabel |
|---|---|---|---|---|
| Active, attempts=0, nextBillingDate=now-3d | WARNING | 3 | monthlyRate | "Strhnutí splatné" |
| Active, attempts=1, nextBillingDate=now-5d, lastFail=now-3d | ERROR | 5 | monthlyRate | "Selhání platby (1×)" |
| Active, attempts=2, nextBillingDate=now-12d, lastFail=now-2d | ERROR | 12 | monthlyRate | "Selhání platby (2×)" |
| Terminated, outstandingDebtAmount=350000, terminatedAt=now-15d | CRITICAL | 15 | 350000 | "Dluh — smlouva ukončena" |

Plus a sort test: a list of mixed-severity views comes back ordered CRITICAL → ERROR → WARNING, then daysOverdue DESC within each.

#### Integration — `tests/Integration/Repository/ContractRepositoryTest.php`

Cases for `countOverdueContracts`, `sumOverdueAmount`, `findOverdueUserIds(now)` and `findOverdueUserIds(now, [userId])`. Build via fixtures + manual entity manipulation; assert via `assertSame` and `assertContains`.

#### Integration — `tests/Integration/Repository/UserRepositoryTest.php`

`findOverduePaginated` returns only debtor users; `countOverdueUsers` matches.

#### Integration — `tests/Integration/Controller/Admin/AdminOverdueControllerTest.php`

GET `/portal/admin/po-splatnosti` as admin: 200, response body contains "Po splatnosti", contains the debtor's `fullName`, contains the per-row CTA "Detail objednávky".

GET as non-admin (landlord, user): 403 (or redirect to login per `IsGranted` behaviour).

#### Integration — `tests/Integration/Controller/Portal/UserListControllerTest.php`

GET `?filter=overdue` returns only debtor users in the rendered HTML (assert that the non-debtor fixture user is **absent** from the response and the debtor is present).

GET without filter: response body contains the "Dlužník" badge text exactly once per debtor on the page.

#### Manual walk-through (Czech, full diacritics — per memory rule)

After `docker compose exec web composer db:reset`:

- Login `admin@example.com`. Dashboard shows "Po splatnosti" tile with non-zero count and total Kč. Top-5 preview lists ≥2 entries. Click tile → lands on `/portal/admin/po-splatnosti`. Page shows summary cards + table sorted CRITICAL first, ERROR second, WARNING third (if any).
- Admin nav (sidebar + mobile menu): "Po splatnosti" entry visible with red count badge equal to the page row count.
- `/portal/users` — debtor user has the "Dlužník" red badge in Stav column. Click "Pouze dlužníci (N)" → list filters to debtors only; the non-debtor admin/landlord users are hidden.
- `/portal/admin/orders` — debtor's row shows "Dlužník" mini-badge in the user cell. Other rows don't.
- `/portal/admin/orders/{debtorOrderId}` — red banner above the content with link "Zobrazit přehled po splatnosti".
- Login `landlord@example.com`. None of the new admin surfaces are reachable; landlord nav unchanged.

## Acceptance

- [ ] `docker compose exec web composer quality` green.
- [ ] Manual walk-through above passes for `admin@`, `landlord@`, `tenant@` / `user@`.
- [ ] No occurrence of `Problémové platby` or `payment-issues` in any rendered output (template / route / link). Grep `templates/` and `src/` for both.
- [ ] Admin-nav badge count equals the row count on the overdue page for any single page load.
- [ ] User-list filter `?filter=overdue` returns the same set as `OverdueChecker::filterOverdueUserIds($now, allUserIds)` for the same `$now`.
- [ ] `BACKLOG.md` row added: `023` `Po splatnosti — admin overdue detection (dashboard tile + nav badge + dedicated page rename + user-list filter + dlužník badge across user list / admin orders list / admin order detail)`, status `ready`, link to `023-overdue-detection-for-admins.md`.

## Out of scope

- **Landlord visibility.** Landlords don't chase debts — settled revenue flows through self-billing on cleared payments only. Single-role v1.
- **Daily admin email digest.** User explicitly deferred ("we can add later").
- **Order-list `?filter=overdue`.** The financial state lives at user/contract grain, not per-order. The dedicated page covers that need; orders list gets a row-level badge only.
- **Including `AWAITING_PAYMENT` orders past `expiresAt` as overdue.** Cron-driven auto-expire path; cron drift is an ops issue, not a debtor issue.
- **301 redirect from old `/portal/admin/payment-issues`.** User confirmed fresh start, no migration.
- **Sortable column headers on the overdue page.** Service-side sort gives the right default ordering; click-to-resort is a separate UX increment.
- **Customer-facing "you're overdue" surfaces.** Customer already gets payment-default emails (`templates/email/payment_default_*.html.twig`). This spec is admin-side only.
- **Tracking "days since last admin acknowledgement / contact".** No data model for it; would require a new entity. Out of scope.
- **Refactoring the duplicated overdue-criteria WHERE clause** across `ContractRepository` and the private helper in `UserRepository`. Bounded duplication; cosmetic refactor.
- **Replacing `Order.firstPaymentPrice` lookup with current `Storage.pricePerMonth`.** The locked-in monthly rate is the right anchor for "amount overdue" (see spec 021's reasoning).

## Open questions

None — proceed.
