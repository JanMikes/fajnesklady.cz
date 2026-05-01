# Project Map — Fajnesklady.cz

Reference for spec writing. Regenerate if structure drifts significantly. Generated 2026-04-22.

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
- `/objednavka/{placeId}/{storageTypeId}/{storageId?}` → `OrderCreateController`
- `/objednavka/{placeId}/{storageTypeId}/{storageId}/prijmout` → `OrderAcceptController`
- `/objednavka/{id}/platba` → `OrderPaymentController`
- `/objednavka/{id}/platba/iniciovat` → `PaymentInitiateController`
- `/objednavka/{id}/platba/navrat` → `PaymentReturnController`
- `/objednavka/{id}/dokonceno` → `OrderCompleteController`
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
- `/portal/smlouvy/{id}` → `ContractDownloadController`
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
- `/portal/places/{placeId}/storages` → `StorageListController`
- `/portal/places/{placeId}/storage-types` → `StorageTypeListController`
- `/portal/places/{placeId}/storage-types/create` → `StorageTypeCreateController`
- `/portal/places/{placeId}/storage-types/{id}/edit` → `StorageTypeEditController`
- `/portal/places/{placeId}/storage-types/{id}/delete` → `StorageTypeDeleteController`
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
- `/portal/landlord/self-billing` → `SelfBillingInvoiceListController`
- `/portal/landlord/self-billing/{id}` → `SelfBillingInvoiceDetailController`
- `/portal/landlord/self-billing/{id}/pdf` → `SelfBillingInvoicePdfController`
- `/portal/pronajimatel/predavaci-protokol/{id}` → `LandlordHandoverViewController`
- `/portal/users` → `UserListController`
- `/portal/users/{id}` → `UserViewController`
- `/portal/users/{id}/edit` → `UserEditController`
- `/portal/users/{id}/activate` → `UserActivateController`
- `/portal/users/{id}/deactivate` → `UserDeactivateController`
- `/portal/users/{id}/verify` → `UserVerifyController`

### Portal — Admin (ROLE_ADMIN, Admin\* and Portal\Admin\*)
- `/portal/admin/orders` → `AdminOrderListController`
- `/portal/admin/orders/{id}` → `AdminOrderDetailController`
- `/portal/admin/orders/{id}/cancel` → `AdminOrderCancelController`
- `/portal/admin/places` → `AdminPlaceListController`
- `/portal/admin/audit-log` → `AdminAuditLogController`
- `/portal/admin/payment-issues` → `AdminPaymentIssuesController`
- `/portal/admin/onboarding` → `AdminOnboardingController`
- `/portal/admin/onboarding/digital` → `AdminCreateOnboardingController`
- `/portal/admin/onboarding/migrate` → `AdminMigrateCustomerController`
- `/portal/admin/place-access-requests` → `PlaceAccessRequestListController`
- `/portal/admin/place-access-requests/{id}/approve` → `PlaceAccessRequestApproveController`
- `/portal/admin/place-access-requests/{id}/deny` → `PlaceAccessRequestDenyController`

### API
- `POST /api/places/{placeId}/storages` → `Api\StorageApiCreateController`
- `PUT /api/places/{placeId}/storages/{storageId}` → `Api\StorageApiUpdateController`
- `DELETE /api/places/{placeId}/storages/{storageId}` → `Api\StorageApiDeleteController`

### Ops
- `/-/health-check/liveness` → `HealthCheckController`

## Entities

| Entity | Purpose | Key relations |
|---|---|---|
| `User` | Platform user (tenant/landlord/admin) | billing info; orders, places, contracts, audit logs |
| `Place` | Physical storage location | owner:User; storages, storage types |
| `Storage` | Unit inside a place | user, storageType, place; photos, unavailability |
| `StorageType` | Template/category for storage | place; storages, photos |
| `StoragePhoto` / `StorageTypePhoto` | Images | parent |
| `StorageUnavailability` | Blackout period | storage, user |
| `Order` | Rental request/booking | user, storage; contract; invoices |
| `Contract` | Legal rental agreement | order(1:1), user, storage; tracks signing, termination, recurring payment |
| `Invoice` | Customer bill | order, user |
| `SelfBillingInvoice` | Landlord revenue invoice | landlord; payments |
| `Payment` | Payment tx | selfBillingInvoice, order, contract, storage; GoPay status |
| `HandoverProtocol` | Check-in/out doc | contract(1:1); photos |
| `HandoverPhoto` | Image in handover | handoverProtocol |
| `PlaceAccessRequest` | Request manager access | requester, place, approver |
| `PlaceAccess` | Granted access | place, user |
| `PlaceChangeRequest` | Update place request | user, place, processor |
| `CreatePlaceRequest` | Application to create place | requester, place |
| `AuditLog` | Activity log | user |
| `ResetPasswordRequest` | Password reset token | user |
| `LandlordInvoiceSequence` | Invoice numbering | landlord |

## Commands (write ops)
RegisterUser, RegisterLandlord, VerifyEmail, ResendVerificationEmail, RequestPasswordReset, ResetPassword, ChangePassword, UpdateProfile, UpdateBillingInfo, ActivateUser, DeactivateUser, SetUserPassword, VerifyUserByAdmin, ChangeUserRole, AdminUpdateUser, CreatePlace, UpdatePlace, DeletePlace, CreateStorage, UpdateStorage, DeleteStorage, AddStoragePhoto, DeleteStoragePhoto, CreateStorageType, UpdateStorageType, DeleteStorageType, AddStorageTypePhoto, DeleteStorageTypePhoto, CreateStorageUnavailability, DeleteStorageUnavailability, CreateOrder, CancelOrder, CompleteOrder, AcceptOrderTerms, SignOrder, ConfirmOrderPayment, InitiatePayment, ProcessPaymentNotification, ChargeRecurringPayment, CancelRecurringPayment, RequestPlaceAccess, ApprovePlaceAccessRequest, DenyPlaceAccessRequest, CreateHandoverProtocol, CompleteLandlordHandover, CompleteTenantHandover, AddHandoverPhoto, RemoveHandoverPhoto, RequestTerminationNotice, GenerateSelfBillingInvoice, GetOrCreateUserByEmail, AdminCreateOnboarding, AdminMigrateCustomer, CustomerSignOnboarding.

## Queries
GetDashboardStats, GetLandlordDashboardStats, GetAdminRevenueChart, GetLandlordRevenueChart.

## Domain Events
UserRegistered, EmailVerified, PasswordResetRequested, LandlordRegistered, AdminOnboardingInitiated, OrderCreated, OrderPaid, OrderCompleted, OrderCancelled, OrderExpired, PlaceProposed, PlaceAccessRequested, PlaceAccessRequestApproved, PlaceAccessRequestDenied, HandoverProtocolCreated, HandoverCompleted, HandoverExpired, HandoverReminderDue, ContractExpiringSoon, ContractTerminated, ContractTerminatedDueToPaymentFailure, InvoiceCreated, RecurringPaymentCharged, RecurringPaymentFailed, RecurringPaymentCancelled, TerminationNoticeRequested.

Many handlers exist for email side-effects (Send*Handler) and bookkeeping (IssueInvoiceOnPaymentHandler, RecordPaymentOn*Handler, ReleaseStorageOn*Handler).

## Enums
UserRole, OrderStatus, StorageStatus, HandoverStatus, PlaceAccessRequestStatus, PlaceType, RentalType (UNLIMITED/FIXED_TERM), RequestStatus, TerminationReason, PaymentMethod, PaymentFrequency (ONCE/MONTHLY/QUARTERLY/ANNUALLY), SigningMethod (DIGITAL/PHYSICAL).

## Forms
Registration, LandlordRegistration, RequestPasswordReset, ResetPassword, ChangePassword, Profile, BillingInfo, Place, Storage, StorageType, StorageUnavailability, PlaceProposal, Order, UserRole (admin), AdminUser, AdminCreateOnboarding, AdminMigrateCustomer, LandlordHandover, TenantHandover.

## Services of interest
- Payment: `GoPay\GoPayClient`, `GoPay\GoPayApiClient`, `RecurringPaymentCancelUrlGenerator`
- Invoicing: `Fakturoid\FakturoidClient`, `InvoicingService`, `SelfBillingService`
- Security voters: ContractVoter, OrderVoter, PlaceVoter, StorageVoter, StorageTypeVoter, HandoverProtocolVoter, DeactivatedUserChecker, LoginSubscriber
- Identity: `ProvideIdentity` (UUID v7), `RandomIdentityProvider`, `PredictableIdentityProvider` (tests)
- ARES lookup: `AresService`, `AresLookup`, `AresResult`, `AresSubject`, `AresAddress`
- Uploads: `PlaceFileUploader`, `StoragePhotoUploader`, `StorageTypePhotoUploader`, `HandoverPhotoUploader`, `PublicFilesystem`, `SignatureStorage`
- Domain utilities: `PriceCalculator`, `CommissionCalculator`, `ContractService`, `ContractDocumentGenerator`, `DocumentPdfConverter`, `StorageMapImageGenerator`, `StorageAvailabilityChecker`, `StorageAssignment`, `AtRiskContractChecker`, `AuditLogger`

## Templates
`templates/{base.html.twig, user/, portal/, admin/, public/, email/, form/, components/, partials/, documents/, bundles/}`

## Fixtures
UserFixtures, PlaceFixtures, StorageFixtures, StorageTypeFixtures, StorageUnavailabilityFixtures, OrderFixtures, ContractFixtures, InvoiceFixtures, PlaceAccessFixtures, PlaceAccessRequestFixtures.
Test users: admin@/landlord@/landlord2@/user@/tenant@/unverified@example.com, password `password`.

## Conventions reminder
- `declare(strict_types=1);` everywhere; `final readonly` for commands/events/DTOs
- Repositories use `EntityManager` composition, never extend `ServiceEntityRepository`, never call `flush()`
- Single-action controllers (`__invoke()`); route at class level
- Entities use property hooks (PHP 8.4+ syntax); UUIDs come from `ProvideIdentity`
- Tests: MockClock fixed at `2025-06-15 12:00:00 UTC`
- Czech UI text requires full diacritics
- Turbo is disabled globally; opt-in with `data-turbo="true"`
