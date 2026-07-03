# Test Fixtures Documentation

This document describes the fixture data available for integration tests.

## Mock Clock

Tests use a fixed time: **2025-06-15 12:00:00 UTC**

All fixture data and date calculations are relative to this time.

## Fixture Load Order

Core chain (each depends on the previous):

```
UserFixtures
    ↓
PlaceFixtures
    ↓
StorageTypeFixtures
    ↓
StorageFixtures
    ↓
OrderFixtures
    ↓
ContractFixtures
```

Additional classes load after their dependencies (Doctrine resolves the order):
`StorageUnavailabilityFixtures`, `InvoiceFixtures`, `OnboardingFixtures`,
`HandoverProtocolFixtures`, `PlaceAccessFixtures`, `PlaceAccessRequestFixtures`,
`PlaceStorageCodeUsageFixtures`, `StorageTypePhotoFixtures`,
`StoragePhotoFixtures`, `EmailLogFixtures`.

## Users

| Constant | Email | Role | Verified | Purpose |
|----------|-------|------|----------|---------|
| `UserFixtures::REF_USER` | user@example.com | USER | Yes | Basic tenant |
| `UserFixtures::REF_UNVERIFIED` | unverified@example.com | USER | No | Auth tests |
| `UserFixtures::REF_LANDLORD` | landlord@example.com | LANDLORD | Yes | Primary landlord |
| `UserFixtures::REF_LANDLORD2` | landlord2@example.com | LANDLORD | Yes | Isolation tests |
| `UserFixtures::REF_TENANT` | tenant@example.com | USER | Yes | Order tests |
| `UserFixtures::REF_ADMIN` | admin@example.com | ADMIN | Yes | Admin access |
| `UserFixtures::REF_DEACTIVATED` | deactivated@example.com | USER | Yes (deactivated) | Deactivation tests |

**Email constants:** `UserFixtures::USER_EMAIL`, `UserFixtures::LANDLORD_EMAIL`, etc.

## Places

| Constant | Owner | Name | Location |
|----------|-------|------|----------|
| `PlaceFixtures::REF_PRAHA_CENTRUM` | LANDLORD | Sklad Praha - Centrum | Praha 1 |
| `PlaceFixtures::REF_PRAHA_JIH` | LANDLORD | Sklad Praha - Jiznimesto | Praha 4 |
| `PlaceFixtures::REF_BRNO` | ADMIN | Sklad Brno | Brno |
| `PlaceFixtures::REF_OSTRAVA` | LANDLORD2 | Sklad Ostrava | Ostrava |
| `PlaceFixtures::REF_PLZEN` | - | Sklad Plzen | Plzen (no address, map-only) |

## Storage Types (per-Place)

Storage types belong to a specific Place. Four-tier pricing (spec 052):
weekly / monthly short-term / monthly long-term (6+ months & unlimited) / yearly.

### Praha Centrum
| Constant | Name | Dimensions | Weekly | Monthly | Long-term | Yearly | Notes |
|----------|------|------------|--------|---------|-----------|--------|-------|
| `StorageTypeFixtures::REF_SMALL_CENTRUM` | Maly box | 1m x 1m x 1m | 150 | 500 | 430 | 4 300 | |
| `StorageTypeFixtures::REF_MEDIUM_CENTRUM` | Stredni box | 2m x 2m x 2m | 350 | 1 200 | 1 020 | 10 200 | |
| `StorageTypeFixtures::REF_LARGE_CENTRUM` | Velky box | 3m x 2.5m x 4m | 800 | 2 800 | 2 380 | 23 800 | |
| `StorageTypeFixtures::REF_CUSTOM_CENTRUM` | Custom box | 2.5m x 2.2m x 3m | 400 | 1 400 | 1 190 | 11 900 | `uniformStorages=false` — per-storage price overrides |
| `StorageTypeFixtures::REF_ADMIN_ONLY_CENTRUM` | Admin box (skrytý) | 1.8m x 1.8m x 1.8m | 320 | 1 100 | 940 | 9 400 | **adminOnly=true** — hidden from all customer surfaces; admin-onboarding only |

### Praha Jih
| Constant | Name | Weekly | Monthly | Long-term | Yearly |
|----------|------|--------|---------|-----------|--------|
| `StorageTypeFixtures::REF_SMALL_JIH` | Maly box | 120 | 400 | 340 | 3 400 |
| `StorageTypeFixtures::REF_MEDIUM_JIH` | Stredni box | 300 | 1 000 | 850 | 8 500 |

### Brno
| Constant | Name | Weekly | Monthly | Long-term | Yearly |
|----------|------|--------|---------|-----------|--------|
| `StorageTypeFixtures::REF_PREMIUM_BRNO` | Premium box | 1 500 | 5 000 | 4 250 | 42 500 |

### Ostrava
| Constant | Name | Weekly | Monthly | Long-term | Yearly |
|----------|------|--------|---------|-----------|--------|
| `StorageTypeFixtures::REF_STANDARD_OSTRAVA` | Standardni box | 200 | 700 | 600 | 6 000 |

### Backward compatibility aliases
| Alias | Points to |
|-------|-----------|
| `StorageTypeFixtures::REF_SMALL` | `REF_SMALL_CENTRUM` |
| `StorageTypeFixtures::REF_MEDIUM` | `REF_MEDIUM_CENTRUM` |
| `StorageTypeFixtures::REF_LARGE` | `REF_LARGE_CENTRUM` |
| `StorageTypeFixtures::REF_PREMIUM` | `REF_PREMIUM_BRNO` |
| `StorageTypeFixtures::REF_STANDARD` | `REF_STANDARD_OSTRAVA` |
| `StorageTypeFixtures::REF_CUSTOM` | `REF_CUSTOM_CENTRUM` |

## Storages

State below is the effective booking state at MockClock time (spec 071 derives
availability from orders/contracts/unavailabilities, not the stored enum).

### Praha Centrum (owner: LANDLORD unless noted)
| Constant | Number | Type | State |
|----------|--------|------|-------|
| `StorageFixtures::REF_SMALL_A1` | A1 | Small | AVAILABLE — lock code `0042` |
| `StorageFixtures::REF_SMALL_A2` | A2 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_A3` | A3 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_A4` | A4 | Small | UNAVAILABLE (maintenance window -3 → +4 days) |
| `StorageFixtures::REF_SMALL_A5` | A5 | Small | AVAILABLE (past unavailability -14 → -7 days) |
| `StorageFixtures::REF_MEDIUM_B1` | B1 | Medium | RESERVED (order pending) |
| `StorageFixtures::REF_MEDIUM_B2` | B2 | Medium | RESERVED (order paid) |
| `StorageFixtures::REF_MEDIUM_B3` | B3 | Medium | OCCUPIED (active contract) |
| `StorageFixtures::REF_LARGE_C1` | C1 | Large | OCCUPIED (card-recurring contract, availability guarantee) — lock code `0577` |
| `StorageFixtures::REF_LARGE_C2` | C2 | Large | UNAVAILABLE (indefinite unavailability since -7 days) |
| `StorageFixtures::REF_CUSTOM_X1` | X1 | Custom | AVAILABLE — price override 350 Kč/week, 1 200 Kč/month, 1 020 Kč long-term; 2 unit photos |
| `StorageFixtures::REF_CUSTOM_X2` | X2 | Custom | OCCUPIED (upfront bank-transfer contract, spec 078) — price override 500 Kč/week, 1 800 Kč/month, 1 530 Kč long-term; 1 unit photo |
| `StorageFixtures::REF_CUSTOM_X3` | X3 | Custom | OCCUPIED (onboarding: external prepaid expired -10 days → Po splatnosti) — type default pricing, no unit photos (intentional) |
| `StorageFixtures::REF_SMALL_Z1_LANDLORD2` | Z1 | Small | AVAILABLE — **owned by LANDLORD2** (co-owner storage at landlord's place; drives the "Vidíte pouze své sklady" disclaimer) |
| `StorageFixtures::REF_ADMIN_ONLY_AO1` | AO1 | Admin box (skrytý) | AVAILABLE (admin-onboarding only; never publicly orderable) |

### Praha Jih (owner: LANDLORD)
| Constant | Number | Type | State |
|----------|--------|------|-------|
| `StorageFixtures::REF_SMALL_D1` | D1 | Small | AVAILABLE (cancelled order history) |
| `StorageFixtures::REF_SMALL_D2` | D2 | Small | AVAILABLE (expired order history) |
| `StorageFixtures::REF_SMALL_D3` | D3 | Small | OCCUPIED (contract expiring in +7 days) |
| `StorageFixtures::REF_MEDIUM_E1` | E1 | Medium | OCCUPIED (recurring contract with pending termination notice; also carries an older terminated contract with 3 500 Kč debt) |
| `StorageFixtures::REF_MEDIUM_E2` | E2 | Medium | OCCUPIED (onboarding: external prepaid ending in +5 days) |

### Brno (no owner — unassigned)
| Constant | Number | State |
|----------|--------|-------|
| `StorageFixtures::REF_PREMIUM_P1` | P1 | OCCUPIED (onboarding: individual price 800 Kč/month) |
| `StorageFixtures::REF_PREMIUM_P2` | P2 | OCCUPIED (onboarding: free contract, 0 Kč) |

### Ostrava (owner: LANDLORD2)
| Constant | Number | State |
|----------|--------|-------|
| `StorageFixtures::REF_STANDARD_O1` | O1 | AVAILABLE (future unavailability +14 → +21 days) |
| `StorageFixtures::REF_STANDARD_O2` | O2 | OCCUPIED (onboarding: external prepaid until +30 days — blue customer banner) |

## Orders

| Constant | User | Storage | Status | Period |
|----------|------|---------|--------|--------|
| `OrderFixtures::REF_ORDER_RESERVED` | TENANT | B1 | RESERVED | +7 → +37 days |
| `OrderFixtures::REF_ORDER_PAID` | TENANT | B2 | PAID | +14 → +44 days |
| `OrderFixtures::REF_ORDER_COMPLETED` | USER | B3 | COMPLETED | -1 → +29 days |
| `OrderFixtures::REF_ORDER_COMPLETED_RECURRING` | USER | C1 | COMPLETED | -30 → +700 days (card-recurring, live token) |
| `OrderFixtures::REF_ORDER_CANCELLED` | TENANT | D1 | CANCELLED | +7 → +37 days |
| `OrderFixtures::REF_ORDER_EXPIRED` | TENANT | D2 | EXPIRED | +7 → +37 days |
| `OrderFixtures::REF_ORDER_EXPIRING_SOON` | TENANT | D3 | COMPLETED | -23 → +7 days |
| `OrderFixtures::REF_ORDER_TERMINATING` | USER | E1 | COMPLETED | -60 → +305 days (monthly recurring; its contract carries a pending termination notice) |
| `OrderFixtures::REF_ORDER_COMPLETED_UPFRONT` | TENANT | X2 | COMPLETED | -30 → +92 days (bank transfer, `paymentFrequency = ONE_TIME`, `billingMode = ONE_TIME`, VS `7800000001`, `firstPaymentPrice` = whole rental total; spec 078) |

`OnboardingFixtures` creates five more completed admin-onboarding orders (no
reference constants) — see "Onboarding contracts" below.

## Contracts

| Constant | User | Storage | Status | End Date |
|----------|------|---------|--------|----------|
| `ContractFixtures::REF_CONTRACT_ACTIVE` | USER | B3 | Active, signed | +29 days |
| `ContractFixtures::REF_CONTRACT_RECURRING` | USER | C1 | Active, signed, live GoPay token (availability guarantee) | +700 days |
| `ContractFixtures::REF_CONTRACT_EXPIRING_7_DAYS` | TENANT | D3 | Active, signed | +7 days |
| `ContractFixtures::REF_CONTRACT_TERMINATED` | TENANT | E1 | Terminated -20 days (PAYMENT_FAILURE), outstanding debt 3 500 Kč → CRITICAL row on Po splatnosti | -30 days |
| `ContractFixtures::REF_CONTRACT_TERMINATING` | USER | E1 | Active, signed, termination requested -2 days with `terminatesAt` +30 days — drives "ukončuje se" warnings on planning surfaces | +305 days |
| `ContractFixtures::REF_CONTRACT_UPFRONT` | TENANT | X2 | Active, signed, whole rental prepaid upfront (`billingMode = ONE_TIME`, no `nextBillingDate`, no `paidThroughDate`; spec 078) | +92 days |

## Onboarding contracts (`OnboardingFixtures`, no reference constants)

All five: tenant = TENANT, created by ADMIN, signed, 24-month term. They seed
the admin order-list filter strip (spec 025) and customer billing-status
banners (spec 030):

| Storage | Started | Billing situation |
|---------|---------|-------------------|
| P1 (Premium Brno) | -60 days | Individual price 800 Kč/month (`individualMonthlyAmount`) |
| P2 (Premium Brno) | -30 days | Free (`individualMonthlyAmount = 0`) |
| E2 (Medium Jih) | -90 days | External prepaid ending +5 days → "brzy končí" cron + filter |
| X3 (Custom Centrum) | -90 days | External prepaid expired -10 days → Po splatnosti |
| O2 (Standard Ostrava) | -30 days | External prepaid until +30 days → blue "Předplaceno externě do …" banner |

## Handover protocols

| Constant | Contract | Created | State |
|----------|----------|---------|-------|
| `HandoverProtocolFixtures::REF_HANDOVER_PENDING` | CONTRACT_ACTIVE (B3) | -3 days | PENDING — neither side filled |
| `HandoverProtocolFixtures::REF_HANDOVER_TENANT_COMPLETED` | CONTRACT_TERMINATING (E1) | -5 days | TENANT_COMPLETED — tenant side filled -1 day |
| `HandoverProtocolFixtures::REF_HANDOVER_OVERDUE` | CONTRACT_TERMINATED (E1) | -16 days | PENDING > 14 days → red "overdue" row on the admin Operations hub |

## Invoices

| Constant | Order |
|----------|-------|
| `InvoiceFixtures::REF_INVOICE_COMPLETED` | REF_ORDER_COMPLETED |
| `InvoiceFixtures::REF_INVOICE_RECURRING` | REF_ORDER_COMPLETED_RECURRING |
| `InvoiceFixtures::REF_INVOICE_EXPIRING` | REF_ORDER_EXPIRING_SOON |

PDF files are written into the invoices directory on load.

## Place access (landlord ↔ place)

`PlaceAccessFixtures`: LANDLORD → Praha Centrum + Praha Jih; LANDLORD2 → Ostrava
(constants `REF_LANDLORD_PRAHA_CENTRUM`, `REF_LANDLORD_PRAHA_JIH`,
`REF_LANDLORD2_OSTRAVA`).

`PlaceAccessRequestFixtures`: pending LANDLORD → Brno; approved LANDLORD2 →
Ostrava; denied LANDLORD → Plzeň (constants `REF_PENDING_LANDLORD_BRNO`,
`REF_APPROVED_LANDLORD2_OSTRAVA`, `REF_DENIED_LANDLORD_PLZEN`).

## Storage lock-code usage (`PlaceStorageCodeUsageFixtures`, no constants)

At Praha Centrum: codes `0042` and `0577` recorded as USED (matching the active
lock codes on A1 and C1), plus code `9999` EXCLUDED with note "Servisní kód
zámku" (spec 082 exclusions UI).

## Photos

- `StorageTypePhotoFixtures` — placeholder JPGs for several storage types
  (Small Centrum ×1, Medium Centrum ×3, Large Centrum ×2, Custom Centrum ×3, …);
  some types intentionally have none to exercise the "no photos" branch.
- `StoragePhotoFixtures` — unit-specific photos: X1 ×2, X2 ×1; X3 intentionally
  none.
- Source JPGs live in `fixtures/photos/`; regenerate with
  `bin/console app:generate-fixture-photos`.

## Email log (`EmailLogFixtures`, no constants)

Six sample rows for the admin email-log UI: five SENT (some with attachment
metadata) and one FAILED with an error message.

## Storage Unavailabilities

| Constant | Storage | Period | Reason |
|----------|---------|--------|--------|
| `StorageUnavailabilityFixtures::REF_UNAVAILABILITY_INDEFINITE` | C2 | -7 days to NULL | Rekonstrukce |
| `StorageUnavailabilityFixtures::REF_UNAVAILABILITY_FIXED` | A4 | -3 days to +4 days | Preventivni udrzba |
| `StorageUnavailabilityFixtures::REF_UNAVAILABILITY_PAST` | A5 | -14 days to -7 days | Vymena zamku |
| `StorageUnavailabilityFixtures::REF_UNAVAILABILITY_FUTURE` | O1 | +14 days to +21 days | Planovana udrzba |

## Usage in Tests

```php
use App\DataFixtures\UserFixtures;
use App\DataFixtures\StorageFixtures;
use App\Entity\User;
use App\Entity\Storage;

class MyTest extends KernelTestCase
{
    public function testSomething(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Get user by email
        $landlord = $em->getRepository(User::class)
            ->findOneBy(['email' => UserFixtures::LANDLORD_EMAIL]);

        // Or use fixture reference (requires ReferenceRepository)
        // $landlord = $this->getReference(UserFixtures::REF_LANDLORD, User::class);
    }
}
```

## Important Notes

1. **Never create test data manually** - Always use fixture references
2. **Use constants** - `UserFixtures::REF_LANDLORD` not `'user-landlord'`
3. **MockClock** - Time is fixed at 2025-06-15 12:00:00 UTC, never use `new \DateTimeImmutable()`
4. **Isolation tests** - Use LANDLORD2 and Ostrava place to test landlord isolation — but note LANDLORD2 also owns storage Z1 at Praha Centrum (co-owner), so `PlaceAccess`-widened checks may legitimately pass there; use a place LANDLORD2 has no storage at for hard-deny tests
5. **Storage types are per-Place** - Each storage type belongs to exactly one Place
6. **"Available" storages are scarcer than they look** - onboarding + terminating fixtures occupy P1, P2, E1, E2, O2, X2, X3; tests needing a free unit should prefer A1–A3, A5, D1, D2, X1, Z1, O1
