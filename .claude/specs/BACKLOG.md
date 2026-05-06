# Backlog

Small, independent tasks. Each row = one unit of work to hand to an implementation agent.

**Spec format (per item):**
- Short items live inline. Longer ones get a linked file `.claude/specs/NNN-slug.md`.
- Standard sections per spec: **Context** (current state + file paths), **Requirements**, **Acceptance**, **Out of scope**, **Open questions**.
- Always read `.claude/specs/PROJECT_MAP.md` first for orientation, then the CLAUDE.md at repo root.

**Status legend:** `draft` (gathering info) · `ready` (can hand to dev) · `in-progress` · `done` · `blocked`

## Items

| # | Title | Status | Spec |
|---|---|---|---|
| 001 | Persistent sessions via PdoSessionHandler (survive deploys, 30-day idle) | done | [001-pdo-session-handler.md](001-pdo-session-handler.md) |
| 002 | Admin: change another user's password (dedicated page, email + audit log) | done | [002-admin-change-user-password.md](002-admin-change-user-password.md) |
| 003 | Drop `PasswordStrength` constraint from self-service forms (keep min 8) | done | [003-drop-password-strength-constraint.md](003-drop-password-strength-constraint.md) |
| 004 | Audit log of outgoing emails (entity + listener + admin browse/detail UI) | done | [004-email-audit-log.md](004-email-audit-log.md) |
| 005 | Show/hide password toggle on every password input (Stimulus + form theme) | done | [005-password-visibility-toggle.md](005-password-visibility-toggle.md) |
| 006 | "Načíst z ARES" button next to every IČO field (refactor + JSON endpoint + Stimulus) | done | [006-ares-lookup-button.md](006-ares-lookup-button.md) |
| 007 | Flatpickr on every date input (form theme + filters + birthDate maxDate) | done | [007-flatpickr-everywhere.md](007-flatpickr-everywhere.md) |
| 008 | Order form as a Live Component (preserve inputs across map clicks, novalidate, on-blur server validation) | done | [008-order-form-live-component.md](008-order-form-live-component.md) |
| 009 | Order: hide map by default, opt-in via "auto vs. pick from map" radio | done | [009-order-storage-selection-mode.md](009-order-storage-selection-mode.md) |
| 010 | Customer documents after successful payment (success page + email link + new docs index) | ready | [010-customer-documents-after-payment.md](010-customer-documents-after-payment.md) |
| 011 | Move highlighted-storage map from pre-payment to post-payment email (attachment, once at finalization) | ready | [011-move-storage-map-to-post-payment-email.md](011-move-storage-map-to-post-payment-email.md) |
| 012 | Photos visible across the order journey (place card / form sidebar / map modal / recap) with GLightbox, never cropped | done | [012-photos-during-order.md](012-photos-during-order.md) |
| 013 | Price label reflects billing cadence (Celková cena vs Měsíční platba / měsíc) — fix in emails + UI | ready | [013-price-label-by-billing-cadence.md](013-price-label-by-billing-cadence.md) |
| 014 | One-click "Prodloužit pronájem" from previous order (email CTA + portal CTA + prefilled form) | ready | [014-one-click-prolong-from-previous-order.md](014-one-click-prolong-from-previous-order.md) |
| 015 | Order accept redesign — wider layout, smaller inline photos, inline signing (no modal, radios), single consolidated consent | done | [015-order-accept-redesign.md](015-order-accept-redesign.md) |
| 016 | GoPay & VOP compliance pass — button label per § 1826a OZ, dedicated recurring consent, card/3DS logos, parameter card, identification, recurring confirmation + advance-notice e-mails | ready (P1+P2) / P3 deferred | [016-gopay-vop-compliance.md](016-gopay-vop-compliance.md) |
| 017 | Per-place order expiration window (default 3 days) — replace hardcoded `RESERVATION_DAYS = 7` with a configurable `Place::$orderExpirationDays`, exposed in the place edit/create form | done | [017-per-place-order-expiration-days.md](017-per-place-order-expiration-days.md) |
| 018 | Detect customer-side cancellation of recurring (ON_DEMAND) GoPay payments — distinct event + acknowledgement e-mails (customer + admin), stop retry loop, leave contract in documented holding state | draft | [018-handle-gopay-side-recurring-cancellation.md](018-handle-gopay-side-recurring-cancellation.md) |
| 019 | Settle outstanding usage when customer cancels an open-ended recurring payment — preview prorated amount on cancel page, charge via GoPay token before voiding it, fall through to outstanding-debt path on failure | draft | [019-settle-outstanding-on-cancel.md](019-settle-outstanding-on-cancel.md) |

