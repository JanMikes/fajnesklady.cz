# Project Map — Fajnesklady.cz

Reference for spec writing & implementation. Regenerate if structure drifts significantly. Generated 2026-05-19.

Stack: PHP 8.5 (Docker image `ghcr.io/thedevs-cz/php:8.5-fajnesklady`) · Symfony · Postgres 17 · FrankenPHP · Tailwind · Stimulus.

## Routes

### Public (unauthenticated)
- `/` → `HomeController` — landing
- `/register` → `RegisterController` — customer signup
- `/login` → `LoginController`
- `/logout` → `LogoutController`
- `/reset-password/request` → `RequestPasswordResetController`
- `/reset-password/reset/{token}` → `ResetPasswordController`
- `/verify-email` → `VerifyEmailController`
- `/verify-email/confirmation` → `VerifyEmailConfirmationController`
- `/resend-verification-email` → `ResendVerificationEmailController`
- `/registrace-pronajimatele` → `LandlordRegisterController` — landlord signup
- `/pronajimatel/cekani-na-overeni` → `LandlordAwaitingVerificationController`

### Public — Place browsing & ordering (Public\*)
- `/pobocka/{id}` → `PlaceDetailController`
- `/pobocka/{id}/cenik` → `PlacePricelistController` — placeholder rendered by the `${PRICELIST_URL}` token in VOP
- `/objednavka/{placeId}/{storageTypeId}/{storageId?}` → `OrderCreateController`
- `/objednavka/{placeId}/{storageTypeId}/{storageId}/prijmout` → `OrderAcceptController`
- `/objednavka/prodlouzit/{previousOrderId}` → `OrderRenewController` — one-click renew from finished/expiring order
- `/objednavka/{id}/platba` → `OrderPaymentController`
- `/objednavka/{id}/platba/iniciovat` → `PaymentInitiateController`
- `/objednavka/{id}/platba/navrat` → `PaymentReturnController`
- `/objednavka/{id}/stav` → `OrderStatusController` (UriSigner-protected lifecycle-aware permalink)
- `/objednavka/{id}/dokumenty/smlouva.pdf` → `OrderContractDownloadController` (UriSigner-protected)
- `/objednavka/{id}/dokumenty/faktura/{invoiceId}.pdf` → `OrderInvoiceDownloadController` (UriSigner-protected)
- `/objednavka/{id}/dokumenty/mapa.png` → `OrderMapDownloadController` (UriSigner-protected)
- `/objednavka/{id}/dokumenty/vop.pdf` → `OrderVopDownloadController` (UriSigner-protected)
- `/predavaci-protokol/{id}` → `Public\HandoverViewController` (UriSigner-protected — signed link mailed to tenant; lets them upload signature/photos without logging in)
- `/podpis/{token}` → `CustomerSigningController`
- `/podpis/dokonceno/{id}` → `CustomerSigningCompleteController`
- `/opakovana-platba/{contractId}/zrusit` → `CancelRecurringPaymentController`
- `/pouceni-spotrebitele` → `ConsumerNoticeController`
- `/ochrana-osobnich-udaju` → `PrivacyPolicyController`
- `/obchodni-podminky` → `TermsAndConditionsController`
- `/podminky-opakovanych-plateb` → `RecurringPaymentsTermsController`
- `/webhook/gopay` → `PaymentNotificationController`

### Portal (shared, authenticated)
- `/portal/profile` → `ProfileController`
- `/portal/profile/edit` → `ProfileEditController`
- `/portal/profile/billing` → `BillingInfoController`
- `/portal/profile/change-password` → `ChangePasswordController`

### Portal — User/Tenant (Portal\User\*)
- `/portal/objednavky` → `OrderListController`
- `/portal/objednavky/{id}` → `OrderDetailController`
- `/portal/objednavky/{id}/zrusit` → `OrderCancelController`
- `/portal/objednavky/{id}/vop.pdf` → `VopPdfController` — per-order VOP snapshot with placeholders resolved
- `/portal/smlouvy/{id}/stahnout` → `ContractDownloadController`
- `/portal/smlouvy/{id}/pdf` → `ContractPdfController`
- `/portal/smlouvy/{id}/ukoncit` → `ContractTerminateController`
- `/portal/faktury/{id}/pdf` → `InvoicePdfController`
- `/portal/pobocky` → `PlaceBrowseListController`
- `/portal/pobocka/{id}` → `PlaceBrowseDetailController`
- `/portal/predavaci-protokol/{id}` → `HandoverViewController`

### Portal — Landlord (ROLE_LANDLORD)
- `/portal/dashboard` → `DashboardController`
- `/portal/calendar` → `CalendarController`
- `/portal/places` → `PlaceListController`
- `/portal/places/create` → `PlaceCreateController`
- `/portal/places/{id}` → `PlaceDetailController`
- `/portal/places/{id}/edit` → `PlaceEditController`
- `/portal/places/{id}/delete` → `PlaceDeleteController`
- `/portal/places/propose` → `PlaceProposeController`
- `/portal/places/{placeId}/request-access` → `PlaceAccessRequestController`
- `/portal/places/{placeId}/canvas` → `StorageCanvasController`
- `/portal/places/{placeId}/access-codes` → `PlaceAccessCodesController`
- `/portal/places/{placeId}/access-codes/bulk-generate` → `PlaceAccessCodesBulkGenerateController`
- `/portal/places/{placeId}/access-codes/reset` → `PlaceAccessCodesResetController`
- `/portal/places/{placeId}/storages` → `StorageListController`
- `/portal/places/{placeId}/storages/export` → `StorageExportController`
- `/portal/places/{placeId}/storage-types` → `StorageTypeListController`
- `/portal/places/{placeId}/storage-types/create` → `StorageTypeCreateController`
- `/portal/places/{placeId}/storage-types/{id}/edit` → `StorageTypeEditController`
- `/portal/places/{placeId}/storage-types/{id}/delete` → `StorageTypeDeleteController`
- `/portal/places/{placeId}/storage-types/{id}/obsazenost` → `StorageTypeOccupancyController`
- `/portal/places/{placeId}/storage-types/{storageTypeId}/photos/{photoId}/delete` → `StorageTypePhotoDeleteController`
- `/portal/storages/{id}/edit` → `StorageEditController`
- `/portal/storages/{id}/delete` → `StorageDeleteController`
- `/portal/storages/{storageId}/photos/{photoId}/delete` → `StoragePhotoDeleteController`
- `/portal/unavailabilities` → `StorageUnavailabilityListController`
- `/portal/unavailabilities/create` → `StorageUnavailabilityCreateController`
- `/portal/unavailabilities/{id}/delete` → `StorageUnavailabilityDeleteController`
- `/portal/landlord/orders` → `LandlordOrderListController`
- `/portal/landlord/orders/{id}` → `LandlordOrderDetailController`
- `/portal/landlord/orders/{id}/cancel` → `LandlordOrderCancelController`
- `/portal/landlord/orders/export` → `LandlordOrderExportController`
- `/portal/landlord/self-billing` → `SelfBillingInvoiceListController`
- `/portal/landlord/self-billing/{id}` → `SelfBillingInvoiceDetailController`
- `/portal/landlord/self-billing/{id}/pdf` → `SelfBillingInvoicePdfController`
- `/portal/landlord/self-billing/export` → `LandlordSelfBillingExportController`
- `/portal/pronajimatel/predavaci-protokol/{id}` → `LandlordHandoverViewController`
- `/portal/pronajimatel/predavaci-protokol/{id}/generate-code` → `LandlordHandoverGenerateCodeController`
- `/portal/users` → `UserListController`
- `/portal/users/export` → `UserExportController`
- `/portal/users/{id}` → `UserViewController`
- `/portal/users/{id}/edit` → `UserEditController`
- `/portal/users/{id}/activate` → `UserActivateController`
- `/portal/users/{id}/deactivate` → `UserDeactivateController`
- `/portal/users/{id}/verify` → `UserVerifyController`
- `/portal/users/{id}/change-password` → `UserChangePasswordController` — admin sets another user's password (≠ self-service)

### Portal — Admin (ROLE_ADMIN, Admin\* and Portal\Admin\*)
- `/portal/admin/orders` → `AdminOrderListController`
- `/portal/admin/orders/{id}` → `AdminOrderDetailController`
- `/portal/admin/orders/{id}/cancel` → `AdminOrderCancelController`
- `/portal/admin/orders/export` → `AdminOrderExportController`
- `/portal/admin/places` → `AdminPlaceListController`
- `/portal/admin/places/export` → `AdminPlaceExportController`
- `/portal/admin/audit-log` → `AdminAuditLogController`
- `/portal/admin/audit-log/export` → `AdminAuditLogExportController`
- `/portal/admin/email-log` → `AdminEmailLogController`
- `/portal/admin/email-log/{id}` → `AdminEmailLogDetailController`
- `/portal/admin/email-log/export` → `AdminEmailLogExportController`
- `/portal/admin/po-splatnosti` → `AdminOverdueController` — overdue contracts dashboard
- `/portal/admin/po-splatnosti/export` → `AdminOverdueExportController`
- `/portal/admin/onboarding` → `AdminOnboardingController`
- `/portal/admin/onboarding/digital` → `AdminCreateOnboardingController`
- `/portal/admin/onboarding/migrate` → `AdminMigrateCustomerController`
- `/portal/admin/contracts/{id}/advance-notice` → `AdminContractAdvanceNoticeController`
- `/portal/admin/place-access-requests` → `PlaceAccessRequestListController`
- `/portal/admin/place-access-requests/{id}/approve` → `PlaceAccessRequestApproveController`
- `/portal/admin/place-access-requests/{id}/deny` → `PlaceAccessRequestDenyController`

### API
- `POST /api/places/{placeId}/storages` → `Api\StorageApiCreateController`
- `PUT /api/places/{placeId}/storages/{storageId}` → `Api\StorageApiUpdateController`
- `DELETE /api/places/{placeId}/storages/{storageId}` → `Api\StorageApiDeleteController`
- `POST /api/places/{placeId}/storages/generate-code` → `Api\StorageApiGenerateCodeController`
- `GET /api/ares/{companyId}` → `Api\AresLookupController`
- `GET /api/address/suggest` → `Api\AddressSuggestController` — rate-limited Photon (Komoot) autocomplete; backs the address field on the order/billing forms
- (Shared validation in `Api\StorageApiValidationTrait`)

### Ops
- `/-/health-check/liveness` → `HealthCheckController`

## Entities

| Entity | Purpose | Key relations / notes |
|---|---|---|
| `User` (records events) | Platform user (tenant/landlord/admin); records events via `HasEvents` | billing info; orders, places, contracts, audit logs |
| `Place` | Physical storage location | owner:User; storages, storage types; carries storage-code config; latitude/longitude used by `PlaceAddressFormatter` for navigation links |
| `Storage` | Unit inside a place | user, storageType, place; photos, unavailability; nullable `storageCode` |
| `StorageType` | Template/category for storage | place; storages, photos |
| `StoragePhoto` / `StorageTypePhoto` | Images | parent |
| `StorageUnavailability` | Blackout period | storage, user |
| `Order` (records events) | Rental request/booking; carries `individualMonthlyAmount`, `paidThroughDate`, `billingMode` from admin onboarding | user, storage; contract; invoices |
| `Contract` (records events) | Legal rental agreement; `individualMonthlyAmount` override survives storage-price changes; every change records a `ContractPriceChanged` event; `billingMode` (`BillingMode` enum) is locked at order completion and drives recurring vs manual billing; `daysUntilExternalPrepaymentEnds()` reports remaining external-prepayment days for customer banners | order(1:1), user, storage; tracks signing, termination, recurring/manual payment |
| `ContractPriceChange` | Append-only audit row per `applyIndividualMonthlyAmount` call (previous → new amount, actor, reason); persisted by `PersistContractPriceChangeHandler` on `ContractPriceChanged` | contract, changedBy:User? |
| `ManualPaymentRequest` | One row per (Contract, billing cycle) for `MANUAL_RECURRING` contracts. Tracks per-stage reminder dispatch (`initial`, `d_minus_2`, `d_zero`, `d_plus_3`, `d_plus_7`) and the GoPay one-off payment for that cycle. Unique on `(contract_id, period_start)` | contract |
| `Invoice` (records events) | Customer bill | order, user |
| `SelfBillingInvoice` | Landlord revenue invoice | landlord; payments |
| `Payment` | Payment tx | selfBillingInvoice, order, contract, storage; GoPay status |
| `HandoverProtocol` (records events) | Check-in/out doc | contract(1:1); photos |
| `HandoverPhoto` | Image in handover | handoverProtocol |
| `PlaceAccessRequest` (records events) | Request manager access | requester, place, approver |
| `PlaceAccess` | Granted access | place, user |
| `PlaceChangeRequest` | Update place request | user, place, processor |
| `CreatePlaceRequest` | Application to create place | requester, place |
| `PlaceStorageCodeUsage` | Tracks which storage codes have been issued at a place | place; supports bulk + on-demand allocation |
| `EmailLog` | Persistent record of every outbound email (status, recipient, template, payload) | user (nullable); written out-of-band so survives rollback |
| `OverdueDigestSent` | Audit row marking a daily overdue digest e-mail was sent to one admin (unique per admin per day) | admin:User |
| `OnboardingReminderSent` | Idempotency row for `app:send-onboarding-payment-reminders` (D+2 / D+5 stages). Unique on `(order_id, stage)` | order |
| `AuditLog` | Activity log | user |
| `ResetPasswordRequest` | Password reset token | user |
| `LandlordInvoiceSequence` | Invoice numbering | landlord |

`EntityWithEvents` interface + `HasEvents` trait power domain-event recording (`User`, `Order`, `Contract`, `Invoice`, `HandoverProtocol`, `PlaceAccessRequest`). `#[HasDeleteDomainEvent(...)]` on entity wires delete-time events. `DomainEventsSubscriber` + `DispatchDomainEventsMiddleware` flush recorded events through the event bus on transaction commit.

## Commands (write ops)

User / auth: RegisterUser, RegisterLandlord, VerifyEmail, ResendVerificationEmail, RequestPasswordReset, ResetPassword, ChangePassword, SetUserPassword, UpdateProfile, UpdateBillingInfo, ActivateUser, DeactivateUser, VerifyUserByAdmin, ChangeUserRole, AdminUpdateUser, GetOrCreateUserByEmail.

Place / storage: CreatePlace, UpdatePlace, DeletePlace, UpdatePlaceStorageCodeConfig, CreateStorage, UpdateStorage, DeleteStorage, AddStoragePhoto, DeleteStoragePhoto, CreateStorageType, UpdateStorageType, DeleteStorageType, AddStorageTypePhoto, DeleteStorageTypePhoto, CreateStorageUnavailability, DeleteStorageUnavailability, BulkGenerateStorageCodes, ReleaseUnusedStorageCodes.

Order / payment / contract: CreateOrder, CancelOrder, CompleteOrder, AcceptOrderTerms, SignOrder, ConfirmOrderPayment, InitiatePayment, ProcessPaymentNotification, ChargeRecurringPayment, CancelRecurringPayment, RequestTerminationNotice, DispatchManualBillingNotification, DispatchOnboardingReminder.

Onboarding (admin-driven): AdminCreateOnboarding, AdminMigrateCustomer, CustomerSignOnboarding.

Place access / handover: RequestPlaceAccess, ApprovePlaceAccessRequest, DenyPlaceAccessRequest, CreateHandoverProtocol, CompleteLandlordHandover, CompleteTenantHandover, AddHandoverPhoto, RemoveHandoverPhoto.

Self-billing: GenerateSelfBillingInvoice.

## Queries

`QueryBus` (typed via `QueryMessage<TResult>`) + `Get*Query` handlers. Each has matching `Get*Result` DTO.

GetDashboardStats, GetLandlordDashboardStats, GetPlaceDashboardStats, GetAdminRevenueChart, GetLandlordRevenueChart, GetPlaceRevenueChart, GetPlaceTypeOccupancyOverview (rows: `GetPlaceTypeOccupancyRow`), GetStorageTypeOccupancy.

## Domain Events

User / auth: UserRegistered, EmailVerified, PasswordResetRequested, PasswordChangedByAdmin, LandlordRegistered, AdminOnboardingInitiated.

Order: OrderCreated, OrderPlaced, OrderPaid, OrderCompleted, OrderCancelled, OrderExpired.

Place / handover: PlaceProposed, PlaceAccessRequested, PlaceAccessRequestApproved, PlaceAccessRequestDenied, HandoverProtocolCreated, HandoverCompleted, HandoverExpired, HandoverReminderDue.

Contracts / billing: ContractExpiringSoon, ContractPriceChanged, ContractTerminated, ContractTerminatedDueToPaymentFailure, InvoiceCreated, RecurringPaymentEstablished, RecurringPaymentCharged, RecurringPaymentFailed, RecurringPaymentCancelled, RecurringPaymentAdvanceNoticeNeeded, TerminationNoticeRequested, ExternalPrepaymentEndingSoon, PaymentAmountMismatch, ManualBillingPaymentRequested, ManualBillingPaymentOverdue, OnboardingPaymentReminderRequested.

Admin notifications: OverdueDigestRequested.

Handlers (in `src/Event/`):
- Email side-effects: `Send*EmailHandler` (welcome, verification, password reset, order confirmation, signing link, contract ready/expiring/terminated, handover request/reminder/completed, invoice, recurring-payment established/cancelled/failed/advance-notice + admin variants, manual-billing payment-requested / payment-overdue + admin variant, place access approved/denied/requested, place proposed, payment default, amount mismatch alert, external-prepayment ending soon, termination notice, overdue digest).
- Bookkeeping: `PersistContractPriceChangeHandler` (persists `ContractPriceChange` audit row on `ContractPriceChanged`), `IssueInvoiceOnRecurringChargeHandler`, `RecordPaymentOnOrderPaidHandler`, `RecordPaymentOnRecurringChargeHandler`, `ReleaseStorageOnHandoverCompletedHandler`, `ForceReleaseStorageOnHandoverExpiredHandler`, `SendStorageAvailabilityWarningHandler`. First-payment invoice is issued synchronously by `SendRentalActivatedEmailHandler` so the PDF can be bundled into the post-payment e-mail; the standalone `SendInvoiceEmailHandler` is the fallback (also used by every recurring charge).
- Framework subscribers (not domain events): `DomainEventsSubscriber` (flushes recorded entity events via the messenger middleware), `SentryScopeResetSubscriber` (resets Sentry scope on kernel `REQUEST`).

## Enums

`UserRole`, `OrderStatus`, `StorageStatus`, `HandoverStatus`, `PlaceAccessRequestStatus`, `PlaceType`, `RentalType` (UNLIMITED/FIXED_TERM), `RequestStatus`, `TerminationReason`, `PaymentMethod`, `PaymentFrequency` (MONTHLY/YEARLY — locked at order completion, copied to Contract; YEARLY always implies MANUAL_RECURRING), `SigningMethod` (DIGITAL/PHYSICAL), `AdvanceNoticeReason`, `EmailLogStatus`, `BillingMode` (ONE_TIME/AUTO_RECURRING/MANUAL_RECURRING — locked at order completion, copied to Contract), `ExpectedDuration` (SHORT/MEDIUM/LONG — research-only; populated only for UNLIMITED orders).

## Forms (FormData + FormType pairs)

Registration, LandlordRegistration, RequestPasswordReset, ResetPassword, ChangePassword, Profile, BillingInfo, Place, PlaceProposal, PlaceStorageCodeConfig, Storage, StorageType, StorageUnavailability, Order, UserRole (admin), AdminUser, AdminUserPassword, AdminCreateOnboarding, AdminMigrateCustomer, LandlordHandover, TenantHandover.

Address helper interface: `Form\Address\HasBillingAddress` — implemented by every FormData that owns a billing address (`billingStreet`/`billingCity`/`billingPostalCode` + `addressOverride` + `hasCompleteAddress()`); lets the address-validation/autocomplete UI bind to any of them generically.

## Repositories (`src/Repository/`)

Compose `EntityManagerInterface` only — never extend `ServiceEntityRepository`, never call `flush()` (exception: `EmailLogRepository`, audit-log writers commit out-of-band so the row survives a parent rollback).

User, Place, PlaceAccess, PlaceAccessRequest, PlaceChangeRequest, PlaceStorageCodeUsage, CreatePlaceRequest, Storage, StorageType, StoragePhoto, StorageTypePhoto, StorageUnavailability, Order (incl. `findUnpaidSignedOnboarding` for the spec 043 reminder cron), Contract, ContractPriceChange, ManualPaymentRequest, Invoice, SelfBillingInvoice, LandlordInvoiceSequence, Payment, HandoverProtocol, HandoverPhoto, AuditLog, EmailLog (+ `EmailLogFilter` value-object), OverdueDigestSent, OnboardingReminderSent, ResetPasswordRequest.

## Services (`src/Service/`)

- **Payment / GoPay**: `GoPay\GoPayClient`, `GoPay\GoPayApiClient`, `GoPay\GoPayException`, `GoPay\PaymentNotConfirmedException`, `RecurringPaymentCancelUrlGenerator`, `OrderStatusUrlGenerator`.
- **Order display**: `Order\OrderDisplayStatus`, `Order\OrderDisplayStatusCase`, `Order\OrderDisplayStatusResolver`, `Order\OrderStatusViewModel`, `Order\OrderStatusViewModelFactory`. `OrderService` orchestrates higher-level order operations. `OrderEmailAttachments` + `OrderEmailAttachmentsService` bundle contract/invoice/map/VOP PDFs into outbound mail (falls back to `Contract.documentPath` paper PDF when `Order.hasSignature()` is false — migrate flow).
- **Customer billing situation (spec 043)**: `Order\CustomerBillingSituation` (the enum spine: GOPAY_FIRST_CHARGE / EXTERNALLY_PREPAID / FREE; `fromOrder(Order)` + `fromContract(Contract)` factories — free wins over prepaid). Feeds four content VOs read by signing/onboarding surfaces: `Order\SigningPriceViewModel` (signing page), `Order\SigningEmailContent` (signing-link email), `Order\CompletionPageViewModel` (signing complete page), `Order\RentalActivatedEmailContent` (rental activated email).
- **Onboarding**: `Onboarding\OnboardingReminderSchedule` (D+2 / D+5 calendar-day stage matcher for `app:send-onboarding-payment-reminders`).
- **Invoicing / billing**: `Fakturoid\FakturoidClient`, `Fakturoid\FakturoidApiClient`, `Fakturoid\StaleFakturoidSubjectException`, `InvoicingService`, `SelfBillingService`, `Billing\RecurringAmountCalculator` (frequency-aware; reads `Contract::getEffectiveRecurringAmount()` + cadence-aware proration), `Billing\ManualBillingReminderSchedule` (per-stage cron offsets for `MANUAL_RECURRING` contracts).
- **VOP generation**: `Vop\VopDocumentGenerator` (fills `${PRICELIST_URL}` / `${OPERATING_RULES_URL}` into `vop_template.docx` per order), `Vop\VopPdfStamper` (overlays customer signature; `$skipLastPages` excludes annex pages).
- **Overdue & contract risk**: `Overdue\OverdueChecker`, `AtRiskContractChecker`.
- **Address validation / autocomplete**: `Address\AddressValidator` (interface) + `Address\PhotonAddressValidator` (Photon Komoot impl with HTTP cache + 3 s timeout; powers `/api/address/suggest` and `BillingInfoForm` validation). Result VOs in `Value\Address\` (`AddressSuggestion`, `AddressValidationResult`).
- **Handover signed links**: `Handover\HandoverUrlGenerator` (HMAC-signs `public_handover_view` URLs — mirrors `OrderStatusUrlGenerator`).
- **Place address rendering**: `Place\PlaceAddressFormatter` (single-line address, Google Maps navigation URL, fallback when no GPS); surfaced via `Twig\PlaceAddressExtension`.
- **Security voters / subscribers / listeners**: `Security\ContractVoter`, `Security\OrderVoter`, `Security\PlaceVoter`, `Security\StorageVoter`, `Security\StorageTypeVoter`, `Security\HandoverProtocolVoter`, `Security\DeactivatedUserChecker`, `Security\LoginSubscriber`, `Security\RecordLoginSubscriber`, `Security\HandoverAccessDeniedListener` (kernel exception listener: turns `AccessDeniedException` on the three portal handover routes into a logout + redirect to `/login?_target_path=…`, so a wrong-account user re-authenticates instead of being bounced to home).
- **Identity**: `Identity\ProvideIdentity` (UUID v7), `Identity\RandomIdentityProvider` (prod), `tests/.../PredictableIdentityProvider`.
- **ARES lookup**: `AresService`, `AresLookup` (+ `Value\AresResult`, `Value\AresSubject`, `Value\AresAddress`).
- **Uploads / files**: `PlaceFileUploader`, `StoragePhotoUploader`, `StorageTypePhotoUploader`, `HandoverPhotoUploader`, `PublicFilesystem`, `SignatureStorage`.
- **Domain utilities**: `PriceCalculator`, `CommissionCalculator`, `ContractService`, `ContractDocumentGenerator`, `DocumentPdfConverter`, `StorageMapImageGenerator`, `StorageAssignment`, `StorageAvailabilityChecker`, `StorageCodeGenerator`, `Storage\StorageOccupancyService`, `AuditLogger`, `AuditLogDescriptionRenderer`, `EmailLogger`.
- **Excel export**: `Excel\ExcelExporter`, `Excel\ExcelSheet`, `Excel\ExcelColumn`, `Excel\ExcelColumnType`. Drives every `*Export*Controller`.
- **Form helpers**: `Form\StorageChoiceBuilder`, `Form\EmailLogFilterFactory`.
- **Messenger**: `Messenger\HandlerFailureUnwrap` (mandatory at every dispatch site that catches handler exceptions — see `.claude/MESSENGER.md`).

## Yearly pricing tier (spec 045)

- `StorageType.defaultPricePerYear` (nullable, halere) + `Storage.pricePerYear` override.
- `Storage::getEffectivePricePerYear()` — falls back to monthly × 12 when both unset; `hasExplicitYearlyRate()` flags whether an explicit rate exists (admin UI uses this to decide whether to surface the YEARLY radio).
- `Contract.paymentFrequency` (`MONTHLY` | `YEARLY`) mirrors `Order.paymentFrequency`; locked at completion.
- `Contract::getBillingCadenceStep()` → `+1 month` or `+1 year`; consumed by both billing crons (`ChargeRecurringPaymentHandler`, `DispatchManualBillingNotificationHandler`) and webhook reconciliation (`ProcessPaymentNotificationHandler`). `Contract::getBillingPeriodDays()` returns 30 vs 365 for proration.
- `Contract::getEffectiveRecurringAmount()` returns the cadence-appropriate amount (monthly individual / storage monthly / storage yearly).
- YEARLY is always `BillingMode::MANUAL_RECURRING` (form-layer constraint + entity-level defence in `OrderFormData::validatePaymentFrequency` + admin equivalents).
- `PriceCalculator::YEARLY_THRESHOLD_DAYS = 360` (LIMITED eligibility); `resolveRateType()` + `isEligibleForYearly()` are the public eligibility helpers.
- `PaymentSchedule.yearlyAmount` + `isYearly()` + `getYearlyMonthlyEquivalentInCzk()` power customer-facing "X Kč / měsíc (účtováno jednou ročně, Y Kč)" display.
- GoPay 15 000 Kč ON_DEMAND cap does not apply — yearly is a one-shot charge per period, no token saved. Recurring-payment consent checkbox on `/prijmout` is suppressed for YEARLY (already gated on `billingMode = AUTO_RECURRING`).
- `daysUntilExternalPrepaymentEnds()` returns `null` for yearly contracts so they don't trip the "Předplaceno externě" customer banner; `customer_billing_status.html.twig` has a dedicated teal "Platíte ročně" variant.
- MRR: yearly contracts are excluded from `mrrInHaler` (would inflate 12×). Sibling `yrrInHaler` / `expectedYrrInHaler` tally yearly amounts; surfaced on the user list and admin places export, plus a per-place "Očekávané YRR" tile when non-zero.
- `CompleteOrderHandler` seeds the initial `nextBillingDate` for MANUAL_RECURRING contracts (cadence-aware) — previously the cron was blind to any MANUAL contract without external prepayment, so this fix is both yearly-required and an incidental monthly-MANUAL bugfix.

## Middleware

- `Middleware\DispatchDomainEventsMiddleware` — flushes recorded entity events to the event bus on successful command-bus transaction.

## Twig (`src/Twig/`)

- Components: `BillingInfoForm`, `OrderForm`, `RevenueChart`.
- Extensions: `OverdueExtension` (severity badges / labels), `RoleLabelExtension`, `UploadExtension`, `PlaceAddressExtension` (`place_address`, `place_navigation_url`, `place_has_navigation`).

## Value objects (`src/Value/`)

`AresResult`, `AresSubject`, `AresAddress`, `Address\AddressSuggestion`, `Address\AddressValidationResult`, `FakturoidInvoice`, `FakturoidSubject`, `GoPayPayment`, `GoPayPaymentStatus`, `OverdueSummary`, `OverdueContractView`, `OverdueSeverity`, `PaymentSchedule`, `PaymentScheduleEntry`, `RentalSpan`, `RentalSpanKind`, `StorageRentalView`.

## Exceptions (`src/Exception/`)

Domain exceptions, most carrying `#[WithHttpStatus]`:

`UserAlreadyExists`, `UserNotFound`, `UnverifiedUser`, `InvalidCurrentPassword`, `PlaceNotFound`, `PlaceAccessNotFound`, `PlaceAccessAlreadyExists`, `PlaceAccessRequestNotFound`, `PlaceChangeRequestNotFound`, `CreatePlaceRequestNotFound`, `CreatePlaceRequestAlreadyProcessed`, `StorageNotFound`, `StorageTypeNotFound`, `StoragePhotoNotFound`, `StorageTypePhotoNotFound`, `StorageUnavailabilityNotFound`, `StorageCannotBeDeleted`, `StorageCannotBeReassigned`, `StorageHasActiveRental`, `NoStorageAvailable`, `InvalidStorageCode`, `StorageCodeRangeExhausted`, `OrderNotFound`, `ContractNotFound`, `InvoiceNotFound`, `SelfBillingInvoiceNotFound`, `HandoverProtocolNotFound`, `EmailLogNotFound`, `ManualPaymentRequestNotFound`, `NoPaymentsForPeriod`, `AresUnavailable`.

## Console commands (`src/Console/`, run via `bin/console`)

| Command | Purpose |
|---|---|
| `app:expire-orders` | Mark unpaid orders past their per-place expiration window as expired |
| `app:issue-missing-invoices` | Backstop: issue Fakturoid invoice for paid orders that ended up without one (15-min grace, avoids racing the synchronous path in `SendRentalActivatedEmailHandler`) |
| `app:process-recurring-payments` | Charge `AUTO_RECURRING` contracts due today via GoPay (records `RecurringPaymentCharged` / `RecurringPaymentFailed`) |
| `app:retry-failed-payments` | Retry recurring charges that previously failed (after 3 days) or cancel if retry also fails |
| `app:send-recurring-payment-advance-notice` | Email customer N days before next charge (≥6-month gap rule, Podmínky čl. V) |
| `app:send-manual-billing-payment-requests` | Per-cycle payment-request & overdue reminders for `MANUAL_RECURRING` contracts; idempotent via `ManualPaymentRequest.sentStages` |
| `app:send-external-prepayment-ending-soon` | Email customer when external-prepayment runway nearly used up |
| `app:send-overdue-digest-email` | Daily 08:00 Europe/Prague — one e-mail per admin when ≥1 overdue contract; idempotent via `OverdueDigestSent` |
| `app:send-onboarding-payment-reminders` | Daily — D+2 / D+5 payment-reminder e-mails to admin-onboarded GoPay customers who signed but never paid; idempotent via `OnboardingReminderSent` |
| `app:send-expiration-reminders` | Email customer ahead of fixed-term contract end |
| `app:process-contract-terminations` | Apply scheduled terminations + emit `ContractTerminated` |
| `app:process-handover-protocols` | Send handover requests/reminders, expire stale protocols |
| `app:generate-self-billing-invoices` | Issue monthly landlord self-billing invoices |
| `app:generate-fixture-photos` | Dev-only: regenerate fixture image set |

## Templates

`templates/{base.html.twig, user/, portal/, admin/, public/, email/, form/, components/, partials/, documents/, bundles/}`.

## Fixtures (`fixtures/`)

UserFixtures, PlaceFixtures, StorageFixtures, StoragePhotoFixtures, StorageTypeFixtures, StorageTypePhotoFixtures, StorageUnavailabilityFixtures, OrderFixtures, ContractFixtures, InvoiceFixtures, OnboardingFixtures, PlaceAccessFixtures, PlaceAccessRequestFixtures, PlaceStorageCodeUsageFixtures, EmailLogFixtures.

Test users: `admin@`, `landlord@`, `landlord2@`, `user@`, `tenant@`, `unverified@example.com`, password `password`.

Reference constants and golden IDs: `.claude/FIXTURES.md`.

## Compliance & docs

- Order / payment / consumer-facing legal text governed by `.claude/COMPLIANCE.md` (CZ "tlačítková novela", GoPay rules, VOP / Poučení / Podmínky opakovaných plateb). Submit button MUST read exactly `OBJEDNÁVÁM a zaplatím`; recurring-payment consent is its own checkbox; identification block on every order page; prices `vč. DPH`; card+3DS+GoPay logos at every payment surface.
- Customer-facing document inventory: `.claude/CUSTOMER_DOCUMENTS.md`. **VOP is dynamic** — legal master is `templates/documents/vop_template.docx`; `Vop\VopDocumentGenerator` resolves `${PRICELIST_URL}` / `${OPERATING_RULES_URL}`; `Vop\VopPdfStamper` overlays customer signature (`$skipLastPages` excludes annex pages — update in `config/services.php` if annex count changes); on-page consent modal (`templates/public/_terms_and_conditions_content.html.twig`) must stay in sync with DOCX wording.
- Domain narrative: `.claude/DOMAIN.md` / `.pdf`.
- Messenger gotchas (handler exception unwrapping, GoPay webhook architecture, failure-recording-outside-transaction): `.claude/MESSENGER.md`.

## Conventions reminder
- `declare(strict_types=1);` everywhere; `final readonly` for commands/events/DTOs.
- Repositories use `EntityManager` composition, never extend `ServiceEntityRepository`, never call `flush()` (audit-log writers are the documented exception).
- Single-action controllers (`__invoke()`); route at class level.
- Entities use property hooks (PHP 8.4+ syntax); `private(set)` for ctor params, `public private(set)` for updatable; UUIDs come from `ProvideIdentity`.
- Tests: MockClock fixed at `2025-06-15 12:00:00 UTC`. Prefer fixtures over fabricated data.
- Czech UI text requires full diacritics.
- Turbo is disabled globally; opt-in with `data-turbo="true"`.
- Logging exceptions: `['exception' => $e]`, never `$e->getMessage()`.
- Migrations: always generate via `make:migration` / `doctrine:migrations:diff` — never handwrite.
