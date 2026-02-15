# Test Fixtures Documentation

This document describes the fixture data available for integration tests.

## Mock Clock

Tests use a fixed time: **2025-06-15 12:00:00 UTC**

All fixture data and date calculations are relative to this time.

## Fixture Load Order

```
UserFixtures
    ↓
PlaceFixtures
    ↓
StorageTypeFixtures (depends on PlaceFixtures)
    ↓
StorageFixtures
    ↓
OrderFixtures
    ↓
ContractFixtures
    ↓
StorageUnavailabilityFixtures
```

## Users

| Constant | Email | Role | Verified | Purpose |
|----------|-------|------|----------|---------|
| `UserFixtures::REF_USER` | user@example.com | USER | Yes | Basic tenant |
| `UserFixtures::REF_UNVERIFIED` | unverified@example.com | USER | No | Auth tests |
| `UserFixtures::REF_LANDLORD` | landlord@example.com | LANDLORD | Yes | Primary landlord |
| `UserFixtures::REF_LANDLORD2` | landlord2@example.com | LANDLORD | Yes | Isolation tests |
| `UserFixtures::REF_TENANT` | tenant@example.com | USER | Yes | Order tests |
| `UserFixtures::REF_ADMIN` | admin@example.com | ADMIN | Yes | Admin access |

**Email constants:** `UserFixtures::USER_EMAIL`, `UserFixtures::LANDLORD_EMAIL`, etc.

## Places

| Constant | Owner | Name | Location |
|----------|-------|------|----------|
| `PlaceFixtures::REF_PRAHA_CENTRUM` | LANDLORD | Sklad Praha - Centrum | Praha 1 |
| `PlaceFixtures::REF_PRAHA_JIH` | LANDLORD | Sklad Praha - Jiznimesto | Praha 4 |
| `PlaceFixtures::REF_BRNO` | ADMIN | Sklad Brno | Brno |
| `PlaceFixtures::REF_OSTRAVA` | LANDLORD2 | Sklad Ostrava | Ostrava |

## Storage Types (per-Place)

Storage types belong to a specific Place.

### Praha Centrum
| Constant | Name | Dimensions | Weekly | Monthly |
|----------|------|------------|--------|---------|
| `StorageTypeFixtures::REF_SMALL_CENTRUM` | Maly box | 1m x 1m x 1m | 150 CZK | 500 CZK |
| `StorageTypeFixtures::REF_MEDIUM_CENTRUM` | Stredni box | 2m x 2m x 2m | 350 CZK | 1200 CZK |
| `StorageTypeFixtures::REF_LARGE_CENTRUM` | Velky box | 3m x 2.5m x 4m | 800 CZK | 2800 CZK |
| `StorageTypeFixtures::REF_CUSTOM_CENTRUM` | Custom box | 2.5m x 2.2m x 3m | 400 CZK | 1400 CZK |

### Praha Jih
| Constant | Name | Weekly | Monthly |
|----------|------|--------|---------|
| `StorageTypeFixtures::REF_SMALL_JIH` | Maly box | 120 CZK | 400 CZK |
| `StorageTypeFixtures::REF_MEDIUM_JIH` | Stredni box | 300 CZK | 1000 CZK |

### Brno
| Constant | Name | Weekly | Monthly |
|----------|------|--------|---------|
| `StorageTypeFixtures::REF_PREMIUM_BRNO` | Premium box | 1500 CZK | 5000 CZK |

### Ostrava
| Constant | Name | Weekly | Monthly |
|----------|------|--------|---------|
| `StorageTypeFixtures::REF_STANDARD_OSTRAVA` | Standardni box | 200 CZK | 700 CZK |

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

### Praha Centrum
| Constant | Number | Type | Status |
|----------|--------|------|--------|
| `StorageFixtures::REF_SMALL_A1` | A1 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_A2` | A2 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_A3` | A3 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_A4` | A4 | Small | MANUALLY_UNAVAILABLE |
| `StorageFixtures::REF_SMALL_A5` | A5 | Small | AVAILABLE |
| `StorageFixtures::REF_MEDIUM_B1` | B1 | Medium | RESERVED (order pending) |
| `StorageFixtures::REF_MEDIUM_B2` | B2 | Medium | RESERVED (order paid) |
| `StorageFixtures::REF_MEDIUM_B3` | B3 | Medium | OCCUPIED (active contract) |
| `StorageFixtures::REF_LARGE_C1` | C1 | Large | OCCUPIED (unlimited contract) |
| `StorageFixtures::REF_LARGE_C2` | C2 | Large | MANUALLY_UNAVAILABLE |

### Praha Jih
| Constant | Number | Type | Status |
|----------|--------|------|--------|
| `StorageFixtures::REF_SMALL_D1` | D1 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_D2` | D2 | Small | AVAILABLE |
| `StorageFixtures::REF_SMALL_D3` | D3 | Small | OCCUPIED (expiring soon) |
| `StorageFixtures::REF_MEDIUM_E1` | E1 | Medium | AVAILABLE (terminated contract) |
| `StorageFixtures::REF_MEDIUM_E2` | E2 | Medium | AVAILABLE |

### Brno
| Constant | Number | Status |
|----------|--------|--------|
| `StorageFixtures::REF_PREMIUM_P1` | P1 | AVAILABLE |
| `StorageFixtures::REF_PREMIUM_P2` | P2 | AVAILABLE |

### Ostrava
| Constant | Number | Status |
|----------|--------|--------|
| `StorageFixtures::REF_STANDARD_O1` | O1 | AVAILABLE |
| `StorageFixtures::REF_STANDARD_O2` | O2 | AVAILABLE |

## Orders

| Constant | User | Storage | Status | Rental Type |
|----------|------|---------|--------|-------------|
| `OrderFixtures::REF_ORDER_RESERVED` | TENANT | B1 | RESERVED | LIMITED |
| `OrderFixtures::REF_ORDER_PAID` | TENANT | B2 | PAID | LIMITED |
| `OrderFixtures::REF_ORDER_COMPLETED` | USER | B3 | COMPLETED | LIMITED |
| `OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED` | USER | C1 | COMPLETED | UNLIMITED |
| `OrderFixtures::REF_ORDER_CANCELLED` | TENANT | D1 | CANCELLED | LIMITED |
| `OrderFixtures::REF_ORDER_EXPIRED` | TENANT | D2 | EXPIRED | LIMITED |
| `OrderFixtures::REF_ORDER_EXPIRING_SOON` | TENANT | D3 | COMPLETED | LIMITED |

## Contracts

| Constant | User | Storage | Status | End Date |
|----------|------|---------|--------|----------|
| `ContractFixtures::REF_CONTRACT_ACTIVE` | USER | B3 | Active, signed | +29 days |
| `ContractFixtures::REF_CONTRACT_UNLIMITED` | USER | C1 | Active, signed | NULL (unlimited) |
| `ContractFixtures::REF_CONTRACT_EXPIRING_7_DAYS` | TENANT | D3 | Active, signed | +7 days |
| `ContractFixtures::REF_CONTRACT_TERMINATED` | TENANT | E1 | Terminated | -30 days |

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
4. **Isolation tests** - Use LANDLORD2 and Ostrava place to test landlord isolation
5. **Storage types are per-Place** - Each storage type belongs to exactly one Place
