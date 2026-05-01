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
| 008 | Order form as a Live Component (preserve inputs across map clicks, novalidate, on-blur server validation) | ready | [008-order-form-live-component.md](008-order-form-live-component.md) |
| 009 | Order: hide map by default, opt-in via "auto vs. pick from map" radio | ready | [009-order-storage-selection-mode.md](009-order-storage-selection-mode.md) |

