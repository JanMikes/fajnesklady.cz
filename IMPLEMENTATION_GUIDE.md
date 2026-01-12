# Storage Rental System - Implementation Guide

## Purpose

This document provides domain knowledge and business rules for implementing a storage rental system in Symfony. Understand the domain deeply before writing code. Make your own technical decisions on architecture, packages, and structure.

---

## 1. Domain Overview

### 1.1 System Purpose

A multi-tenant storage rental platform where:
- **Landlords** own Places containing physical Storage units
- **Users** rent Storages through an Order → Contract flow
- **Admins** manage the entire system

### 1.2 Core Business Flow

```
USER JOURNEY:

1. DISCOVERY
   └─ User visits homepage with map of Places
   └─ Clicks on Place → sees available StorageTypes + prices
   └─ Selects StorageType + rental period

2. ORDER CREATION
   └─ System AUTO-ASSIGNS specific Storage (user cannot choose)
   └─ Storage immediately enters RESERVED state
   └─ User enters email (creates/uses passwordless account)
   └─ Order created with 7-day payment deadline

3. PAYMENT
   └─ User "pays" (simulated for now)
   └─ Contract auto-generated from Place's DOCX template
   └─ User accepts contract (checkbox)
   └─ Storage enters OCCUPIED state

4. RENTAL PERIOD
   └─ Limited: Fixed end date, reminders at 7 days and 1 day before
   └─ Unlimited: Recurring payments (monthly/yearly), user can terminate anytime

5. EXTENSION / TERMINATION
   └─ Extension: Prefers same Storage, creates new Order
   └─ Termination: Storage returns to AVAILABLE
```

### 1.3 User Roles

| Role | Capabilities |
|------|-------------|
| **Admin** | Full system access. Manage all users, places, orders, contracts. View everything from any user. |
| **Landlord** | Create/manage own Places, StorageTypes, Storages. View own orders/contracts. Manually block storages. Upload contract templates. Access calendar/capacity views. |
| **User** | Browse places, create orders, view own orders/contracts, extend rentals. Minimal portal access. |

### 1.4 Critical Invariants

**THESE MUST NEVER BE VIOLATED:**

1. **No Double-Booking**: A specific Storage can only have ONE active reservation/occupation for any given date
2. **Availability Check**: Before any reservation, verify Storage is free for entire requested period
3. **Auto-Assignment**: Users NEVER choose specific Storage - system assigns based on availability
4. **7-Day Reservation Hold**: Unpaid orders expire after 7 days, releasing the reservation
5. **Same-Storage Preference**: Extensions always try to keep user in same Storage

---

## 2. Entity Model

### 2.1 Entity Relationships

```
User (email unique, password nullable for passwordless)
  │
  ├──< Place (landlord relationship)
  │      │
  │      ├──< StorageType
  │      │      │
  │      │      └──< Storage (number, coordinates JSON, status)
  │      │             │
  │      │             ├──< Order
  │      │             ├──< Contract  
  │      │             └──< StorageUnavailability (manual blocks)
  │      │
  │      └── contractTemplatePath (DOCX)
  │      └── mapImagePath
  │      └── daysInAdvance (0 = same day allowed)
  │
  ├──< Order
  └──< Contract

AuditLog (entity_type, entity_id, event_type, payload JSON, user, ip, timestamp)
```

### 2.2 Entity Fields

**User**
- email (unique), password (nullable), roles[], firstName, lastName, phone, verifiedAt, createdAt

**Place**
- name, description, address, city, postalCode, latitude, longitude
- mapImagePath, contractTemplatePath, daysInAdvance (default 0), isActive
- landlord (User), createdAt

**StorageType**
- name, description
- width, height, length (centimeters)
- pricePerWeek, pricePerMonth (decimal)
- place (Place), isActive, createdAt

**Storage**
- number (display identifier like "A1", "B12")
- coordinates (JSON for canvas position: x, y, width, height, rotation)
- status (available, reserved, occupied, manually_unavailable)
- storageType (StorageType), createdAt

**Order**
- uuid (public reference)
- user, storage
- rentalType (limited/unlimited)
- paymentFrequency (monthly/yearly, for unlimited only)
- startDate, endDate (nullable for unlimited)
- totalPrice, status, expiresAt (7 days from creation)
- createdAt, paidAt, cancelledAt

**Contract**
- uuid (public reference)
- order (one-to-one), user, storage
- rentalType, startDate, endDate (nullable for unlimited)
- documentPath (generated DOCX)
- signedAt, terminatedAt (nullable), createdAt

**StorageUnavailability**
- storage, startDate, endDate (nullable = indefinite)
- reason, createdBy (User), createdAt

**AuditLog**
- entityType, entityId, eventType, payload (JSON)
- user (nullable for system), ipAddress, createdAt

### 2.3 Status Values

**Storage Status**: available, reserved, occupied, manually_unavailable

**Order Status**: created → reserved → awaiting_payment → paid → completed
- Also: cancelled, expired (terminal states)

**Rental Type**: limited, unlimited

**Payment Frequency** (for unlimited): monthly, yearly

---

## 3. State Machines

### 3.1 Order State Machine

```
CREATED ──reserve()──> RESERVED ──process_payment()──> AWAITING_PAYMENT
                          │                                   │
                          │                          confirm_payment()
                          │                                   │
                          │                                   ▼
                          │                                 PAID ──complete()──> COMPLETED
                          │                                   │
                          ▼                                   │
                       EXPIRED <───── 7 days pass ────────────┤
                          ▲                                   │
                          │                                   ▼
                       cancel() ─────────────────────────> CANCELLED
```

**Transition Side Effects:**

| Transition | Side Effects |
|------------|--------------|
| reserve | Assign storage, set expiresAt (+7 days), storage → RESERVED |
| confirm_payment | Record paidAt |
| complete | Create Contract, storage → OCCUPIED |
| expire | Release storage reservation |
| cancel | Release storage reservation |

### 3.2 Storage Status

Storage status is derived from:
1. Manual unavailability records (StorageUnavailability)
2. Active orders (status in: reserved, awaiting_payment, paid)
3. Active contracts (not terminated, not past end date)

If none of the above apply → AVAILABLE

---

## 4. Business Rules

### 4.1 Pricing Calculation

**Rule**: Duration < 4 weeks uses weekly rate, ≥ 4 weeks uses monthly rate

**Algorithm**:
```
If days < 28:
  fullWeeks = days / 7 (integer division)
  remainingDays = days % 7
  price = (fullWeeks × weeklyRate) + (remainingDays × weeklyRate/7)

If days >= 28:
  fullMonths = days / 30 (integer division)
  remainingDays = days % 30
  price = (fullMonths × monthlyRate) + (remainingDays × monthlyRate/30)
```

**Examples**:
- 7 days = 1 × weekly
- 10 days = 1 × weekly + 3 × (weekly/7)
- 28 days = 1 × monthly (threshold)
- 45 days = 1 × monthly + 15 × (monthly/30)

### 4.2 Storage Assignment Algorithm

**Priority**:
1. If extending rental, try to assign SAME storage user currently has
2. Prefer storages currently in AVAILABLE status (not occupied at all)
3. If all occupied, find storage that becomes free before requested start date

**CRITICAL - Overlap Detection**:

A storage is NOT available for period [startDate, endDate] if ANY of these overlap:
- StorageUnavailability record
- Order with status in (reserved, awaiting_payment, paid)
- Active Contract

**Overlap Logic** (handles unlimited/null end dates):
```
periodsOverlap(start1, end1, start2, end2):
  If both end dates are null → overlap (both unlimited)
  If end1 is null → overlap if end2 >= start1
  If end2 is null → overlap if end1 >= start2
  Otherwise → start1 <= end2 AND start2 <= end1
```

**When No Storage Available**:
- Throw exception
- Notify admin that demand exists but no capacity
- Do NOT offer future dates automatically (reject the order)

### 4.3 Extension Flow

When user wants to extend:
1. Check if their current storage is available for new period
2. If yes → assign same storage
3. If no → try to find different storage of same type
4. If no storage of same type available → inform user, they must choose different type

### 4.4 Contract Template Processing

Landlord uploads DOCX template per Place. System replaces placeholders when generating contract.

**Required Placeholders**:
- `{{TENANT_NAME}}`, `{{TENANT_EMAIL}}`, `{{TENANT_PHONE}}`
- `{{STORAGE_NUMBER}}`, `{{STORAGE_TYPE}}`, `{{STORAGE_DIMENSIONS}}`
- `{{PLACE_NAME}}`, `{{PLACE_ADDRESS}}`
- `{{START_DATE}}`, `{{END_DATE}}` (or "Unlimited")
- `{{RENTAL_TYPE}}`, `{{PRICE}}`
- `{{CONTRACT_DATE}}`, `{{CONTRACT_NUMBER}}`

### 4.5 Availability Calendar

For landlord/public calendar view, calculate per day:
- Total storages of type
- Occupied count (check all blocking conditions)
- Available count

---

## 5. Implementation Phases

Work through these phases in order. Write tests as you go. Validate each phase before proceeding.

### Phase 1: Foundation

**Goal**: Entities, database, basic fixtures

- Create all entities with proper relationships
- Generate and run migrations
- Create fixtures: test admin, landlord, users, sample place with storage types and storages
- Verify: migrations run, fixtures load, basic queries work

### Phase 2: Core Domain Services

**Goal**: Business logic without UI

- Price calculator service
- Storage assignment service (with availability checking)
- Availability query service (for calendars)
- Audit logging service

**Critical Tests**:
- Price calculation for various durations
- Storage assignment never double-books
- Unlimited rentals handled correctly
- Same-storage preference for extensions

### Phase 3: Order Workflow

**Goal**: Complete order lifecycle

- Order state machine (use Symfony Workflow or custom)
- Order service: create, process payment, confirm, cancel, expire
- Events for each transition
- Storage status updates on transitions

**Critical Tests**:
- Order expires after 7 days
- Cancelled order releases storage
- Completed order creates contract and occupies storage

### Phase 4: Contract Management

**Goal**: Contract generation and lifecycle

- Contract document generator (DOCX template processing)
- Contract service: create from order, terminate
- Track expiring contracts

### Phase 5: User Management

**Goal**: Authentication and authorization

- Passwordless user creation (email only)
- Password setting/reset for users who want accounts
- Role-based access (Admin, Landlord, User)
- Voters for entity-level authorization

### Phase 6: Email System

**Goal**: Transactional emails

- Order confirmation
- Payment received / contract ready
- Contract expiring reminders (7 days, 1 day before)
- Password reset

### Phase 7: Scheduled Tasks (Cron)

**Goal**: Automated maintenance

- **Expire Orders Command**: Find orders past expiresAt in non-terminal status, transition to expired
- **Expiration Reminders Command**: Find contracts expiring in 7 days or 1 day, send emails (track sent to avoid duplicates)

### Phase 8: Public Frontend

**Goal**: Customer-facing pages

- Homepage with map showing all Places
- Place detail: storage types, prices, availability calendar
- Order form (cart-like): select type, dates, enter email
- Payment simulation page: "Pay" and "Cancel" buttons
- Contract acceptance (checkbox)
- Order completion confirmation

### Phase 9: User Portal

**Goal**: Authenticated user area

- Dashboard: active rentals overview
- Orders list and detail
- Contracts list, detail, download document
- Terminate unlimited contract
- Profile management

### Phase 10: Landlord Portal

**Goal**: Landlord management

- Dashboard: occupancy stats, revenue
- Place CRUD, upload map image, upload contract template
- Storage Type CRUD
- Calendar view: occupancy per day, filter by type
- Manual unavailability: block/release storages
- Orders and customer details view

### Phase 11: Storage Canvas Editor

**Goal**: Visual storage placement on place map

- Symfony UX Live Component (or alternative approach)
- Load place map image as background
- Draw/position rectangles for storages
- Assign number and storage type to each
- Save coordinates as JSON
- Optional: make interactive for users (show type, calendar on click)

### Phase 12: Admin Area

**Goal**: Full system administration

- User management (list, edit roles, impersonate)
- View/edit any place, order, contract
- Audit log viewer with search/filter

---

## 6. Testing Requirements

### Critical Test Cases (Must Pass)

**Storage Assignment**:
```
✓ Assigns first available storage
✓ Never assigns storage with overlapping reservation
✓ Never assigns storage with overlapping contract
✓ Never assigns manually blocked storage
✓ Handles unlimited rentals (null end date)
✓ Prefers same storage for user extension
✓ Falls back to different storage when same unavailable
✓ Throws exception when no storage available
```

**Order Workflow**:
```
✓ Order expires after 7 days if not paid
✓ Expired order releases storage reservation
✓ Cancelled order releases storage reservation
✓ Completed order creates contract
✓ Completed order sets storage to occupied
✓ Cannot complete order without payment
```

**Price Calculation**:
```
✓ 7 days = 1 week rate
✓ 10 days = 1 week + 3 days pro-rata
✓ 27 days = weekly rate calculation
✓ 28 days = 1 month rate (threshold)
✓ 45 days = 1 month + 15 days pro-rata
```

---

## 7. Audit Events to Track

Log these events with relevant payload:

**Order Events**: created, reserved, paid, completed, cancelled, expired

**Contract Events**: created, signed, terminated, expiring_soon

**Storage Events**: reserved, occupied, released, manually_blocked, manually_released

---

## 8. Edge Cases to Handle

1. **User orders while anonymous, then logs in with same email** → Should be same user, orders merge automatically

2. **Landlord tries to block already-occupied storage** → Reject, can only block for dates without existing reservations/occupancy

3. **Two users order same storage type simultaneously** → First to complete order creation wins, second gets different storage or error

4. **User extends but their storage was manually blocked** → Assign different storage of same type

5. **Contract expires on same day new rental starts** → Previous rental ends at 23:59, new starts at 00:00 - no overlap (day granularity)

6. **Unlimited contract termination** → Storage available from day after termination

7. **Place has no contract template** → Cannot complete orders until template uploaded

---

## 9. UI/UX Notes

### Public Order Flow
- Cart-like experience (one storage type per order for now)
- Calendar picker for date selection
- Show price calculation as user selects dates
- Email field required, password optional (can set later)

### Landlord Calendar
- Monthly view
- Color coding: green (available), yellow (reserved), red (occupied), gray (blocked)
- Click day to see details

### Payment Simulation
- Simple page with order summary
- "Simulate Payment" button → marks as paid
- "Cancel Order" button → cancels
- After payment: contract acceptance checkbox → completes order

---

## Summary

The most critical aspect of this system is **availability management**. Every storage assignment must verify no overlaps exist. This check happens:

1. When creating an order (reserve storage)
2. When landlord views availability
3. When public user views availability
4. When extending a rental

Build the availability checking logic once, test it thoroughly, and reuse it everywhere.

Start with Phase 1, get the domain model right, then build up from there. Test as you go. Ask for clarification if any business rule is unclear.
