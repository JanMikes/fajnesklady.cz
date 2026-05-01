# 003 — Drop `PasswordStrength` constraint, keep min 8 chars only

**Status:** done
**Type:** validation tweak
**Scope:** tiny (2 files)
**Depends on:** none

## Problem

Two self-service forms enforce `Symfony\Component\Validator\Constraints\PasswordStrength` (medium score). Decision: keep only the existing `Length(min: 8)` requirement. New admin password form (spec 002) already follows this rule.

## Context

`grep -rn PasswordStrength src/` confirms only two hits:
- `src/Form/ChangePasswordFormData.php` — self-service "change my password" (logged-in user, requires current pwd).
- `src/Form/ResetPasswordFormData.php` — "reset password via emailed token".

Other password fields already enforce only `Length(min: 8)` (e.g. `RegistrationFormData.php`, `LandlordRegistrationFormData.php`) — nothing to change there.

## Requirements

In each of the two files above:
1. Remove the entire `#[Assert\PasswordStrength(...)]` attribute block.
2. Leave the `#[Assert\NotBlank(...)]` and `#[Assert\Length(min: 8, max: 4096, minMessage: ...)]` attributes intact.
3. Remove the now-unused `Symfony\Component\Validator\Constraints as Assert` import — wait, no, `Assert\NotBlank` and `Assert\Length` still need it. Keep the import.

That's it — no entity, command, template, or test changes elsewhere.

## Acceptance

- `grep -rn PasswordStrength src/ tests/` returns nothing.
- `docker compose exec web composer quality` is green.
- Submitting a 9-character weak password (e.g. `password1`) on `/portal/profile/change-password` and on the password-reset form succeeds.
- Submitting a 7-character password still fails with the existing length error.

## Out of scope

- Adjusting `min` from 8 to anything else.
- Touching registration / landlord-registration / admin-onboarding password rules (already at `min: 8`, no strength check).
- Changing `max: 4096` (kept as-is — protects against DoS on password hashers).

## Open questions

None.
