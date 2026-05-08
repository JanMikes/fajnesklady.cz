# 033 — Per-place "Návod" document (admin-managed, post-payment customer attachment)

**Status:** done
**Type:** feature
**Scope:** small (~14 files: 1 entity field + migration + form/command pair tweaks + 2 controllers + 1 uploader method + 1 email handler + 4 templates + 1 query result + 1 entity test + CUSTOMER_DOCUMENTS.md)
**Depends on:** none

## Problem

Today every place can carry a single per-place document — the legally-tinged **Provozní řád** (`Place.operatingRulesPath`) — which gets attached to the post-payment "smlouva připravena" e-mail and is offered as a download on both customer order surfaces. Operators want a *second* per-place document, the **Návod** (practical instructions / how-to-use guide for the customer), governed by the same lifecycle but distinct in purpose: it's an operational courtesy, not a legal artefact, and it's edited by Fajnesklady admins only — landlords don't author it.

## Goal

Place gains an optional `instructionsPath`. Admins (and only admins) see a "Návod pro zákazníky" file field in the place create/edit form. When uploaded, the document:

1. is attached to the post-payment `email/contract_ready.html.twig` (handler: `SendContractReadyEmailHandler`), file-named `navod.pdf` / `navod.docx`,
2. shows up as a download row in both customer order documents components (`templates/components/order_documents.html.twig` for the authenticated portal detail, `templates/components/order_status_documents.html.twig` for the public `/stav` permalink),
3. surfaces a visual "missing" indicator on the place lists (admin + landlord) and a setup-health alert on the per-place dashboard — exactly the same affordance pattern used today for `operatingRulesPath`.

The Návod is **not** part of the order-acceptance legal-consent block (`order_accept.html.twig`) — it's operational, not contractual. See *Out of scope*.

## Context (current state)

The Provozní řád flow is the structural template; we mirror it field-for-field. Reference points:

- `src/Entity/Place.php:29` — `?string $operatingRulesPath` column (length 500), `hasOperatingRules()` helper (`:113`), `updateOperatingRules(?string, \DateTimeImmutable): void` (`:118`).
- `src/Form/PlaceFormData.php:48–55` — `?UploadedFile $operatingRulesDocument` (PDF/DOCX, max 10 MB) + `?string $currentOperatingRulesPath` mirror, populated from `Place` in `fromPlace()` (`:97`).
- `src/Form/PlaceFormType.php:112–119` — file field registration, `accept` attribute restricting to `application/pdf` / `.docx`.
- `src/Service/PlaceFileUploader.php:22` — `uploadOperatingRules(UploadedFile, Uuid): string`, writes to `places/{placeId}/operating-rules/{slug}-{uniqid}.{ext}`. The relative path returned is what gets stored on the entity.
- `src/Command/CreatePlaceCommand.php:21` and `src/Command/UpdatePlaceCommand.php:21` — `?string $operatingRulesPath` carried across the bus.
- `src/Command/CreatePlaceHandler.php:40` and `src/Command/UpdatePlaceHandler.php:39` — both *only* call `updateOperatingRules()` when the command's path is non-null (so a missing upload never clears an existing file). We keep this semantic — there is no "clear" button today.
- `src/Controller/Portal/PlaceCreateController.php:49–52` (admin-gated via `#[IsGranted('ROLE_ADMIN')]`) and `src/Controller/Portal/PlaceEditController.php:51–56` (landlord-gated) — both run the upload, delete the previous file on edit, and pass the path into the command.
- `src/Event/SendContractReadyEmailHandler.php:44–108` — resolves the absolute path under `$uploadsDirectory`, sets `hasOperatingRulesAttachment` template flag, attaches the file under a normalized name `provozni_rad.{ext}`. The handler stays the same shape; we add a parallel block.
- `templates/email/contract_ready.html.twig:134-138, 171` — flags `hasOperatingRulesAttachment` and mentions the rules in the body + the "Co najdete v příloze" enumeration.
- `templates/components/order_documents.html.twig:69–82` and `templates/components/order_status_documents.html.twig:50–72` — render a "Pobočka" group when there is *anything* place-specific. The status template already wraps under `{% if vm.place.operatingRulesPath or vm.mapDownloadUrl %}` — we extend the gate.
- `src/Query/GetPlaceDashboardStatsQuery.php:65` and `src/Query/GetPlaceDashboardStatsResult.php:27` — `bool $missingOperatingRules` is computed admin-side (`null === $owner`). Templates `templates/portal/place/list.html.twig:43–49`, `templates/admin/place/list.html.twig:30–36`, and `templates/portal/place/detail.html.twig:66–75` all read this flag (or `place.hasOperatingRules` directly on list rows) and render an amber triangle / health alert.
- `templates/portal/place/edit.html.twig:153–171` and `templates/portal/place/create.html.twig:122–127` — file-field + "Nahráno / Zobrazit" preview row pattern.
- `tests/Unit/Entity/PlaceTest.php:290–330` — entity-level coverage for default-null + update-then-set; mirror this for instructions.
- `tests/Unit/Event/SendContractReadyEmailHandlerTest.php:80–215` — already parameterizes a `?string $operatingRulesPath` through `createContract()`; we extend the helper to also seed an instructions path for the new attachment assertion.
- `.claude/CUSTOMER_DOCUMENTS.md` — inventory file (`#8 Provozní řád pobočky`) and email-touchpoints table both name the operating-rules attachment; **per its own "Adding a new document type" checklist** we add a row for Návod.

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Place entity                                                     │
│   NEW column:   instructions_path VARCHAR(500) DEFAULT NULL      │
│   NEW property: ?string $instructionsPath                        │
│   NEW helpers:  hasInstructions(): bool                          │
│                 updateInstructions(?string, \DateTimeImmutable)  │
└──────────────────────────────────────────────────────────────────┘
        │
        │ (write path: identical shape to operatingRulesPath)
        ▼
PlaceFormData.instructionsDocument  ──►  PlaceFileUploader.uploadInstructions
        │                                       │
        ▼                                       ▼
{Create,Update}PlaceCommand.instructionsPath    places/{id}/instructions/...
        │
        ▼
{Create,Update}PlaceHandler ──► Place.updateInstructions(...)

Read paths:
  – SendContractReadyEmailHandler  → attaches `navod.{ext}` if file exists
  – order_documents.html.twig       → "Pobočka" group adds a row
  – order_status_documents.html.twig→ same, gate widened
  – place list templates            → second amber-triangle next to existing
  – place detail health alerts      → second alert card
  – GetPlaceDashboardStatsResult    → NEW bool $missingInstructions
```

The Návod field is **admin-only**: `PlaceFormType` gains a new bool option `is_admin` (default `false`); the `instructionsDocument` field is only registered when `true`. Both controllers pass `is_admin: $this->isGranted('ROLE_ADMIN')`. (Provozní řád stays unchanged — landlords can still upload it, this is not a refactor.)

## Requirements

### 1. Entity — `src/Entity/Place.php`

Add the column next to the existing operating-rules pair:

```php
#[ORM\Column(length: 500, nullable: true)]
public private(set) ?string $instructionsPath = null;
```

Add helpers next to `hasOperatingRules()` / `updateOperatingRules()`:

```php
public function hasInstructions(): bool
{
    return null !== $this->instructionsPath;
}

public function updateInstructions(?string $instructionsPath, \DateTimeImmutable $now): void
{
    $this->instructionsPath = $instructionsPath;
    $this->updatedAt = $now;
}
```

### 2. Migration

Generate via `docker compose exec web bin/console make:migration` — never handwritten. The expected diff is a single `ALTER TABLE place ADD instructions_path VARCHAR(500) DEFAULT NULL` (mirror `Version20260326121528.php`).

### 3. Form — `src/Form/PlaceFormData.php` + `PlaceFormType.php`

`PlaceFormData`:

```php
#[Assert\File(
    maxSize: '10M',
    mimeTypes: ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    mimeTypesMessage: 'Nahrajte dokument ve formátu PDF nebo DOCX',
)]
public ?UploadedFile $instructionsDocument = null;

public ?string $currentInstructionsPath = null;
```

In `fromPlace()` add `$formData->currentInstructionsPath = $place->instructionsPath;`.

`PlaceFormType`:

- In `configureOptions`, add `$resolver->setDefault('is_admin', false)` and `$resolver->setAllowedTypes('is_admin', 'bool')`.
- In `buildForm`, only register the field when `$options['is_admin']`:

```php
if ($options['is_admin']) {
    $builder->add('instructionsDocument', FileType::class, [
        'label' => 'Návod pro zákazníky',
        'required' => false,
        'attr' => [
            'accept' => 'application/pdf,.pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'help' => 'Praktický návod pro zákazníky (PDF nebo DOCX, max 10 MB). Po platbě je přiložen k potvrzovacímu e-mailu.',
    ]);
}
```

### 4. Uploader — `src/Service/PlaceFileUploader.php`

Add a tiny method mirroring `uploadOperatingRules`:

```php
public function uploadInstructions(UploadedFile $file, Uuid $placeId): string
{
    return $this->uploadFile($file, $placeId, 'instructions');
}
```

Resulting path shape: `places/{placeId}/instructions/{safe-slug}-{uniqid}.{ext}`.

### 5. Commands — `Create*PlaceCommand` + handlers

Both `CreatePlaceCommand` and `UpdatePlaceCommand`: append `public ?string $instructionsPath = null` after `operatingRulesPath`. Both `Create*PlaceHandler` and `UpdatePlaceHandler`: add the parallel `if (null !== $command->instructionsPath) { $place->updateInstructions($command->instructionsPath, $now); }` immediately after the operating-rules block. Same null-keeps-existing semantic.

### 6. Controllers

`src/Controller/Portal/PlaceCreateController.php` — already admin-gated. Pass `is_admin: true` to `createForm` and run an upload block parallel to the operating-rules one:

```php
$form = $this->createForm(PlaceFormType::class, options: ['is_admin' => true]);

// …

$instructionsPath = null;
if (null !== $formData->instructionsDocument) {
    $instructionsPath = $this->fileUploader->uploadInstructions($formData->instructionsDocument, $placeId);
}
```

…and pass `instructionsPath: $instructionsPath` into `CreatePlaceCommand`.

`src/Controller/Portal/PlaceEditController.php` — landlord-gated. Pass `is_admin: $this->isGranted('ROLE_ADMIN')` so non-admin landlords don't see the field. The upload block:

```php
$instructionsPath = null;
if (null !== $formData->instructionsDocument) {
    $this->fileUploader->deleteFile($place->instructionsPath);
    $instructionsPath = $this->fileUploader->uploadInstructions($formData->instructionsDocument, $place->id);
}
```

…then pass into `UpdatePlaceCommand`.

(Note: because `instructionsDocument` is only registered when `is_admin`, a landlord posting a manually-crafted multipart with that field name still gets `null` — Symfony forms drop unmapped data.)

### 7. E-mail attachment — `src/Event/SendContractReadyEmailHandler.php`

Add a sibling block to the existing `$operatingRulesPath` resolution:

```php
$instructionsPath = null;
if ($place->hasInstructions() && null !== $place->instructionsPath) {
    $candidate = $this->uploadsDirectory.'/'.$place->instructionsPath;
    if (file_exists($candidate)) {
        $instructionsPath = $candidate;
    }
}
```

Add to the template context: `'hasInstructionsAttachment' => null !== $instructionsPath,`.

After the existing operating-rules attach block:

```php
if (null !== $instructionsPath) {
    $extension = pathinfo($instructionsPath, PATHINFO_EXTENSION);
    $email->attachFromPath(
        $instructionsPath,
        'navod.'.$extension,
    );
}
```

### 8. E-mail body — `templates/email/contract_ready.html.twig`

Right after the `hasOperatingRulesAttachment` paragraph (line 134–138), add:

```twig
{% if hasInstructionsAttachment %}
<p style="font-size: 14px; color: #4b5563; margin-top: 8px;">
    Přiložen je také <strong>návod pro zákazníky pobočky {{ placeName }}</strong> s praktickými informacemi pro užívání skladu.
</p>
{% endif %}
```

Update the enumeration on line 171 — append `{% if hasInstructionsAttachment %}, návod pro zákazníky{% endif %}`.

### 9. Customer document downloads

`templates/components/order_documents.html.twig` — extend the "Pobočka" gate from `{% if place.operatingRulesPath %}` to `{% if place.operatingRulesPath or place.instructionsPath %}` and add a second `documentRow`:

```twig
{% if place.instructionsPath %}
    {{ _self.documentRow(
        upload_url(place.instructionsPath),
        'Návod pro zákazníky',
        'Praktický návod k používání skladu a pobočky',
        'pdf'
    ) }}
{% endif %}
```

`templates/components/order_status_documents.html.twig` — widen line 50's gate to `{% if vm.place.operatingRulesPath or vm.place.instructionsPath or vm.mapDownloadUrl %}` and insert the same row inside the `<ul>`. The view-model factory needs no change — `vm.place` is the Place entity itself, so `vm.place.instructionsPath` flows through automatically.

### 10. Setup-health flag — `GetPlaceDashboardStats*`

`src/Query/GetPlaceDashboardStatsQuery.php` — add right after `$missingOperatingRules`:

```php
$missingInstructions = null === $owner && null === $place->instructionsPath;
```

Pass through to the result and pass it into the constructor call.

`src/Query/GetPlaceDashboardStatsResult.php` — add `public bool $missingInstructions` next to `$missingOperatingRules`.

### 11. Visual "missing" indicators

`templates/portal/place/list.html.twig` — after the existing operating-rules amber triangle (line 43–49), add a second triangle on the same `<td>`, gated on `not place.hasInstructions`:

```twig
{% if not place.hasInstructions %}
    <span class="inline-flex items-center ml-2 text-amber-600" title="Chybí nahrát návod pro zákazníky">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
    </span>
{% endif %}
```

`templates/admin/place/list.html.twig` — paste the same block after line 36.

`templates/portal/place/detail.html.twig` — extend the health-alert outer condition (line 66) to include `stats.missingInstructions`, then add a fifth `_health_alert.html.twig` include between the operating-rules and map alerts:

```twig
{% if stats.missingInstructions %}
    {{ include('portal/place/_health_alert.html.twig', {
        title: 'Chybí nahrát návod pro zákazníky',
        detail: 'Návod je po platbě připojen k potvrzovacímu e-mailu zákazníka.',
        ctaUrl: path('portal_places_edit', {id: place.id}),
        ctaLabel: 'Nahrát v nastavení místa',
    }) }}
{% endif %}
```

### 12. Form view — admin only sees the new field

`templates/portal/place/edit.html.twig` — after the existing operating-rules block (line 153–171), add an identical block keyed on `form.instructionsDocument is defined`. Only admins reach this branch because the controller passes `is_admin: false` for non-admins:

```twig
{% if form.instructionsDocument is defined %}
    <div class="mb-4">
        <label for="{{ form.instructionsDocument.vars.id }}" class="block text-sm font-semibold text-gray-700 mb-2">Návod pro zákazníky</label>
        {% if form.vars.data.currentInstructionsPath %}
            <div class="mb-2 p-3 bg-gray-50 rounded-lg flex items-center justify-between">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-green-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-sm text-gray-700">Nahráno</span>
                </div>
                <a href="{{ upload_url(form.vars.data.currentInstructionsPath) }}" target="_blank" class="link text-sm">
                    Zobrazit
                </a>
            </div>
        {% endif %}
        {{ form_widget(form.instructionsDocument, {}) }}
        <p class="mt-1 text-sm text-gray-500">Praktický návod pro zákazníky (PDF nebo DOCX, max 10 MB). Po platbě je přiložen k potvrzovacímu e-mailu.</p>
        {{ form_errors(form.instructionsDocument) }}
    </div>
{% endif %}
```

`templates/portal/place/create.html.twig` — paste the same `{% if form.instructionsDocument is defined %}` block right after the operating-rules block (line 122–127). Create is already admin-only, so the field will always be present; the `is defined` guard keeps the diff between create/edit identical.

### 13. Tests

- `tests/Unit/Entity/PlaceTest.php` — add `testDefaultInstructionsIsNull` and `testUpdateInstructions`, structurally mirroring the existing operating-rules pair (lines 290–330).
- `tests/Unit/Event/SendContractReadyEmailHandlerTest.php` — extend the `createContract()` helper signature with `?string $instructionsPath = null`, seed it via `$place->updateInstructions(...)`, and add one new test asserting that when `$instructionsPath` is set, the sent `Email` has an attachment whose filename equals `navod.pdf` (or `.docx` for the DOCX path), parallel to the existing operating-rules attachment test (lines 60–150).

### 14. Documentation — `.claude/CUSTOMER_DOCUMENTS.md`

Add inventory row #11 between current row #10 and the email-touchpoints table:

```
| 11 | Návod pro zákazníky (PDF/DOCX) | per-place upload (admin-only) | `public/uploads/{Place.instructionsPath}` | `upload_url()` Twig helper — only when `place.hasInstructions()` |
```

Update the `email/contract_ready.html.twig` row of the email-touchpoints table to add `, návod pro zákazníky (if any)` to the Attachments column.

## Acceptance

- `composer quality` is green.
- Visiting `/portal/places/create` as admin shows a "Návod pro zákazníky" file field; as a landlord (non-admin) the field is absent.
- Visiting `/portal/places/{id}/edit` as admin shows the field with the "Nahráno / Zobrazit" preview row when a file exists; as a landlord without admin role the field is absent.
- Uploading a PDF on create and edit persists `instructions_path` under `places/{id}/instructions/…`; replacing it on edit deletes the previous file.
- After `OrderCompleted` for an order at a place that has an instructions document, the resulting e-mail (capture via `SendContractReadyEmailHandlerTest`) carries an attachment named `navod.pdf` (or `.docx`).
- `/portal/objednavky/{id}` and `/objednavka/{id}/stav?_hash=…` (signed URL) for a paid/completed order at a place with `instructionsPath` set show a "Návod pro zákazníky" download row inside the "Pobočka" group.
- `/portal/places` (admin & landlord), `/portal/admin/places`, and `/portal/places/{id}` (admin) display an amber triangle / health alert on places that have no instructions document, independent of operating-rules state.
- `/portal/places/{id}` as landlord (non-admin) does **not** show the new health alert (matches `null === $owner` gate already used for `missingOperatingRules`).
- `Place` entity unit tests cover default-null and update-then-set for `instructionsPath`.
- `bin/console doctrine:schema:validate` is clean.

## Out of scope

- **Order acceptance / legal consent block** (`templates/public/order_accept.html.twig`, lines 359–378). Provozní řád is referenced there because it carries quasi-legal weight (rules tenants must follow). Návod is operational guidance; including it as a checkbox-acknowledged document would muddle the legal surface and trigger unnecessary `accept_*` plumbing in `OrderAcceptController`. Skip.
- **Order confirmation e-mail** (`SendOrderConfirmationEmailHandler` / `email/order_confirmation.html.twig`). That e-mail fires *pre-payment* on `OrderPlaced` and ships strictly contractual artefacts (smlouva, VOP, poučení, podmínky opakovaných plateb). Návod belongs to the post-payment "rental active" e-mail (`contract_ready`), matching Provozní řád.
- **Public order success page** (`templates/public/order_complete.html.twig`). It already includes `components/order_documents.html.twig`, so the new download row appears there for free — no separate change.
- **Landlord ability to upload Návod.** The user explicitly scoped this to admin only; landlords still own Provozní řád. We do not unify the two surfaces today.
- **"Clear / remove file" UI** for either Provozní řád or Návod. The current Provozní řád has no clear button (handler only acts on non-null paths); we keep the same affordance for Návod. Adding it is a separate UX task touching both fields uniformly.
- **Renaming the e-mail template/handler.** `CUSTOMER_DOCUMENTS.md` already flags `contract_ready` → `rental_active` as a future improvement; out of scope here to limit blast radius.
- **Excel-export columns.** Admin/landlord place exports don't enumerate per-place document presence today; not changing that surface.
- **Storing a custom display name / version.** Návod is a single file with a fixed customer-facing label; no metadata layer needed.

## Open questions

None — proceed.
