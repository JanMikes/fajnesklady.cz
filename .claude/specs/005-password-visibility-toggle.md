# 005 — Show/hide password toggle on every password input

**Status:** done
**Type:** UX
**Scope:** small (1 new Stimulus controller + 1 form-theme block + 1 hand-rolled template tweak)
**Depends on:** none. Independent of spec 002 (which adds another password form that will automatically inherit the toggle once this is in place).

## Problem

Users currently have no way to verify what they typed in a password field. The login, registration, password-reset, change-password and guest-checkout forms all use plain `<input type="password">` with no visibility toggle.

## Goal

Every password input across the app gets a click-to-toggle eye icon inside the input on the right. One Stimulus controller + a form-theme override = covers all `PasswordType` fields in one place. Login (hand-rolled) gets the same markup applied manually.

## Context (current state)

- All Symfony forms render through `templates/form/tailwind_theme.html.twig` (configured in `config/packages/twig.php`). The theme overrides `form_widget_simple`, `textarea_widget`, `choice_widget_collapsed`, etc., but **does not override `password_widget`** — passwords currently fall back to `form_widget_simple`, producing a plain `<input type="password" class="form-input">`.
- JS stack: Symfony AssetMapper + `@hotwired/stimulus` 3.2.2 + `@symfony/stimulus-bundle` (auto-registers controllers in `assets/controllers/*_controller.js`). Existing controllers: `datepicker`, `hello`, `hero_slider`, `lightbox`, `location_picker`, `map`, `signature`, `storage_canvas`, `storage_map`. Pattern reference: `assets/controllers/datepicker_controller.js`.
- Icon style throughout the app: hand-written inline SVGs in Heroicons format. No icon library installed; do not add one.
- All `PasswordType`/`RepeatedType<PasswordType>` fields in code:
  - `src/Form/RegistrationFormType.php` — `password` (RepeatedType)
  - `src/Form/LandlordRegistrationFormType.php` — `password` (RepeatedType)
  - `src/Form/ResetPasswordFormType.php` — `newPassword` (RepeatedType)
  - `src/Form/ChangePasswordFormType.php` — `currentPassword` + `newPassword` (RepeatedType)
  - `src/Form/OrderFormType.php` — `plainPassword` (PasswordType, optional guest-checkout password)
  - **(after spec 002 lands)** `src/Form/AdminUserPasswordFormType.php` — `newPassword` (RepeatedType)
- Hand-rolled (non-Symfony-form) password inputs: `templates/user/login.html.twig` lines 46–52 (`<input type="password" name="_password">`).
- `RepeatedType` renders two separate `PasswordType` widgets in the DOM, so the form-theme override naturally covers both halves of every confirm-password flow.

## Requirements

### 1. Stimulus controller

Create `assets/controllers/password_toggle_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'showIcon', 'hideIcon'];

    connect() {
        this.update(false);
    }

    toggle() {
        const isHidden = this.inputTarget.type === 'password';
        this.update(isHidden);
    }

    update(visible) {
        this.inputTarget.type = visible ? 'text' : 'password';
        this.showIconTarget.classList.toggle('hidden', visible);
        this.hideIconTarget.classList.toggle('hidden', !visible);
        this.element.querySelector('button')?.setAttribute(
            'aria-label',
            visible ? 'Skrýt heslo' : 'Zobrazit heslo',
        );
    }
}
```

No external deps; the controller is auto-registered by `@symfony/stimulus-bundle`.

### 2. Form-theme override — new `password_widget` block

In `templates/form/tailwind_theme.html.twig`, **add** (do not replace anything) a new block:

```twig
{# Password widget with show/hide toggle #}
{% block password_widget -%}
    {% set type = type|default('password') -%}
    {% set attr = attr|merge({
        class: (attr.class|default('') ~ ' form-input pr-10' ~ (errors|length ? ' form-input-error' : ''))|trim
    }) -%}
    <div class="relative" data-controller="password-toggle">
        <input type="{{ type }}" {{ block('widget_attributes') }} {% if value is not empty %}value="{{ value }}" {% endif %} data-password-toggle-target="input" />
        <button type="button"
                tabindex="-1"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 focus:outline-none focus:text-gray-600"
                aria-label="Zobrazit heslo"
                data-action="click->password-toggle#toggle">
            {# Eye icon — visible when password is hidden #}
            <svg data-password-toggle-target="showIcon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="h-5 w-5">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
            {# Eye-slash icon — visible when password is shown #}
            <svg data-password-toggle-target="hideIcon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="h-5 w-5 hidden">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            </svg>
        </button>
    </div>
{%- endblock password_widget %}
```

Notes for the implementer:
- `pr-10` on the input reserves space for the absolute-positioned button so typed text doesn't slide under the icon.
- `tabindex="-1"` on the button keeps the keyboard tab-order going from input → next field, not into the toggle button. Mouse and assistive tech can still activate it.
- The block name `password_widget` is the standard Symfony Form block name for `PasswordType` — Symfony picks it up automatically via the `{% use "form_div_layout.html.twig" %}` chain.
- Each `PasswordType` widget gets its own wrapper + its own controller instance, so each input toggles independently. `RepeatedType<PasswordType>` works automatically because Symfony renders the two children as two separate `password_widget` calls.

### 3. Login template

In `templates/user/login.html.twig`, replace the password `form-group` block (currently lines ~44–53) with the same wrapper markup as the form theme:

```twig
<div class="form-group">
    <label class="form-label">Heslo</label>
    <div class="relative" data-controller="password-toggle">
        <input
            type="password"
            name="_password"
            class="form-input pr-10"
            required
            autocomplete="current-password"
            data-password-toggle-target="input"
        >
        <button type="button"
                tabindex="-1"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 focus:outline-none focus:text-gray-600"
                aria-label="Zobrazit heslo"
                data-action="click->password-toggle#toggle">
            <svg data-password-toggle-target="showIcon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="h-5 w-5">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
            <svg data-password-toggle-target="hideIcon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="h-5 w-5 hidden">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            </svg>
        </button>
    </div>
</div>
```

(If you want to be DRY, lift this snippet into a small `templates/components/password_input.html.twig` partial and `{% include %}` it from both `login.html.twig` and the form theme. Optional — duplicate inline is also fine for this small surface.)

### 4. Verification (manual — no JS unit tests)

After the changes, walk through each of these and confirm the toggle works (icon swaps, input type swaps, focus stays on the input on toggle):

- `/login` — current password
- `/register` — new password + confirm
- `/registrace-pronajimatele` — new password + confirm
- `/reset-password/reset/<token>` — new password + confirm (use `bin/console` or trigger from "forgot password" flow against `user@example.com`)
- `/portal/profile/change-password` — current + new + confirm (3 inputs)
- `/objednavka/...` guest-checkout — `plainPassword` field (when ordering as a not-yet-registered email)
- `/portal/users/{id}/change-password` — once spec 002 is merged, no extra work needed; verify it inherits

## Acceptance

- `docker compose exec web composer quality` is green (touches no PHP that PHPStan/CS would care about, but run anyway).
- Every password input on the pages above has a clickable eye icon inside the input on the right. Clicking it toggles the input between `type=password` and `type=text` and swaps the eye / eye-slash icon.
- Aria-label updates on toggle (`Zobrazit heslo` ↔ `Skrýt heslo`).
- Tab order skips the toggle button (`tabindex="-1"`) — pressing Tab from the password field jumps to the next form field, not the icon.
- Each input on a multi-password page (e.g. change-password with 3 fields) toggles independently.
- No console errors; controller initializes via stimulus auto-loading.

## Out of scope

- Forcing the input back to `type=password` on form submit (browsers handle autofill correctly either way; not worth the complexity).
- A "Caps Lock is on" warning indicator.
- Strength meter / live validation.
- Rebuilding the login form to use Symfony Form (would be a separate refactor).
- Internationalisation — strings stay in Czech (matches every other label in the project).

## Open questions

None — proceed.
