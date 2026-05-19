# 041 — Handover email links: public signed URL for tenant, login-aware redirect for landlord/admin

**Status:** done
**Type:** feature / UX (security model split — new public signed route + kernel exception listener)
**Scope:** medium (~14 files: 1 new generator, 1 new public controller, 1 new exception listener, 4 email handlers updated, 1 new template, 1 register-routes touch, 2 unit/integration tests)
**Depends on:** none — reuses the UriSigner pattern from spec 020

## Problem

The "Předávací protokol — prosím vyplňte" email is the only touchpoint we have for a customer at the end of a rental. Today's link points at `/portal/predavaci-protokol/{id}` (`portal_user_handover_view`, gated by `#[IsGranted('ROLE_USER')]` + `HandoverProtocolVoter::VIEW`). That route is functionally unreachable for the tenant in the most common case:

- The customer ordered without choosing a password (`GetOrCreateUserByEmailHandler` creates a passwordless `User` row — `password: null`). They were never sent verification credentials, never set a password, and have **no way to log in**.
- They click the email link, Symfony's `^/portal` access_control redirects them to `/login`, they have no credentials → dead end.
- Even when they have a password but are currently logged out, the redirect-to-login → login → bounce-back chain is fragile (target_path can desync), and they perceive the experience as broken.

The landlord/admin link to `/portal/pronajimatel/predavaci-protokol/{id}` has a different but related failure: a hard `AccessDeniedHttpException` (403) is rendered when **(a)** the firewall lets the user through `^/portal` because they're authenticated as ROLE_USER but the `IsGranted('ROLE_LANDLORD')` controller attribute denies, or **(b)** they're a landlord but on a different account than the storage owner (`HandoverProtocolVoter::VIEW` returns false). The user lands on a Czech 403 page with no path forward; the expected UX is "send me to login so I can sign in with the right account".

## Goal

Two distinct security models, one per audience:

1. **Tenant link → public, signature-authorized.** Email handler mints a `UriSigner`-signed URL at `/predavaci-protokol/{id}?_hash=…`. The signature **is** the authorization — anyone holding the link can view the protocol and fill the tenant section (photos, comment, confirmation). Identical threat model to the existing `/podpis/{token}` and `/objednavka/{id}/stav` flows. Whether the customer is logged in or not, the same link works.

2. **Landlord/admin link → still login-required, but no dead-end 403.** Anonymous, wrong-role, or wrong-account access to `/portal/pronajimatel/predavaci-protokol/{id}` (and its sister generate-code endpoint, and the legacy `/portal/predavaci-protokol/{id}`) is intercepted by a kernel exception listener that:
   - Force-clears the current session token via `Security::logout()` if one is set, so the login form actually renders instead of bouncing on `LoginController`'s "already logged in → home" branch.
   - Redirects to `/login?_target_path=<current-url>` so the standard form_login flow lands the user back on the handover page after a successful login.

The two route families coexist: the old `/portal/predavaci-protokol/{id}` stays for navigation from inside the portal (e.g. order detail page), but emails no longer point at it.

## Context (current state)

### Things this spec extends

- `src/Controller/Portal/User/HandoverViewController.php` (route `portal_user_handover_view` at `/portal/predavaci-protokol/{id}`) — kept as-is for in-portal navigation; **no longer the email destination**.
- `src/Controller/Portal/LandlordHandoverViewController.php` (route `portal_landlord_handover_view` at `/portal/pronajimatel/predavaci-protokol/{id}`) — kept as-is; new listener intercepts its `AccessDeniedException`.
- `src/Controller/Portal/LandlordHandoverGenerateCodeController.php` (route `portal_landlord_handover_generate_code`) — same listener also covers this POST endpoint.
- `src/Event/SendHandoverRequestToTenantHandler.php:34-38` — currently calls `$urlGenerator->generate('portal_user_handover_view', …)`. Switches to the new signed URL generator.
- `src/Event/SendHandoverReminderToTenantHandler.php:39-43` — same swap.
- `src/Event/SendHandoverRequestToLandlordHandler.php:38-42` — **no change** (landlord URL is still the portal route; the exception listener does the redirecting).
- `src/Event/SendHandoverReminderToLandlordHandler.php:43-47` — same, no change.

### Existing primitives we reuse

- **`Symfony\Component\HttpFoundation\UriSigner`** — already wired as a service. Canonical use sites: `src/Service/OrderStatusUrlGenerator.php` (spec 020) and `src/Service/RecurringPaymentCancelUrlGenerator.php` (mints `$uriSigner->sign($url)`; consumers verify with `$uriSigner->checkRequest($request)`). Backed by `%kernel.secret%`. The signature covers the full URL including query — never append params after signing.
- **`Symfony\Bundle\SecurityBundle\Security`** — already used in `src/Service/AuditLogger.php`. Exposes `logout(bool $validateCsrfToken = false): ?Response`, which clears the token storage and invalidates the session.
- **`HandoverProtocolRepository::get(Uuid): HandoverProtocol`** — throws `HandoverProtocolNotFound` (`#[WithHttpStatus(404)]`) if missing. Reuse as-is.
- **`AddHandoverPhotoCommand` + `CompleteTenantHandoverCommand`** — already dispatched from `HandoverViewController`. Identical dispatch in the new public controller.
- **`TenantHandoverFormType` + `TenantHandoverFormData`** — Symfony forms work fine on public routes (CSRF token sits in session, no auth required).
- **`HandoverProtocolVoter::COMPLETE_TENANT`** — **not used** by the new public controller (signature is the authorization). The voter remains in place for the legacy `portal_user_handover_view` route.
- **`templates/portal/user/handover/view.html.twig`** — the rendering shell. The new public template extends a different layout (no portal sidebar) but reuses the same form / photo grid markup.

### How the firewall behaves today (relevant for the listener design)

- `config/packages/security.php:55` — `['path' => '^/portal', 'roles' => 'ROLE_USER']`. An **anonymous** request to any `/portal/*` URL triggers `AccessDeniedException` at the access_control stage; the `form_login` entry point converts it to a 302 → `/login` with `_security.main.target_path` saved in the session. **This case already works** — anonymous landlords get the right behavior automatically. The listener's job is the **already-authenticated** branches.
- `#[IsGranted('ROLE_LANDLORD')]` on `LandlordHandoverViewController` fires at `kernel.controller_arguments` (after the firewall lets ROLE_USER through). A ROLE_USER-only customer who somehow opens the landlord URL hits this and 403s. The listener catches that.
- `denyAccessUnlessGranted(HandoverProtocolVoter::VIEW, $protocol)` inside the controller fires after `IsGranted`. A different-landlord-than-owner case 403s here. Same listener catches it.
- `LoginController` (`src/Controller/LoginController.php:14`) — `if ($this->getUser()) { return $this->redirectToRoute('app_home'); }`. **This is why a plain `/login` redirect for a still-authenticated wrong-account user fails today** — they bounce home. The listener must call `Security::logout()` first to clear the token.

### Token strategy — why UriSigner (not a per-row token)

Same calculus as spec 020 §"Security model — token strategy decision". Three options were evaluated for the tenant route:

| Option | Mechanism | Pros | Cons |
|---|---|---|---|
| **A. UriSigner (HMAC-signed URL)** ✅ | `?_hash=…` over the full URL | Stateless · zero migration · existing pattern · stable per-protocol URL · negligible code | Cannot revoke a single leaked URL — only global `APP_SECRET` rotation |
| B. `HandoverProtocol::$accessToken` column | 32-byte hex token, lookup by token | Per-row revocation | Migration · token-lookup branch · still need a URL minter · doesn't beat A on threat model |
| C. Opaque slug (Hashids) | Bijective short ID | Pretty URL | Same revocation profile as A · adds dependency · ergonomically worse |

**Decision: A.** Identical to spec 020. A leaked email-link is the customer's responsibility (mirror of password-reset link semantics). No expiry on the link — the handover protocol becomes a no-op once `protocol.tenantCompletedAt !== null` (already enforced in `HandoverProtocol::completeTenantSide()`), so a stale link can't double-fill.

## Architecture

```
Tenant flow (NEW — public, no auth)
─────────────────────────────────────────
Email handler              Public\HandoverViewController
   │  (signed URL)            │  (UriSigner::checkRequest)
   └────────────┬─────────────┘
                │
                ▼
       Render or POST → AddHandoverPhotoCommand × N
                       → CompleteTenantHandoverCommand
                       → 302 back to same signed URL

Landlord/admin flow (UNCHANGED routes, NEW listener)
─────────────────────────────────────────
Email handler              Portal\LandlordHandoverViewController
   │  (portal URL)            │  (IsGranted + voter)
   └────────────┬─────────────┘
                │ AccessDeniedException
                ▼
     HandoverAccessDeniedListener (kernel.exception, priority high)
                │
                ├─ if authenticated: Security::logout() to clear session
                ▼
       302 → /login?_target_path=<current-url>
                │
                ▼
     form_login auth → redirect back to handover URL
```

## Requirements

### 1. `App\Service\Handover\HandoverUrlGenerator` (new)

Mirrors `App\Service\OrderStatusUrlGenerator` 1:1. Single method (room to grow if landlord ever also needs a signed link, though out of scope here).

```php
namespace App\Service\Handover;

use App\Entity\HandoverProtocol;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class HandoverUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generateTenantView(HandoverProtocol $protocol): string
    {
        $url = $this->urlGenerator->generate(
            'public_handover_view',
            ['id' => $protocol->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }
}
```

Wired by autowiring; no manual `services.php` entry needed.

### 2. `App\Controller\Public\HandoverViewController` (new)

New file `src/Controller/Public/HandoverViewController.php`. Route is **outside `/portal`** so the firewall's `^/portal` access_control doesn't apply.

```php
#[Route('/predavaci-protokol/{id}', name: 'public_handover_view', requirements: ['id' => '[0-9a-f-]{36}'])]
final class HandoverViewController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly UriSigner $uriSigner,
        #[Autowire(service: 'command.bus')]
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));
        // No voter check — the signature IS the authorization.

        $contract = $protocol->contract;
        $storage = $contract->storage;
        $place = $storage->getPlace();

        $formData = new TenantHandoverFormData();
        $form = $this->createForm(TenantHandoverFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $protocol->needsTenantCompletion()) {
            $photos = $request->files->get('photos', []);
            foreach ($photos as $photo) {
                $this->commandBus->dispatch(new AddHandoverPhotoCommand(
                    handoverProtocolId: $protocol->id,
                    file: $photo,
                    uploadedBy: 'tenant',
                ));
            }

            $this->commandBus->dispatch(new CompleteTenantHandoverCommand(
                handoverProtocolId: $protocol->id,
                comment: $formData->comment,
            ));

            $this->addFlash('success', 'Předávací protokol byl úspěšně vyplněn.');

            // Re-mint the signed URL — the redirect target must carry a fresh _hash
            // because the bare path won't validate. UrlGeneratorInterface + UriSigner.
            $signedUrl = $this->uriSigner->sign(
                $this->generateUrl('public_handover_view', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
            );

            return $this->redirect($signedUrl);
        }

        return $this->render('public/handover_view.html.twig', [
            'protocol' => $protocol,
            'contract' => $contract,
            'storage' => $storage,
            'place' => $place,
            'form' => $form,
            'canComplete' => $protocol->needsTenantCompletion(),
        ]);
    }
}
```

**Notes for the dev:**
- POST requests carry `?_hash=…` in the query string (same URL the form was rendered on). `UriSigner::checkRequest` validates against the full request URL including query — works for both GET and POST without any extra body fields.
- The redirect-after-POST uses a freshly-signed absolute URL (not `redirectToRoute`, which would drop the hash). Mirror this exactly.
- The `canComplete` flag is now purely "still needs tenant completion" — no voter logic. Once `tenantCompletedAt !== null`, the form section disappears and the page becomes read-only summary (template detail in §5).
- `Order::isExpired($now)` / similar expiry checks are **not** added — `HandoverProtocol::completeTenantSide()` already throws if the tenant has filled it; that's the only relevant idempotency.

### 3. `App\Service\Security\HandoverAccessDeniedListener` (new)

New file `src/Service/Security/HandoverAccessDeniedListener.php`. Subscribes to `KernelEvents::EXCEPTION`. Scoped strictly to the three portal handover routes — must not interfere with any other 403 in the app.

```php
namespace App\Service\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class HandoverAccessDeniedListener
{
    private const array HANDLED_ROUTES = [
        'portal_landlord_handover_view',
        'portal_landlord_handover_generate_code',
        'portal_user_handover_view',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof AccessDeniedException) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!in_array($route, self::HANDLED_ROUTES, true)) {
            return;
        }

        // Wrong-account case: clear the current token so /login renders the
        // form instead of bouncing on LoginController's "already logged in" branch.
        if (null !== $this->security->getUser()) {
            $this->security->logout(validateCsrfToken: false);
        }

        $loginUrl = $this->urlGenerator->generate(
            'app_login',
            ['_target_path' => $request->getUri()],
        );

        $event->setResponse(new RedirectResponse($loginUrl));
    }
}
```

**Notes for the dev:**
- Priority `16` runs **before** Symfony's `ExceptionListener` (priority `-50` in `security-http`) which would otherwise also try to convert the AccessDeniedException to a 403 response or to a re-auth via the entry point. Confirm by checking `debug:event-dispatcher kernel.exception` after adding — our listener should sit above `Symfony\Component\Security\Http\Firewall\ExceptionListener`.
- The listener **only** handles `Security\Core\Exception\AccessDeniedException` (the Symfony-internal one thrown by `IsGranted`, `denyAccessUnlessGranted`, and voters). It does NOT match `HttpKernel\Exception\AccessDeniedHttpException` — the signed-URL controller throws the HTTP one for a bad signature, and we don't want to logout+redirect users with an invalid signed link to a login page. If the dev finds the path needs widening (e.g. some future controller throws the HTTP variant), do it explicitly with a comment — don't broaden silently.
- For the anonymous landlord case (firewall already redirects to `/login` via the access_control entry point), this listener never fires — the firewall converts the exception to a redirect at an earlier stage. That's the desired no-op.
- `Security::logout(validateCsrfToken: false)` skips the CSRF token check (we're not on the `/logout` route, so there's no logout token in the request). It clears the token storage and invalidates the session.

### 4. `SendHandoverRequestToTenantHandler` + `SendHandoverReminderToTenantHandler` — switch URL source

Both handlers swap `UrlGeneratorInterface` for `HandoverUrlGenerator`:

```php
public function __construct(
    private HandoverProtocolRepository $handoverProtocolRepository,
    private MailerInterface $mailer,
    private HandoverUrlGenerator $handoverUrlGenerator,  // was UrlGeneratorInterface
    private LoggerInterface $logger,
) {
}

// in __invoke:
$handoverUrl = $this->handoverUrlGenerator->generateTenantView($protocol);
```

No template changes needed — `templates/email/handover_request_tenant.html.twig:55` and `templates/email/handover_reminder_tenant.html.twig` already render `{{ handoverUrl }}` opaquely.

**Landlord email handlers (`SendHandoverRequestToLandlordHandler`, `SendHandoverReminderToLandlordHandler`) are untouched.** Their URL still points at `portal_landlord_handover_view`; the new listener handles 403 → login redirects in-band.

### 5. `templates/public/handover_view.html.twig` (new)

Standalone public page — does **not** extend `portal/layout.html.twig` (which assumes auth + sidebar). Use `base.html.twig` as the parent (same as `templates/public/order_status.html.twig` from spec 020). Markup re-uses the form / photo grid blocks from `templates/portal/user/handover/view.html.twig`:

- Header: `Předávací protokol` + place name + storage number.
- Status pill (same map as in the portal template).
- "Informace o skladu" card (place, storage number, end date).
- Landlord-side read-only summary if `protocol.landlordCompletedAt` is set.
- Tenant form block (photos, comment, confirm checkbox) when `canComplete` and `!protocol.tenantCompletedAt`.
- Read-only tenant summary when `protocol.tenantCompletedAt` is set.
- Flash success on completion.

No portal-style breadcrumb. No nav. Include the Fajnesklady identification block at the bottom (mirror what spec 020's `order_status.html.twig` does — Mekmann s.r.o., IČO, sídlo). Keep the same Czech labels with full diacritics ("Předávací protokol", "Vyplnit", "Odeslat předávací protokol", etc.).

Acceptance: copy the relevant sections from `templates/portal/user/handover/view.html.twig:18-135` verbatim and re-wrap; do not invent new wording.

### 6. Out-of-scope removals

The legacy `portal_user_handover_view` route stays. It's still reachable from `templates/portal/user/order/detail.html.twig` (or wherever the in-portal "Předávací protokol" link sits — grep for `portal_user_handover_view` to confirm the exact callers). Anonymous access to it already redirects to login via the firewall; wrong-account access now redirects via the new listener.

## Acceptance

- [ ] `composer quality` is green.
- [ ] `composer test` is green (full suite — controller/email integration tests live there).
- [ ] New `HandoverUrlGenerator` returns a URL of shape `http://localhost/predavaci-protokol/{uuid}?_hash=…` in a unit test; signature round-trips through `UriSigner::checkRequest`.
- [ ] Public controller: integration test asserts (a) GET without `_hash` → 403, (b) GET with valid `_hash` → 200 with the form rendered, (c) POST with `_hash` + valid form payload completes the tenant side (assert `protocol.tenantCompletedAt !== null` via DB), (d) re-GET after completion renders the read-only summary, no form.
- [ ] Listener: integration test against `LandlordHandoverViewController` — logged in as `user@example.com` (ROLE_USER only) hitting the landlord URL → 302 to `/login?_target_path=<original>`, **and** the session no longer carries the user token (subsequent GET to `/portal` redirects to login, not 403).
- [ ] Listener: integration test against `LandlordHandoverViewController` — logged in as `landlord2@example.com` (ROLE_LANDLORD but not the owner of the storage in question) → same 302 + session cleared.
- [ ] Anonymous request to landlord URL still redirects to `/login` (via firewall, listener not involved — sanity check, not a new test).
- [ ] Manual smoke (Docker, `docker compose exec web …`): trigger handover protocol creation in dev (via `ProcessHandoverProtocolsCommand` or fixtures), open the tenant email from MailHog, click → see the public page with the form, fill + submit → success flash, DB row updated.
- [ ] `composer phpstan` clean — `Security::logout()` has a nullable return; the listener discards it intentionally.

## Out of scope

- **Signed URL for landlord/admin.** User confirmed: landlord/admin must require login. The listener is the right tool; we do not move them to a signed flow.
- **Token revocation / link expiry for the tenant signed URL.** Same posture as spec 020 (`/objednavka/{id}/stav`) and the password-reset link contract: leaked link = customer's responsibility. Idempotency comes from `HandoverProtocol::completeTenantSide()` which throws on second submit.
- **Auditing who clicked the tenant signed link.** No IP/UA logging on `public_handover_view`. Photo uploads via `AddHandoverPhotoCommand` already carry `uploadedBy: 'tenant'`; that's the only attribution we record. Add later if a real abuse case appears.
- **Refactoring `HandoverViewController` (the portal one) to share rendering with the new public controller via a partial.** Tempting but cosmetic. The two templates can drift independently — the portal one extends the sidebar layout, the public one is standalone. Keeping them as duplicates beats a premature abstraction over two 80-line templates.
- **Admin-targeted email link.** Today no email points to a handover URL for admins. The listener still covers `portal_landlord_handover_view` for admins (admins have `ROLE_LANDLORD` via role hierarchy and pass the voter via the `ROLE_ADMIN` short-circuit at `HandoverProtocolVoter.php:40-42`), so if an admin manually opens that URL anonymous they get redirected to login like landlords. No new admin route to add.
- **Modifying the existing `LoginController`'s "already logged in → home" behavior globally.** The listener's `Security::logout()` call is a localized fix; the rest of the app keeps the current redirect.

## Open questions

None — proceed.
