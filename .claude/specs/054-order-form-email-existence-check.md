# 054 — Hide password field when order email matches an existing account

**Status:** ready
**Type:** UX
**Scope:** small (3 files)
**Depends on:** none

## Problem

When an anonymous visitor fills the order form, they see an email field and an optional password field. If the visitor's email already belongs to a registered user, the password field is misleading — it implies they're creating a new account, but `GetOrCreateUserByEmailHandler` will silently associate the order with the existing account regardless. There's no feedback that the system recognised them.

## Goal

After the visitor blurs the email field, the Live Component checks whether the email belongs to an existing user. If it does, the password field is replaced by a friendly informational message explaining that the order will be linked to their account and they can find it in the portal after logging in. If the visitor changes the email to one that doesn't exist, the password field reappears.

## Context (current state)

- **`src/Twig/Components/OrderForm.php`**: Live Component with `validateField` action (line 256). Already injects `StorageRepository`, `RequestStack`, `UrlGeneratorInterface`, `PriceCalculator`. Does NOT inject `UserRepository`.
- **`templates/components/OrderForm.html.twig:56-62`**: Email field with `blur->live#action` / `validateField` wiring.
- **`templates/components/OrderForm.html.twig:88-98`**: Password field, gated by `{% if not app.user %}`. For logged-in users, field is suppressed via `setRendered`.
- **`src/Form/OrderFormData.php:42`**: `$plainPassword` is nullable, optional. `validatePassword` callback (line 128) only fires when non-empty.
- **`src/Form/OrderFormType.php:81-94`**: PasswordType with `always_empty: false`, `required: false`.
- **`src/Command/GetOrCreateUserByEmailHandler.php:31-38`**: For existing users, syncs profile/billing and returns — password param is only used for new user creation (line 54-58).
- **`src/Repository/UserRepository.php:38-47`**: `findByEmail(string $email): ?User` already exists.

## Requirements

### 1. Add `emailExistsInSystem` LiveProp to `OrderForm` (`src/Twig/Components/OrderForm.php`)

- Inject `UserRepository` via constructor.
- Add a `#[LiveProp] public bool $emailExistsInSystem = false;`.
- In `validateField()` (line 256), after the existing `validatedFields` push, add a branch:

```php
if ('email' === $field) {
    $data = $this->getForm()->getData();
    $email = $data instanceof OrderFormData ? $data->email : '';
    $this->emailExistsInSystem = '' !== $email && null !== $this->userRepository->findByEmail($email);
}
```

- In `submit()`, if `emailExistsInSystem` is true, force `$data->plainPassword = null` before writing to session — defensive belt so a stale password value can never leak through even if the DOM was tampered with.

### 2. Update template (`templates/components/OrderForm.html.twig`)

Replace the password block (lines 88-98) with:

```twig
{% if not app.user %}
    {% if this.emailExistsInSystem %}
        <div class="mt-4 rounded-lg bg-blue-50 border border-blue-200 p-4 text-sm text-blue-800">
            <div class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 mt-0.5 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Účet s tímto e-mailem již existuje. Objednávka bude přiřazena k vašemu účtu a naleznete ji v portálu po přihlášení.</span>
            </div>
        </div>
        {% do form.plainPassword.setRendered %}
    {% else %}
        <div class="mt-4">
            {{ form_row(form.plainPassword, {attr: {
                'data-action': 'blur->live#action',
                'data-live-action-param': 'validateField',
                'data-live-field-param': 'plainPassword',
            }}) }}
        </div>
    {% endif %}
{% else %}
    {% do form.plainPassword.setRendered %}
{% endif %}
```

### 3. No changes needed elsewhere

- `OrderFormData` — no changes. `$plainPassword` stays nullable; `validatePassword` already skips when null/empty.
- `OrderFormType` — no changes. The PasswordType field stays in the form definition (Symfony requires it for the form to be valid); it's just not rendered when hidden.
- `GetOrCreateUserByEmailHandler` — no changes. Already ignores password for existing users.
- `OrderAcceptController` — no changes. Reads `plainPassword` from session as-is.

## Acceptance

- [ ] Anonymous visitor types an email that exists in the DB → on blur, the password field disappears and the blue info banner appears with text "Účet s tímto e-mailem již existuje. Objednávka bude přiřazena k vašemu účtu a naleznete ji v portálu po přihlášení."
- [ ] Visitor changes the email to one that does NOT exist → password field reappears, banner disappears.
- [ ] Visitor with a recognised email can submit the form successfully and the order is associated with the existing user.
- [ ] Logged-in users see no change (password field was already hidden).
- [ ] `composer quality` is green.

## Out of scope

- **Login CTA / link in the banner** — the message is purely informational; adding a login redirect mid-order would break the flow.
- **Email enumeration hardening** — this is a storage rental site, not a banking app. The existing `GetOrCreateUserByEmail` flow already implicitly reveals email existence. If needed later, rate-limiting on the Live Component endpoint is the right layer.
- **Passwordless users vs. full users distinction** — both are treated identically. Passwordless users who want portal access can use the "Zapomenuté heslo" flow.
- **Admin onboarding form** — `AdminOnboardingForm` has its own email field and different UX; not touched here.

## Open questions

None — proceed.
