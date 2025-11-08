# Symfony Application Implementation Plan

## Project Overview
- **Symfony Version**: 7.2 LTS
- **PHP Version**: 8.4
- **Web Server**: FrankenPHP (with worker mode)
- **Database**: PostgreSQL 17
- **Mail Testing**: Mailpit (axllent/mailpit)
- **Frontend**: Tailwind CSS + DaisyUI (no JS frameworks)
- **Architecture**: Clean Architecture with CQRS using Symfony Messenger
- **Git Repository**: git@github.com:JanMikes/fajnesklady.cz.git

## Architecture Decisions
- **Email Verification**: Required during registration
- **Role Assignment**: All new users get ROLE_USER by default
- **Development Fixtures**: Included for testing (admin@example.com, user@example.com)
- **All write operations**: Handled via Symfony Messenger command handlers
- **Domain Model**: Pure PHP with no framework dependencies
- **Persistence Mapping**: Doctrine XML mapping (keeps domain clean)

---

## Phase 1: Docker Infrastructure Setup

### 1.1 Create Docker PHP Configuration
- [ ] Create `docker/php/Dockerfile`
  - Multi-stage build with `base` and `dev` stages
  - Base image: `dunglas/frankenphp:1-php8.4-bookworm`
  - Install system dependencies: `acl`, `git`, `unzip`
  - Install PHP extensions: `pdo_pgsql`, `pgsql`, `intl`, `opcache`, `apcu`
  - Install xdebug in dev stage only
  - Install Composer 2.x
  - Configure PHP memory limit, opcache settings

- [ ] Create `docker/php/docker-entrypoint.sh`
  - Wait for PostgreSQL availability (pg_isready check)
  - Install Composer dependencies if vendor/ missing
  - Run Doctrine migrations automatically
  - Set proper permissions for var/ directory
  - Execute original Docker PHP entrypoint

- [ ] Create `docker/php/conf.d/app.dev.ini`
  - Enable error reporting
  - Configure xdebug
  - Set memory_limit=512M

- [ ] Create `docker/php/conf.d/app.prod.ini`
  - Disable error display
  - Enable opcache
  - Configure production settings

### 1.2 Create FrankenPHP Configuration
- [ ] Create `docker/frankenphp/Caddyfile`
  - Enable FrankenPHP module
  - Configure root directory: `public/`
  - Enable PHP server with worker mode
  - Worker file: `public/index.php`
  - Worker environment: `APP_RUNTIME=Runtime\FrankenPhpSymfony\Runtime`
  - Configure HTTPS (auto)
  - Enable HTTP/2 and HTTP/3

### 1.3 Create Docker Compose Configuration
- [ ] Create `compose.yaml`
  - **php service**:
    - Build from `docker/php/Dockerfile` (target: dev)
    - Ports: 80:80, 443:443
    - Volumes: code, Caddyfile
    - Depends on: postgres, mailpit
    - Environment variables from .env

  - **postgres service**:
    - Image: `postgres:17-alpine`
    - Port: 5432:5432
    - Environment: POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD
    - Named volume: postgres_data

  - **mailpit service**:
    - Image: `axllent/mailpit:latest`
    - Ports: 1025:1025 (SMTP), 8025:8025 (UI)

- [ ] Create `compose.override.yaml`
  - Development-specific overrides
  - Mount host directories for live reloading
  - Enable xdebug remote host configuration

### 1.4 Supporting Files
- [ ] Create `.dockerignore`
  - Exclude: vendor/, var/, .git/, .env.local

- [ ] Create `Makefile`
  - `make up`: Start containers
  - `make down`: Stop containers
  - `make reset`: Rebuild and restart
  - `make logs`: Follow logs
  - `make shell`: PHP container shell
  - `make db`: PostgreSQL shell

---

## Phase 2: Symfony Application Foundation

### 2.1 Initialize Symfony Project
- [ ] Create new Symfony 7.2 LTS project using skeleton
  ```bash
  symfony new . --version=7.2 --no-git
  ```

- [ ] Update `composer.json`
  - Set PHP requirement: `"php": ">=8.4"`
  - Configure PSR-4 autoloading

- [ ] Install core bundles
  ```bash
  composer require symfony/webapp-pack
  composer require symfony/orm-pack
  composer require doctrine/doctrine-bundle
  composer require doctrine/orm
  composer require symfony/mailer
  composer require symfony/messenger
  composer require symfony/asset-mapper
  composer require symfony/stimulus-bundle
  composer require runtime/frankenphp-symfony
  composer require --dev symfony/maker-bundle
  composer require --dev symfony/debug-bundle
  composer require --dev symfony/web-profiler-bundle
  ```

### 2.2 Create Simplified Folder Structure
- [ ] Create directory structure:
  ```
  src/
  ├── Common/
  │   ├── Email/              # Email service
  │   └── ValueObject/        # Shared value objects
  │
  ├── User/
  │   ├── Entity/
  │   │   └── User.php        # User entity (domain model)
  │   ├── Repository/
  │   │   ├── UserRepositoryInterface.php
  │   │   └── UserRepository.php
  │   ├── Command/            # Write operations (CQRS commands)
  │   │   ├── RegisterUserCommand.php
  │   │   ├── RegisterUserHandler.php
  │   │   ├── VerifyEmailCommand.php
  │   │   ├── VerifyEmailHandler.php
  │   │   ├── RequestPasswordResetCommand.php
  │   │   ├── RequestPasswordResetHandler.php
  │   │   ├── ResetPasswordCommand.php
  │   │   └── ResetPasswordHandler.php
  │   ├── Query/              # Read operations (CQRS queries)
  │   │   ├── FindUserByIdQuery.php
  │   │   └── FindUserByIdHandler.php
  │   ├── Event/              # Domain events
  │   │   ├── UserRegistered.php
  │   │   ├── EmailVerified.php
  │   │   ├── PasswordResetRequested.php
  │   │   ├── SendVerificationEmailHandler.php
  │   │   ├── SendWelcomeEmailHandler.php
  │   │   └── SendPasswordResetEmailHandler.php
  │   ├── Security/
  │   │   └── LoginSubscriber.php
  │   ├── Controller/         # Public controllers
  │   │   ├── HomeController.php
  │   │   ├── RegistrationController.php
  │   │   ├── LoginController.php
  │   │   ├── PasswordResetController.php
  │   │   └── ProfileController.php
  │   └── Form/
  │       ├── RegistrationType.php
  │       ├── RequestPasswordResetType.php
  │       └── ResetPasswordType.php
  │
  └── Admin/
      ├── Command/            # Admin write operations
      │   ├── ChangeUserRoleCommand.php
      │   └── ChangeUserRoleHandler.php
      ├── Query/              # Admin read operations
      │   ├── FindAllUsersQuery.php
      │   ├── FindAllUsersHandler.php
      │   ├── GetDashboardStatsQuery.php
      │   └── GetDashboardStatsHandler.php
      ├── Controller/
      │   ├── DashboardController.php
      │   └── UserManagementController.php
      └── Form/
          └── ChangeUserRoleType.php
  ```

  **Note**: This flatter structure maintains clean architecture principles:
  - Entities contain domain logic (no framework dependencies)
  - Commands/Queries implement CQRS pattern via Messenger
  - Repositories abstract data access
  - Events enable decoupled communication
  - Controllers are thin, delegating to command/query handlers

### 2.3 Configure Environment
- [ ] Create `.env` file
  ```
  APP_ENV=dev
  APP_SECRET=generate_random_secret_here
  DATABASE_URL="postgresql://app:password@postgres:5432/app?serverVersion=17&charset=utf8"
  MAILER_DSN=smtp://mailpit:1025
  ```

- [ ] Create `.env.local.example`
  - Template for local overrides

- [ ] Configure `config/packages/doctrine.yaml`
  - Set server_version: '17'
  - Enable auto_mapping
  - Configure XML mapping paths
  - Set naming strategy: underscore_number_aware

- [ ] Configure `config/packages/messenger.yaml`
  - Define three message buses:
    - `command.bus`: with doctrine_transaction, validation middleware
    - `query.bus`: with validation middleware
    - `event.bus`: allow_no_handlers, with validation
  - Configure routing based on namespace

### 2.4 Git Repository Setup
- [ ] Initialize git repository
  ```bash
  git init
  ```

- [ ] Create `.gitignore`
  ```
  ###> symfony/framework-bundle ###
  /.env.local
  /.env.local.php
  /.env.*.local
  /config/secrets/prod/prod.decrypt.private.php
  /public/bundles/
  /var/
  /vendor/
  ###< symfony/framework-bundle ###

  ###> phpunit/phpunit ###
  /phpunit.xml
  .phpunit.result.cache
  ###< phpunit/phpunit ###

  ###> symfony/asset-mapper ###
  /public/assets/
  /assets/vendor/
  ###< symfony/asset-mapper ###

  ###> Docker ###
  /docker/volumes/
  ###< Docker ###

  .DS_Store
  .idea/
  *.swp
  ```

- [ ] Add git remote
  ```bash
  git remote add origin git@github.com:JanMikes/fajnesklady.cz.git
  ```

- [ ] Create initial commit
  ```bash
  git add .
  git commit -m "Initial Symfony 7.2 LTS setup with Docker infrastructure"
  git branch -M main
  git push -u origin main
  ```

---

## Phase 3: Database & User Domain

### 3.1 Create User Entity
- [ ] Create `src/User/Entity/User.php`
  - Implements `UserInterface`, `PasswordAuthenticatedUserInterface`
  - Properties:
    - `id`: UUID (Symfony\Component\Uid\Uuid)
    - `email`: string (unique)
    - `password`: string (hashed)
    - `name`: string
    - `roles`: array (default: ['ROLE_USER'])
    - `isVerified`: bool (default: false)
    - `createdAt`: DateTimeImmutable
    - `updatedAt`: DateTimeImmutable
  - Methods:
    - `getUserIdentifier()`: string
    - `getRoles()`: array
    - `getPassword()`: string
    - `eraseCredentials()`: void
    - `markAsVerified()`: void
    - `changeRole(string $role)`: void
  - Keep entity clean with business logic only

### 3.2 Create Value Objects (Optional)
- [ ] Create `src/Common/ValueObject/Email.php` (if needed)
  - Validate email format
  - Immutable

### 3.3 Create Domain Events
- [ ] Create `src/User/Event/UserRegistered.php`
  - Properties: userId, email, name, occurredOn

- [ ] Create `src/User/Event/EmailVerified.php`
  - Properties: userId, occurredOn

- [ ] Create `src/User/Event/PasswordResetRequested.php`
  - Properties: userId, email, occurredOn

### 3.4 Create Repository Interface
- [ ] Create `src/User/Repository/UserRepositoryInterface.php`
  - Methods:
    - `save(User $user): void`
    - `findById(Uuid $id): ?User`
    - `findByEmail(string $email): ?User`
    - `findAll(): array`
    - `findAllPaginated(int $page, int $limit): array`

### 3.5 Create Doctrine Mapping
- [ ] Create `config/doctrine/User.orm.xml`
  ```xml
  <?xml version="1.0" encoding="utf-8"?>
  <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
      <entity name="App\User\Entity\User"
              repository-class="App\User\Repository\UserRepository"
              table="users">

          <id name="id" type="uuid">
              <generator strategy="CUSTOM"/>
              <custom-id-generator class="Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator"/>
          </id>

          <field name="email" type="string" length="180" unique="true"/>
          <field name="password" type="string"/>
          <field name="name" type="string" length="255"/>
          <field name="roles" type="json"/>
          <field name="isVerified" type="boolean" column="is_verified"/>
          <field name="createdAt" type="datetime_immutable" column="created_at"/>
          <field name="updatedAt" type="datetime_immutable" column="updated_at"/>

          <indexes>
              <index name="email_idx" columns="email"/>
          </indexes>
      </entity>
  </doctrine-mapping>
  ```

### 3.6 Create Repository Implementation
- [ ] Create `src/User/Repository/UserRepository.php`
  - Extends `ServiceEntityRepository`
  - Implements `UserRepositoryInterface`
  - Implement all interface methods
  - Add custom query methods as needed

### 3.7 Configure Services
- [ ] Update `config/services.yaml`
  ```yaml
  services:
      # Repository interface binding
      App\User\Repository\UserRepositoryInterface:
          class: App\User\Repository\UserRepository
  ```

### 3.8 Create Database Migration
- [ ] Create initial migration
  ```bash
  php bin/console make:migration
  ```

- [ ] Review and adjust migration
  - Verify users table structure
  - Add indexes
  - Set proper constraints

- [ ] Run migration
  ```bash
  php bin/console doctrine:migrations:migrate
  ```

---

## Phase 4: Security Configuration

### 4.1 Configure Security Bundle
- [ ] Update `config/packages/security.yaml`
  ```yaml
  security:
      password_hashers:
          Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'

      providers:
          app_user_provider:
              entity:
                  class: App\User\Entity\User
                  property: email

      firewalls:
          dev:
              pattern: ^/(_(profiler|wdt)|css|images|js)/
              security: false

          main:
              lazy: true
              provider: app_user_provider

              form_login:
                  login_path: app_login
                  check_path: app_login
                  enable_csrf: true
                  default_target_path: app_home

              logout:
                  path: app_logout
                  target: app_login

              remember_me:
                  secret: '%kernel.secret%'
                  lifetime: 604800
                  path: /
                  always_remember_me: true

      access_control:
          - { path: ^/admin, roles: ROLE_ADMIN }
          - { path: ^/login, roles: PUBLIC_ACCESS }
          - { path: ^/register, roles: PUBLIC_ACCESS }
          - { path: ^/reset-password, roles: PUBLIC_ACCESS }
          - { path: ^/verify-email, roles: PUBLIC_ACCESS }

      role_hierarchy:
          ROLE_ADMIN: ROLE_USER
  ```

### 4.2 Create Login Event Subscriber (for email verification)
- [ ] Create `src/User/Security/LoginSubscriber.php`
  - Check if user is verified on login
  - Block unverified users with helpful message

### 4.3 Configure Routing
- [ ] Create `config/routes/admin.yaml`
  ```yaml
  admin_controllers:
      resource: ../../src/Admin/Controller/
      type: attribute
      prefix: /admin
  ```

- [ ] Create `config/routes/public.yaml`
  ```yaml
  public_controllers:
      resource: ../../src/User/Controller/
      type: attribute
  ```

---

## Phase 5: Registration with Email Verification

### 5.1 Install Email Verification Bundle
- [ ] Install bundle
  ```bash
  composer require symfonycasts/verify-email-bundle
  ```

### 5.2 Create Registration Command & Handler
- [ ] Create `src/User/Command/RegisterUserCommand.php`
  - Properties: email, password, name
  - Readonly, immutable
  - Add validation constraints

- [ ] Create `src/User/Command/RegisterUserHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface, UserPasswordHasherInterface, MessageBusInterface (for events)
  - Logic:
    - Check email uniqueness
    - Create new User entity
    - Hash password
    - Set isVerified = false
    - Set default role: ROLE_USER
    - Save user
    - Dispatch UserRegisteredEvent

### 5.3 Create Email Verification Command & Handler
- [ ] Create `src/User/Command/VerifyEmailCommand.php`
  - Properties: userId, token

- [ ] Create `src/User/Command/VerifyEmailHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface, VerifyEmailHelperInterface, MessageBusInterface
  - Logic:
    - Validate token
    - Find user
    - Mark user as verified
    - Save user
    - Dispatch EmailVerifiedEvent

### 5.4 Create Event Handlers for Emails
- [ ] Create `src/User/Event/SendVerificationEmailHandler.php`
  - Listens to: UserRegisteredEvent
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: VerifyEmailHelperInterface, MailerInterface
  - Logic:
    - Generate verification token
    - Create email with verification link
    - Send email

### 5.5 Create Registration Form
- [ ] Create `src/User/Form/RegistrationType.php`
  - Fields: email, name, password (repeated), agreeTerms (checkbox)
  - Add validation constraints
  - Configure form theme for Tailwind

### 5.6 Create Registration Controller
- [ ] Create `src/User/Controller/RegistrationController.php`
  - Route: `/register`
  - Method: `register(Request, MessageBusInterface)`
  - Logic:
    - Handle form submission
    - Create RegisterUserCommand
    - Dispatch to command bus
    - Redirect to login with flash message

- [ ] Create verification handler method
  - Route: `/verify-email`
  - Method: `verify(Request, MessageBusInterface)`
  - Logic:
    - Extract token from URL
    - Create VerifyEmailCommand
    - Dispatch to command bus
    - Redirect to login with success message

### 5.7 Create Registration Templates
- [ ] Create `templates/user/register.html.twig`
  - Extends `user/layout.html.twig`
  - Registration form with DaisyUI styling
  - Form fields styled with Tailwind
  - Error messages display
  - Link to login page

- [ ] Create `templates/user/verify_email_confirmation.html.twig`
  - Success message after registration
  - Instructions to check email

---

## Phase 6: Login System

### 6.1 Create Login Controller
- [ ] Create `src/User/Controller/LoginController.php`
  - Route: `/login` (name: app_login)
  - Method: `login(AuthenticationUtils)`
  - Logic:
    - Get last authentication error
    - Get last username
    - Render login form

- [ ] Add logout route
  - Route: `/logout` (name: app_logout)
  - Empty method (handled by security)

### 6.2 Create Login Template
- [ ] Create `templates/user/login.html.twig`
  - Extends `user/layout.html.twig`
  - Login form with DaisyUI card component
  - Email and password fields
  - Remember me checkbox
  - CSRF token field
  - Error message display
  - Links to: register, forgot password

### 6.3 Add Login Verification Check
- [ ] Update `src/User/Security/LoginSubscriber.php` (created in Phase 4)
  - Listen to login success event
  - Check if user is verified
  - Block unverified users with helpful message

---

## Phase 7: Password Reset (Forgot Password)

### 7.1 Install Reset Password Bundle
- [ ] Install bundle
  ```bash
  composer require symfonycasts/reset-password-bundle
  ```

- [ ] Run maker command
  ```bash
  php bin/console make:reset-password
  ```

### 7.2 Create Password Reset Commands & Handlers
- [ ] Create `src/User/Command/RequestPasswordResetCommand.php`
  - Properties: email

- [ ] Create `src/User/Command/RequestPasswordResetHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface, ResetPasswordHelperInterface, MessageBusInterface
  - Logic:
    - Find user by email
    - Generate reset token
    - Dispatch PasswordResetRequestedEvent

- [ ] Create `src/User/Command/ResetPasswordCommand.php`
  - Properties: token, newPassword

- [ ] Create `src/User/Command/ResetPasswordHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface, ResetPasswordHelperInterface, UserPasswordHasherInterface
  - Logic:
    - Validate token
    - Find user
    - Hash new password
    - Update user password
    - Invalidate token
    - Save user

### 7.3 Create Password Reset Event Handler
- [ ] Create `src/User/Event/SendPasswordResetEmailHandler.php`
  - Listens to: PasswordResetRequestedEvent
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: ResetPasswordHelperInterface, MailerInterface
  - Logic:
    - Generate reset link
    - Create email with reset link
    - Send email

### 7.4 Create Password Reset Forms
- [ ] Create `src/User/Form/RequestPasswordResetType.php`
  - Field: email

- [ ] Create `src/User/Form/ResetPasswordType.php`
  - Field: password (repeated)

### 7.5 Create Password Reset Controllers
- [ ] Create `src/User/Controller/PasswordResetController.php`
  - Route: `/reset-password/request`
  - Method: `request()` - Show request form and handle submission
  - Dispatch RequestPasswordResetCommand

  - Route: `/reset-password/reset/{token}`
  - Method: `reset()` - Show reset form and handle submission
  - Dispatch ResetPasswordCommand

### 7.6 Create Password Reset Templates
- [ ] Create `templates/user/reset_password/request.html.twig`
  - Form to enter email
  - DaisyUI styling

- [ ] Create `templates/user/reset_password/check_email.html.twig`
  - Confirmation message
  - Instructions

- [ ] Create `templates/user/reset_password/reset.html.twig`
  - Form to enter new password
  - Token passed from URL

---

## Phase 8: Frontend Setup - Tailwind + DaisyUI

### 8.1 Install and Configure Tailwind CSS
- [ ] Install Tailwind bundle
  ```bash
  composer require symfonycasts/tailwind-bundle
  ```

- [ ] Initialize Tailwind
  ```bash
  php bin/console tailwind:init
  ```

- [ ] Configure `tailwind.config.js`
  ```javascript
  /** @type {import('tailwindcss').Config} */
  module.exports = {
    content: [
      "./assets/**/*.js",
      "./templates/**/*.html.twig",
    ],
    theme: {
      extend: {},
    },
    plugins: [
      require('daisyui'),
    ],
    daisyui: {
      themes: ["light", "dark"],
      darkTheme: "dark",
      base: true,
      styled: true,
      utils: true,
    },
  }
  ```

- [ ] Update `assets/styles/app.css`
  ```css
  @tailwind base;
  @tailwind components;
  @tailwind utilities;

  /* Custom styles */
  ```

### 8.2 Install DaisyUI
- [ ] Install DaisyUI via importmap
  ```bash
  php bin/console importmap:require daisyui
  ```

- [ ] Or install via npm/package manager if using build process
  ```bash
  npm install -D daisyui@latest
  ```

### 8.3 Configure AssetMapper
- [ ] Verify `config/packages/asset_mapper.yaml` configuration
  - Ensure paths include `assets/`

- [ ] Update `importmap.php` if needed
  - Add third-party packages

- [ ] Configure `assets/app.js`
  - Import Stimulus controllers if needed
  - Initialize any required JavaScript

### 8.4 Create Base Templates
- [ ] Create `templates/base.html.twig`
  ```twig
  <!DOCTYPE html>
  <html lang="en" data-theme="light">
  <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>{% block title %}Fajné Sklady{% endblock %}</title>

      {% block stylesheets %}
          <link rel="stylesheet" href="{{ asset('styles/app.css') }}">
      {% endblock %}

      {% block importmap %}
          {{ importmap('app') }}
      {% endblock %}
  </head>
  <body>
      {% block body %}{% endblock %}
  </body>
  </html>
  ```

- [ ] Create `templates/user/layout.html.twig`
  - Extends `base.html.twig`
  - Public navigation (Home, About, Login, Register)
  - Main content area
  - Footer
  - Flash messages component

- [ ] Create `templates/admin/layout.html.twig`
  - Extends `base.html.twig`
  - Admin navigation (Dashboard, Users, Settings, Logout)
  - Sidebar layout
  - Main content area
  - User info display

### 8.5 Create Reusable Components
- [ ] Create `templates/components/flash_messages.html.twig`
  - Display success/error/warning messages
  - DaisyUI alert component
  - Auto-dismiss functionality

- [ ] Create `templates/components/navbar.html.twig`
  - Responsive navbar
  - DaisyUI navbar component
  - Mobile menu

- [ ] Create `templates/components/footer.html.twig`
  - Site footer
  - Links, copyright

### 8.6 Build Assets
- [ ] Add build scripts to `composer.json`
  ```json
  "scripts": {
      "tailwind:build": "php bin/console tailwind:build",
      "tailwind:watch": "php bin/console tailwind:build --watch",
      "assets:compile": "php bin/console asset-map:compile"
  }
  ```

- [ ] Build Tailwind CSS
  ```bash
  php bin/console tailwind:build
  ```

---

## Phase 9: Public Module (Presentation)

### 9.1 Create Home Controller
- [ ] Create `src/User/Controller/HomeController.php`
  - Route: `/` (name: app_home)
  - Method: `index()`
  - Render landing page about the project

### 9.2 Create Public Templates
- [ ] Create `templates/user/home.html.twig`
  - Hero section with project description
  - Features section
  - Call-to-action buttons (Register, Login)
  - DaisyUI components: hero, card, button

- [ ] Already created in previous phases:
  - `templates/user/login.html.twig`
  - `templates/user/register.html.twig`
  - `templates/user/reset_password/request.html.twig`
  - `templates/user/reset_password/reset.html.twig`
  - `templates/user/verify_email_confirmation.html.twig`

### 9.3 Add Navigation Logic
- [ ] Update `templates/user/layout.html.twig`
  - Show different nav items based on authentication
  - Logged in: Profile, Logout
  - Logged out: Login, Register
  - Admin role: Admin Panel link

### 9.4 Create Profile Page (Optional for Users)
- [ ] Create `src/User/Controller/ProfileController.php`
  - Route: `/profile`
  - Require authentication
  - Display user information

- [ ] Create `templates/user/profile.html.twig`
  - Show user details
  - Option to change password (future feature)

---

## Phase 10: Admin Module

### 10.1 Create Admin Dashboard
- [ ] Create `src/Admin/Query/GetDashboardStatsQuery.php`
  - Empty query (no parameters needed)

- [ ] Create `src/Admin/Query/GetDashboardStatsHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface
  - Return DTO with:
    - Total users count
    - Verified users count
    - Admins count
    - Recent registrations

- [ ] Create `src/Admin/Controller/DashboardController.php`
  - Route: `/admin/dashboard` (name: admin_dashboard)
  - Attribute: `#[IsGranted('ROLE_ADMIN')]`
  - Query dashboard stats
  - Render dashboard

- [ ] Create `templates/admin/dashboard.html.twig`
  - Stats cards (DaisyUI stats component)
  - Quick links
  - Recent activity table

### 10.2 Create User Management Queries
- [ ] Create `src/Admin/Query/FindAllUsersQuery.php`
  - Properties: page, limit

- [ ] Create `src/Admin/Query/FindAllUsersHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface
  - Return paginated user list

- [ ] Create `src/Admin/Query/FindUserByIdQuery.php`
  - Properties: userId

- [ ] Create `src/Admin/Query/FindUserByIdHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface
  - Return user details

### 10.3 Create User Management Commands
- [ ] Create `src/Admin/Command/ChangeUserRoleCommand.php`
  - Properties: userId, newRole

- [ ] Create `src/Admin/Command/ChangeUserRoleHandler.php`
  - Attribute: `#[AsMessageHandler]`
  - Dependencies: UserRepositoryInterface
  - Logic:
    - Find user
    - Validate role
    - Update user role
    - Save user

### 10.4 Create User Management Controller
- [ ] Create `src/Admin/Controller/UserManagementController.php`
  - Attribute: `#[IsGranted('ROLE_ADMIN')]`

  - Route: `/admin/users` (name: admin_users_list)
  - Method: `list(Request, MessageBusInterface)`
  - Query all users with pagination
  - Render user list

  - Route: `/admin/users/{id}` (name: admin_users_view)
  - Method: `view(Uuid, MessageBusInterface)`
  - Query user by ID
  - Render user details

  - Route: `/admin/users/{id}/edit` (name: admin_users_edit)
  - Method: `edit(Uuid, Request, MessageBusInterface)`
  - Handle role change form
  - Dispatch ChangeUserRoleCommand

### 10.5 Create Admin Forms
- [ ] Create `src/Admin/Form/ChangeUserRoleType.php`
  - Field: role (choice field)
  - Options: ROLE_USER, ROLE_ADMIN

### 10.6 Create Admin Templates
- [ ] Create `templates/admin/user/list.html.twig`
  - DaisyUI table component
  - Columns: ID, Name, Email, Role, Verified, Created At, Actions
  - Pagination controls
  - Search/filter (future enhancement)

- [ ] Create `templates/admin/user/view.html.twig`
  - User details card
  - All user information
  - Action buttons (Edit, Back)

- [ ] Create `templates/admin/user/edit.html.twig`
  - Form to change user role
  - DaisyUI form components
  - Cancel and Save buttons

---

## Phase 11: Email Templates & Service

### 11.1 Create Email Service
- [ ] Create `src/Common/Email/EmailService.php`
  - Dependencies: MailerInterface, TwigInterface
  - Methods:
    - `sendVerificationEmail(string $to, string $name, string $verificationUrl): void`
    - `sendWelcomeEmail(string $to, string $name): void`
    - `sendPasswordResetEmail(string $to, string $name, string $resetUrl): void`
  - Use Twig to render email templates

### 11.2 Create Email Templates
- [ ] Create `templates/email/verification.html.twig`
  - Welcome message
  - Verification button/link
  - Expiry information
  - Responsive HTML email design

- [ ] Create `templates/email/welcome.html.twig`
  - Welcome message after verification
  - Getting started information
  - Useful links

- [ ] Create `templates/email/password_reset.html.twig`
  - Password reset instructions
  - Reset button/link
  - Security notice
  - Expiry information

### 11.3 Configure Mailer
- [ ] Verify `.env` configuration
  ```
  MAILER_DSN=smtp://mailpit:1025
  ```

- [ ] Configure sender defaults in `config/packages/mailer.yaml`
  ```yaml
  framework:
      mailer:
          dsn: '%env(MAILER_DSN)%'
          envelope:
              sender: 'noreply@fajnesklady.cz'
          headers:
              from: 'Fajné Sklady <noreply@fajnesklady.cz>'
  ```

### 11.4 Update Event Handlers to Use Email Service
- [ ] Update `SendVerificationEmailHandler`
  - Use EmailService instead of direct MailerInterface

- [ ] Update `SendPasswordResetEmailHandler`
  - Use EmailService instead of direct MailerInterface

- [ ] Create `SendWelcomeEmailHandler`
  - Listen to EmailVerifiedEvent
  - Send welcome email after verification

---

## Phase 12: Development Fixtures

### 12.1 Install Fixtures Bundle
- [ ] Install bundle
  ```bash
  composer require --dev doctrine/doctrine-fixtures-bundle
  ```

### 12.2 Create User Fixtures
- [ ] Create `src/DataFixtures/UserFixtures.php`
  - Dependencies: UserPasswordHasherInterface
  - Create fixtures:
    1. Admin user
       - Email: admin@example.com
       - Password: admin123
       - Role: ROLE_ADMIN
       - Verified: true
    2. Regular user
       - Email: user@example.com
       - Password: user123
       - Role: ROLE_USER
       - Verified: true
    3. Unverified user
       - Email: unverified@example.com
       - Password: user123
       - Role: ROLE_USER
       - Verified: false

### 12.3 Add Fixture Loading Script
- [ ] Add to `composer.json` scripts
  ```json
  "scripts": {
      "db:fixtures": [
          "php bin/console doctrine:fixtures:load --no-interaction"
      ],
      "db:reset": [
          "php bin/console doctrine:database:drop --force --if-exists",
          "php bin/console doctrine:database:create",
          "php bin/console doctrine:migrations:migrate --no-interaction",
          "@db:fixtures"
      ]
  }
  ```

### 12.4 Document Fixture Credentials
- [ ] Update README.md with fixture credentials
  - List all test accounts
  - Include passwords
  - Note that these are for development only

---

## Phase 13: Validation & Error Handling

### 13.1 Add Validation to Commands
- [ ] Add constraints to `src/User/Command/RegisterUserCommand.php`
  - Email: NotBlank, Email
  - Password: NotBlank, Length(min: 8), PasswordStrength
  - Name: NotBlank, Length(max: 255)

- [ ] Add constraints to `src/User/Command/ResetPasswordCommand.php`
  - NewPassword: NotBlank, Length(min: 8), PasswordStrength

- [ ] Add constraints to `src/Admin/Command/ChangeUserRoleCommand.php`
  - NewRole: NotBlank, Choice(choices: ['ROLE_USER', 'ROLE_ADMIN'])

### 13.2 Configure Validation Middleware
- [ ] Ensure validation middleware is configured in `messenger.yaml`
  - Already configured in Phase 2.3

### 13.3 Create Custom Error Pages
- [ ] Create `templates/bundles/TwigBundle/Exception/error.html.twig`
  - Generic error page
  - DaisyUI styling

- [ ] Create `templates/bundles/TwigBundle/Exception/error404.html.twig`
  - 404 Not Found page
  - Helpful navigation links

- [ ] Create `templates/bundles/TwigBundle/Exception/error403.html.twig`
  - 403 Forbidden page
  - Explain access denied

- [ ] Create `templates/bundles/TwigBundle/Exception/error500.html.twig`
  - 500 Internal Server Error
  - Apologize, suggest actions

### 13.4 Implement Flash Messages
- [ ] Ensure flash messages component is included in layouts
  - Already created in Phase 8.5

- [ ] Add flash messages to all controllers
  - Registration: "Check your email to verify your account"
  - Login: "Invalid credentials" on error
  - Password reset: "Check your email for reset link"
  - Admin actions: "User role updated successfully"

### 13.5 Add Form Validation Styling
- [ ] Create custom form theme for Tailwind
  - Create `templates/form/tailwind_theme.html.twig`
  - Style form rows, labels, inputs, errors

- [ ] Configure form theme in `config/packages/twig.yaml`
  ```yaml
  twig:
      form_themes:
          - 'form/tailwind_theme.html.twig'
  ```

---

## Phase 14: Testing & Quality Assurance

### 14.1 Install Testing Tools
- [ ] Install test packages
  ```bash
  composer require --dev symfony/test-pack
  composer require --dev symfony/phpunit-bridge
  composer require --dev symfony/browser-kit
  composer require --dev symfony/css-selector
  composer require --dev dama/doctrine-test-bundle
  ```

### 14.2 Configure PHPUnit
- [ ] Configure `phpunit.xml.dist`
  - Test suite configuration
  - Database for testing
  - Enable DAMA bundle for test isolation

- [ ] Create `.env.test`
  ```
  DATABASE_URL="postgresql://app:password@postgres:5432/app_test?serverVersion=17&charset=utf8"
  ```

### 14.3 Write Unit Tests
- [ ] Create `tests/Unit/User/Entity/UserTest.php`
  - Test User entity methods
  - Test role management
  - Test verification logic

- [ ] Create `tests/Unit/Common/ValueObject/EmailTest.php` (if value objects created)
  - Test email validation
  - Test invalid email rejection

### 14.4 Write Integration Tests
- [ ] Create `tests/Integration/User/Repository/UserRepositoryTest.php`
  - Test repository methods
  - Test finding users
  - Test saving users
  - Test pagination

### 14.5 Write Functional Tests
- [ ] Create `tests/Functional/User/RegistrationTest.php`
  - Test registration flow
  - Test email sending
  - Test validation errors

- [ ] Create `tests/Functional/User/LoginTest.php`
  - Test successful login
  - Test failed login
  - Test unverified user login block

- [ ] Create `tests/Functional/User/PasswordResetTest.php`
  - Test password reset request
  - Test password reset with valid token
  - Test password reset with expired token

- [ ] Create `tests/Functional/Admin/UserManagementTest.php`
  - Test admin can access user list
  - Test admin can change user role
  - Test regular user cannot access admin

### 14.6 Install Code Quality Tools
- [ ] Install PHP CS Fixer
  ```bash
  composer require --dev friendsofphp/php-cs-fixer
  ```

- [ ] Create `.php-cs-fixer.php`
  - Configure PSR-12
  - Configure rules

- [ ] Install PHPStan
  ```bash
  composer require --dev phpstan/phpstan
  composer require --dev phpstan/extension-installer
  composer require --dev phpstan/phpstan-symfony
  composer require --dev phpstan/phpstan-doctrine
  ```

- [ ] Create `phpstan.neon`
  ```neon
  parameters:
      level: 8
      paths:
          - src
          - tests
      symfony:
          containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
  ```

### 14.7 Add Quality Scripts
- [ ] Add to `composer.json` scripts
  ```json
  "scripts": {
      "test": "php bin/phpunit",
      "test:coverage": "XDEBUG_MODE=coverage php bin/phpunit --coverage-html var/coverage",
      "cs:check": "php-cs-fixer fix --dry-run --diff",
      "cs:fix": "php-cs-fixer fix",
      "phpstan": "phpstan analyse",
      "quality": [
          "@cs:check",
          "@phpstan",
          "@test"
      ]
  }
  ```

### 14.8 Run All Tests
- [ ] Execute PHPUnit tests
  ```bash
  composer test
  ```

- [ ] Run code style check
  ```bash
  composer cs:check
  ```

- [ ] Run PHPStan analysis
  ```bash
  composer phpstan
  ```

- [ ] Fix any issues found

---

## Phase 15: Documentation

### 15.1 Create Main README
- [ ] Create comprehensive `README.md`
  - Project title and description
  - Features list
  - Technology stack
  - Prerequisites
  - Installation instructions
  - Usage guide
  - Development workflow
  - Testing instructions
  - Deployment guide
  - Contributing guidelines
  - License

### 15.2 Document Docker Setup
- [ ] Create `docker/README.md`
  - Docker services explanation
  - Configuration details
  - Common commands
  - Troubleshooting
  - Environment variables

### 15.3 Document Architecture
- [ ] Create `docs/ARCHITECTURE.md`
  - Clean architecture explanation
  - Folder structure
  - CQRS pattern usage
  - Messenger bus configuration
  - Domain-driven design principles
  - Repository pattern

### 15.4 Create API/Endpoint Documentation
- [ ] Create `docs/ENDPOINTS.md`
  - List all routes
  - Public routes
  - Protected routes
  - Admin routes
  - Parameters and responses

### 15.5 Document Development Guidelines
- [ ] Create `docs/DEVELOPMENT.md`
  - Code style guidelines
  - Testing requirements
  - Git workflow
  - Pull request process
  - Code review checklist

### 15.6 Create Environment Documentation
- [ ] Create `.env.example`
  - All required environment variables
  - Example values
  - Comments explaining each variable

- [ ] Create `.env.prod.example`
  - Production-ready configuration
  - Security considerations

---

## Phase 16: Production Preparation

### 16.1 Create Production Dockerfile
- [ ] Create `docker/php/Dockerfile.prod`
  - Single-stage optimized build
  - No development tools
  - Optimized PHP configuration
  - Precompiled assets included

### 16.2 Configure Production Environment
- [ ] Create `compose.prod.yaml`
  - Production service configuration
  - Resource limits
  - Restart policies
  - Health checks

- [ ] Create `.env.prod.example`
  - Production environment variables
  - Security-focused defaults

### 16.3 Optimize Performance
- [ ] Configure opcache for production
  - Update `docker/php/conf.d/app.prod.ini`
  - Enable opcache
  - Set optimal settings

- [ ] Configure APCu
  - Enable APCu in production
  - Configure cache settings

- [ ] Precompile assets
  ```bash
  php bin/console asset-map:compile
  php bin/console tailwind:build --minify
  ```

- [ ] Configure FrankenPHP worker mode
  - Optimize worker count
  - Configure max threads
  - Set memory limits

### 16.4 Security Hardening
- [ ] Review `security.yaml` for production
  - Ensure secure settings
  - Configure rate limiting

- [ ] Install rate limiter
  ```bash
  composer require symfony/rate-limiter
  ```

- [ ] Configure rate limiting for:
  - Login attempts
  - Registration
  - Password reset requests

- [ ] Run security check
  ```bash
  symfony security:check
  ```

- [ ] Configure Content Security Policy headers
  - Create `config/packages/framework.yaml` headers configuration

### 16.5 Database Optimization
- [ ] Review database indexes
  - Ensure email index exists
  - Add indexes for frequently queried fields

- [ ] Configure connection pooling
  - Optimize Doctrine configuration
  - Set max connections

### 16.6 Monitoring & Logging
- [ ] Configure production logging
  - Update `config/packages/monolog.yaml`
  - File rotation
  - Error levels

- [ ] Configure error tracking (optional)
  - Sentry integration if needed

---

## Phase 17: Final Testing & Deployment

### 17.1 Complete System Test
- [ ] Fresh database setup
  ```bash
  docker compose down -v
  docker compose up -d
  ```

- [ ] Run migrations
  ```bash
  docker compose exec php bin/console doctrine:migrations:migrate
  ```

- [ ] Load fixtures
  ```bash
  docker compose exec php bin/console doctrine:fixtures:load
  ```

- [ ] Test all features manually:
  - [ ] Visit home page
  - [ ] Register new account
  - [ ] Check email in Mailpit (port 8025)
  - [ ] Verify email
  - [ ] Login with verified account
  - [ ] Request password reset
  - [ ] Reset password
  - [ ] Login with admin account (admin@example.com)
  - [ ] Access admin dashboard
  - [ ] View user list
  - [ ] Change user role
  - [ ] Logout

### 17.2 Cross-Browser Testing
- [ ] Test in Chrome
- [ ] Test in Firefox
- [ ] Test in Safari
- [ ] Test mobile responsive design

### 17.3 Performance Testing
- [ ] Verify FrankenPHP worker mode is active
  - Check logs for worker initialization

- [ ] Test page load times
- [ ] Verify asset loading
- [ ] Check database query performance

### 17.4 Security Testing
- [ ] Test CSRF protection on forms
- [ ] Test authentication flows
- [ ] Test authorization (RBAC)
- [ ] Test XSS prevention
- [ ] Test SQL injection prevention
- [ ] Verify HTTPS enforcement

### 17.5 Git Cleanup & Tagging
- [ ] Review all committed files
  - Ensure no secrets
  - Ensure no unnecessary files

- [ ] Final commit
  ```bash
  git add .
  git commit -m "Complete initial implementation"
  ```

- [ ] Create git tag
  ```bash
  git tag -a v1.0.0 -m "Initial release"
  git push origin main --tags
  ```

### 17.6 Deploy to Production
- [ ] Push code to GitHub
  ```bash
  git push origin main
  ```

- [ ] Deploy to production server
  - Pull latest code
  - Build production Docker images
  - Run migrations
  - Start services

- [ ] Verify production deployment
  - Check all services running
  - Test critical paths
  - Monitor logs

- [ ] Set up monitoring
  - Health checks
  - Error monitoring
  - Performance monitoring

---

## Success Criteria Checklist

### Infrastructure
- [ ] Application runs entirely in Docker
- [ ] FrankenPHP web server configured and running
- [ ] FrankenPHP worker mode active
- [ ] PostgreSQL 17 connected
- [ ] Mailpit catching emails
- [ ] Docker Compose fully configured
- [ ] Git repository pushed to GitHub

### Database
- [ ] Doctrine configured for PostgreSQL
- [ ] User entity created with XML mapping
- [ ] Migrations created and executed
- [ ] Repositories implemented
- [ ] Fixtures working

### Security & Authentication
- [ ] User registration working
- [ ] Email verification required and working
- [ ] Login system working
- [ ] Password reset working
- [ ] Logout working
- [ ] RBAC implemented (ROLE_USER, ROLE_ADMIN)
- [ ] Access control working
- [ ] CSRF protection enabled

### Frontend
- [ ] Tailwind CSS integrated
- [ ] DaisyUI components used
- [ ] Responsive design
- [ ] No JavaScript frameworks (as required)
- [ ] Base templates created
- [ ] Public templates created
- [ ] Admin templates created
- [ ] Flash messages working

### Architecture
- [ ] Clean architecture folder structure
- [ ] All write operations use Messenger
- [ ] Command/Query separation (CQRS)
- [ ] Domain layer free of framework dependencies
- [ ] Event handlers for emails
- [ ] Repository pattern implemented

### Features
- [ ] Public module (presentation) accessible
- [ ] Admin dashboard accessible
- [ ] User management in admin
- [ ] Role management working
- [ ] Email sending working
- [ ] All forms validated

### Quality
- [ ] Unit tests written and passing
- [ ] Integration tests written and passing
- [ ] Functional tests written and passing
- [ ] Code style checks passing
- [ ] PHPStan analysis passing
- [ ] No security vulnerabilities

### Documentation
- [ ] README.md complete
- [ ] PLAN.md exists (this file)
- [ ] Architecture documented
- [ ] Docker setup documented
- [ ] Environment variables documented
- [ ] Fixture credentials documented

---

## Notes

### Access Points (Development)
- **Application**: https://localhost
- **Mailpit UI**: http://localhost:8025
- **PostgreSQL**: localhost:5432

### Default Credentials (Development Fixtures)
- **Admin**: admin@example.com / admin123
- **User**: user@example.com / user123
- **Unverified**: unverified@example.com / user123

### Key Technologies
- **Symfony**: 7.2 LTS
- **PHP**: 8.4
- **Web Server**: FrankenPHP (with worker mode)
- **Database**: PostgreSQL 17
- **Frontend**: Tailwind CSS + DaisyUI
- **Email Testing**: Mailpit
- **Architecture**: Clean Architecture + CQRS + DDD
- **Messaging**: Symfony Messenger

### Important Commands
```bash
# Start development
docker compose up -d

# Stop services
docker compose down

# View logs
docker compose logs -f php

# Access PHP container
docker compose exec php bash

# Run migrations
docker compose exec php bin/console doctrine:migrations:migrate

# Load fixtures
docker compose exec php bin/console doctrine:fixtures:load

# Build Tailwind
docker compose exec php bin/console tailwind:build --watch

# Run tests
docker compose exec php bin/phpunit

# Check code style
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run

# Run PHPStan
docker compose exec php vendor/bin/phpstan analyse
```
