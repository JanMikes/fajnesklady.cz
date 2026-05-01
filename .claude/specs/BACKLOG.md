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
| 001 | Persistent sessions via PdoSessionHandler (survive deploys, 30-day idle) | ready | [001-pdo-session-handler.md](001-pdo-session-handler.md) |

