# 002 — Admin: change user password

**Status:** ready
**Type:** feature (admin tooling)
**Scope:** medium (~10 files: controller + form + template + entity tweak + command/handler reuse + event + email handler + audit log)
**Depends on:** none. Works fine independently of spec 003 (which removes `PasswordStrength` from self-service forms).

## Problem

Admins currently have no way to set a user's password. There's a stub `App\Command\SetUserPasswordCommand` + `SetUserPasswordHandler` already in the codebase but no controller wired to it.

## Goal

Add a dedicated admin page where an admin can set a new password for any other user. Sends a notification email to the affected user and writes an audit-log entry.

## Context (current state)

- Admin user pages live under `/portal/users/*`, all gated by `#[IsGranted('ROLE_ADMIN')]`. View at `src/Controller/Portal/UserViewController.php` (`portal_users_view`), edit at `src/Controller/Portal/UserEditController.php` (`portal_users_edit`). View template `templates/portal/user/view.html.twig` already has a row of action buttons in the header (Verify / Activate / Deactivate / Edit / Switch user).
- `App\Command\SetUserPasswordCommand` (`src/Command/SetUserPasswordCommand.php`) exists with fields `Uuid $userId, string $plainPassword`. Handler hashes + persists via `User::changePassword()`. **Currently has zero callers** — safe to repurpose.
- `App\Entity\User::changePassword(string $hashed, \DateTimeImmutable $now)` (`src/Entity/User.php:133`) is a plain setter — does NOT record any domain event. The entity uses `HasEvents` trait + `EntityWithEvents` interface; events are dispatched via `App\Middleware\DispatchDomainEventsMiddleware` after the command transaction commits.
- Self-service password change at `/portal/profile/change-password` (`ChangePasswordController` + `ChangePasswordFormType`) requires the current password and is the model to copy for form structure (`RepeatedType` + `PasswordType`, autocomplete=`new-password`).
- `App\Service\AuditLogger` (`src/Service/AuditLogger.php`) has a generic `log(entityType, entityId, eventType, payload)` method; actor user + IP are captured automatically via `Security` and `RequestStack`. Add a typed wrapper for this event for consistency with the existing typed methods.
- Existing email handlers live in `src/Event/Send*Handler.php`, listen to a domain event, and use `TemplatedEmail` from `noreply@fajnesklady.cz`. Templates in `templates/email/*.html.twig`. See `SendPasswordResetEmailHandler` (`src/Event/SendPasswordResetEmailHandler.php`) for the canonical pattern.

## Requirements

### 1. Dedicated controller + route

Create `src/Controller/Portal/UserChangePasswordController.php`:
- Route: `#[Route('/portal/users/{id}/change-password', name: 'portal_users_change_password')]`
- `#[IsGranted('ROLE_ADMIN')]`
- Handles GET (show form) and POST (submit).
- Loads user via `UserRepository::get(Uuid::fromString($id))` (matches `UserEditController`).
- **Deny if `$user->id == $currentUser->id`** — admins must use the self-service flow for their own password (which requires the current password). On match, throw a 403 (`createAccessDeniedException()`).
- On valid submit: dispatch `SetUserPasswordCommand`, flash success `Heslo uživatele bylo změněno.`, redirect to `portal_users_view`.

### 2. Form

Create `src/Form/AdminUserPasswordFormType.php` + `src/Form/AdminUserPasswordFormData.php`.

`AdminUserPasswordFormData`:
```php
final class AdminUserPasswordFormData
{
    #[Assert\NotBlank(message: 'Zadejte nové heslo.')]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'Heslo musí mít alespoň {{ limit }} znaků.',
    )]
    public string $newPassword = '';
}
```
**No `PasswordStrength` constraint** (matches direction in spec 003).

`AdminUserPasswordFormType`:
- One `RepeatedType` mapped to `newPassword`, child `PasswordType`, `invalid_message: 'Hesla se neshodují.'`
- `first_options.label`: `'Nové heslo'`, `attr.autocomplete: 'new-password'`
- `second_options.label`: `'Potvrzení nového hesla'`, `attr.autocomplete: 'new-password'`
- Mirror `ChangePasswordFormType` styling/labels.

### 3. Template

Create `templates/portal/user/change_password.html.twig`. Mirror layout of `templates/portal/user/edit.html.twig`:
- Extends `portal/layout.html.twig`
- Breadcrumb: `Uživatelé → {user.fullName} → Změnit heslo`
- Page title `Změnit heslo uživatele`
- Helper text under the heading: `Po uložení bude uživateli odeslán e-mail s upozorněním, že jeho heslo bylo změněno administrátorem.`
- Submit button `Uložit nové heslo`, cancel link to `portal_users_view`.

### 4. Button on user view page

Update `templates/portal/user/view.html.twig` action button row (around line 13–34): add a `Změnit heslo` button between `Upravit` and the activate/deactivate group. **Hide it when viewing one's own profile**:

```twig
{% if user.id != app.user.id %}
    <a href="{{ path('portal_users_change_password', {id: user.id}) }}" class="btn btn-secondary">Změnit heslo</a>
{% endif %}
```

### 5. Entity method + domain event

In `src/Entity/User.php`, add a new method below `changePassword()`:

```php
public function changePasswordByAdmin(string $hashedPassword, \DateTimeImmutable $now): void
{
    $this->changePassword($hashedPassword, $now);
    $this->recordThat(new PasswordChangedByAdmin(
        userId: $this->id,
        email: $this->email,
        occurredOn: $now,
    ));
}
```

Do **not** add `recordThat` inside the existing `changePassword()` itself — that would also fire on self-service change and password reset, which we don't want (avoids spamming users when they just set their own password).

Create `src/Event/PasswordChangedByAdmin.php`:
```php
final readonly class PasswordChangedByAdmin
{
    public function __construct(
        public Uuid $userId,
        public string $email,
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

### 6. Repurpose `SetUserPasswordCommand` + handler

- Update the docblock on `SetUserPasswordCommand` from `"Set password for a passwordless user account."` to `"Admin-driven password change for a user. Always emits PasswordChangedByAdmin domain event."`
- In `SetUserPasswordHandler`, replace `$user->changePassword($hashed, $now)` with `$user->changePasswordByAdmin($hashed, $now)`. The middleware will dispatch the event.

### 7. Email handler + template

Create `src/Event/SendPasswordChangedByAdminEmailHandler.php` following the `SendPasswordResetEmailHandler` pattern:
- `#[AsMessageHandler]`, listens to `PasswordChangedByAdmin`
- Sends `TemplatedEmail` from `noreply@fajnesklady.cz`, name `Fajnesklady.cz`, to `$event->email`
- Subject: `Vaše heslo bylo změněno administrátorem`
- Template: `email/password_changed_by_admin.html.twig`
- Context: `userEmail`, plus a `loginUrl` (generate `app_login` absolute) and a `resetPasswordUrl` (generate `app_request_password_reset` absolute) so the user can self-recover if it wasn't them.

Create `templates/email/password_changed_by_admin.html.twig`. Match the look of `templates/email/welcome.html.twig` / `verification.html.twig`. Czech with full diacritics. Content outline:
- Heading: `Vaše heslo bylo změněno`
- Body paragraph: `Vaše heslo k účtu na Fajnesklady.cz bylo právě změněno administrátorem. Nové heslo Vám bylo sděleno samostatně.`
- Then: `Pokud jste o tuto změnu nepožádali, ihned si nastavte vlastní heslo:` followed by the password-reset link.
- Login button.

### 8. Audit log entry

In `src/Service/AuditLogger.php`, add a typed wrapper next to the other typed methods:

```php
public function logUserPasswordChangedByAdmin(User $targetUser): void
{
    $this->log(
        entityType: 'user',
        entityId: $targetUser->id->toRfc4122(),
        eventType: 'password_changed_by_admin',
        payload: ['target_email' => $targetUser->email],
    );
}
```

Call it from `SetUserPasswordHandler` **after** persisting (the handler is invoked in the actor's HTTP request context, so `Security::getUser()` will resolve the admin correctly). Inject `AuditLogger` into the handler.

### 9. Tests

- Unit test `App\Entity\User::changePasswordByAdmin()` records `PasswordChangedByAdmin` event with the right fields (use `MockClock`, fixed time `2025-06-15 12:00:00 UTC` per `CLAUDE.md`).
- Integration/controller test for `UserChangePasswordController`:
  - admin can change another user's password (form valid → password updated → flash → redirect)
  - admin cannot change own password via this route (403)
  - non-admin cannot access (403)
  - mismatched repeat → form error
  - too-short password (< 8) → form error

## Acceptance

- `docker compose exec web composer quality` is green.
- Logged in as `admin@example.com`, on `/portal/users/{id}` for any other user, a `Změnit heslo` button is visible. The button is hidden when viewing the admin's own user page.
- Clicking it loads the form. Submitting a valid new password (twice) updates the user's password (the user can subsequently log in with it), shows a success flash, and redirects to the user view page.
- The target user receives an email titled "Vaše heslo bylo změněno administrátorem" with a password-reset link.
- A row appears in `audit_log` with `entity_type='user'`, `event_type='password_changed_by_admin'`, `user_id` = the acting admin.
- Self-service `/portal/profile/change-password` is unaffected and still works.

## Out of scope

- Forcing the user to change the password on next login (explicitly declined).
- Removing `PasswordStrength` from self-service forms — see spec 003.
- Generating a temporary password for the admin to copy. The admin types the password themselves.
- Showing the new password back to the admin after submission.
- Showing a "last password change" timestamp on the user view page.

## Open questions

None — proceed.
