# CoachPro — PHP Backend API

A RESTful PHP backend powering the CoachPro coaching platform. Uses MongoDB as the database, JWT for authentication, Stripe for subscriptions, and S3/R2 for media storage.

---

## Tech Stack

| Layer    | Technology                                        |
| -------- | ------------------------------------------------- |
| Language | PHP 8.1+                                          |
| Database | MongoDB (Atlas)                                   |
| Auth     | JWT (Bearer + httpOnly cookies)                   |
| Payments | Stripe (subscriptions, invoices, payment methods) |
| Email    | PHPMailer / SMTP                                  |
| Media    | AWS S3 / Cloudflare R2                            |
| Push     | Firebase Cloud Messaging (FCM v1)                 |

---

## Quick Start

```bash
# 1. Install PHP dependencies
composer install

# 2. Copy and configure environment
cp .env.example .env
# Fill in: MONGO_URI, JWT_SECRET, JWT_REFRESH_SECRET, STRIPE_SECRET_KEY, etc.

# 3. Create MongoDB indexes (run once after first setup)
php setup-indexes.php

# 4. Start development server
php -S 0.0.0.0:8000 -t public public/router.php
```

The API will be available at `http://localhost:8000/v1`.

Health check: `GET http://localhost:8000/v1/health`

---

## Environment Variables

Copy `.env.example` to `.env` and fill in the values below.

### Required

| Variable             | Description                                                 |
| -------------------- | ----------------------------------------------------------- |
| `MONGO_URI`          | MongoDB Atlas connection string                             |
| `MONGO_DB`           | Database name (default: `coaching_platform`)                |
| `JWT_SECRET`         | Secret for access tokens (generate: `openssl rand -hex 40`) |
| `JWT_REFRESH_SECRET` | Secret for refresh tokens                                   |
| `APP_ENV`            | `development` or `production`                               |
| `APP_URL`            | Backend public URL (e.g. `http://localhost:8000`)           |
| `FRONTEND_URL`       | Frontend dashboard URL for CORS & redirects                 |

### Stripe (required for subscription features)

| Variable                | Description                                        |
| ----------------------- | -------------------------------------------------- |
| `STRIPE_SECRET_KEY`     | Stripe secret key (`sk_test_...` or `sk_live_...`) |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret (`whsec_...`)        |
| `STRIPE_PRICE_PRO`      | Stripe Price ID for Pro monthly plan               |
| `STRIPE_PRICE_BUSINESS` | Stripe Price ID for Business monthly plan          |

### Optional

| Variable                   | Description                           |
| -------------------------- | ------------------------------------- |
| `EMAIL_HOST`               | SMTP host for transactional email     |
| `EMAIL_USERNAME`           | SMTP username                         |
| `EMAIL_PASSWORD`           | SMTP password                         |
| `FCM_SERVICE_ACCOUNT_PATH` | Path to Firebase service account JSON |
| `FCM_PROJECT_ID`           | Firebase project ID                   |
| `AWS_ACCESS_KEY_ID`        | S3/R2 access key                      |
| `AWS_SECRET_ACCESS_KEY`    | S3/R2 secret key                      |
| `AWS_S3_BUCKET`            | Bucket name                           |
| `AWS_ENDPOINT`             | Custom endpoint (for Cloudflare R2)   |

---

## Project Structure

```
backend/
├── app/
│   ├── Config/
│   │   └── Database.php          # MongoDB singleton (reads MONGO_URI)
│   ├── Controllers/
│   │   ├── AuthController.php    # Login, register, refresh, logout
│   │   ├── ClientController.php  # Coach: manage clients
│   │   ├── CoachController.php   # Coach profile, photo, password
│   │   ├── WorkoutPlanController.php
│   │   ├── WorkoutLogController.php
│   │   ├── WorkoutAnalysisController.php
│   │   ├── NutritionController.php
│   │   ├── CheckinController.php
│   │   ├── MessageController.php
│   │   ├── MediaController.php
│   │   ├── AnalyticsController.php
│   │   ├── GroupChatController.php
│   │   ├── LiveTrainingController.php
│   │   ├── CoachingSessionController.php
│   │   ├── SubscriptionController.php  # Stripe subscriptions, invoices, payment methods
│   │   ├── SettingsController.php      # Integrations, notification prefs, support tickets
│   │   └── NotificationsController.php
│   ├── Helpers/
│   │   ├── Router.php            # Lightweight HTTP router
│   │   ├── Request.php           # Body parsing, validation
│   │   └── Response.php          # JSON response helpers
│   ├── Middleware/
│   │   ├── AuthMiddleware.php    # Validates Bearer JWT (coach or client)
│   │   ├── CoachMiddleware.php   # Requires coach role
│   │   ├── ClientMiddleware.php  # Requires client role
│   │   ├── SetupAuthMiddleware.php  # Setup token (post-registration)
│   │   ├── SubscriptionMiddleware.php  # Enforces client cap by tier
│   │   └── AuthRateLimitMiddleware.php
│   └── Services/
│       ├── JwtService.php
│       ├── StripeService.php     # Full Stripe API wrapper
│       ├── EmailService.php      # Transactional email via SMTP
│       ├── S3Service.php         # Media upload / presigned URLs
│       ├── FcmService.php        # Push notifications
│       ├── WorkoutAnalysisService.php
│       ├── NotificationService.php
│       └── MongoIndexes.php      # Index definitions
├── bootstrap/app.php             # CORS, error handlers, router init
├── config/
│   ├── cors.php                  # Allowed origins (merges .env ALLOWED_ORIGINS)
│   └── Database.php
├── public/
│   ├── index.php                 # Entry point
│   └── router.php                # Dev-server router
├── routes/
│   └── api.php                   # All route registrations (v1 prefix)
├── scripts/
│   └── send-reminders.php        # Cron: send scheduled notifications
├── .env.example
└── composer.json
```

---

## API Reference

### Base URL

`http://localhost:8000/v1`

### Authentication

All protected endpoints require `Authorization: Bearer <access_token>`.
Tokens are also set as httpOnly cookies (`access_token`, `refresh_token`).

| Endpoint               | Method | Auth   | Description                          |
| ---------------------- | ------ | ------ | ------------------------------------ |
| `/health`              | GET    | —      | Health check + DB ping               |
| `/auth/coach/register` | POST   | —      | Register coach (returns setup_token) |
| `/auth/coach/login`    | POST   | —      | Coach login                          |
| `/auth/client/login`   | POST   | —      | Client login (by code)               |
| `/auth/refresh`        | POST   | Cookie | Refresh access token                 |
| `/auth/logout`         | POST   | Bearer | Logout                               |

### Coach — Profile

| Endpoint                  | Method | Description           |
| ------------------------- | ------ | --------------------- |
| `/coach/profile`          | GET    | Get own profile       |
| `/coach/profile`          | PUT    | Update profile fields |
| `/coach/profile/photo`    | POST   | Upload profile photo  |
| `/coach/profile/photo`    | DELETE | Remove profile photo  |
| `/coach/profile/password` | PUT    | Change password       |

### Coach — Clients

| Endpoint                              | Method         | Description                       |
| ------------------------------------- | -------------- | --------------------------------- |
| `/coach/clients`                      | GET            | List clients (search, pagination) |
| `/coach/clients`                      | POST           | Create client                     |
| `/coach/clients/:id`                  | GET/PUT/DELETE | View / update / delete client     |
| `/coach/clients/:id/block`            | POST           | Block client (revokes sessions)   |
| `/coach/clients/:id/unblock`          | POST           | Restore client access             |
| `/coach/clients/:id/analytics`        | GET            | Client analytics                  |
| `/coach/clients/:id/logs`             | GET            | Workout logs for client           |
| `/coach/clients/:id/workout-analyses` | GET/POST       | AI workout analysis               |

### Subscriptions & Billing

| Endpoint                              | Method | Description                                  |
| ------------------------------------- | ------ | -------------------------------------------- |
| `/subscription/select-plan`           | POST   | Select plan after registration (setup token) |
| `/subscription`                       | GET    | Current subscription status                  |
| `/subscription/checkout`              | POST   | Create Stripe checkout session               |
| `/subscription/upgrade`               | POST   | Upgrade / change plan immediately            |
| `/subscription/portal`                | POST   | Open Stripe customer portal                  |
| `/subscription/cancel`                | POST   | Cancel at end of period                      |
| `/subscription/invoices`              | GET    | List all invoices                            |
| `/subscription/invoices/:id/download` | GET    | Invoice download / hosted URL                |
| `/subscription/webhook`               | POST   | Stripe webhook receiver                      |
| `/payment-methods`                    | GET    | List saved payment methods                   |
| `/payment-methods`                    | POST   | Attach new payment method                    |
| `/payment-methods/setup-intent`       | POST   | Create SetupIntent                           |
| `/payment-methods/:id`                | DELETE | Detach payment method                        |
| `/payment-methods/:id/default`        | POST   | Set default payment method                   |

### Settings

| Endpoint                 | Method  | Description                        |
| ------------------------ | ------- | ---------------------------------- |
| `/integrations/settings` | GET/PUT | Webhook URL + API key              |
| `/notification-settings` | GET/PUT | Email/DND notification preferences |
| `/support/contact`       | POST    | Submit support ticket              |

---

## Excel / CSV Workout Import

`POST /workout-plans/import` — upload `.xlsx`, `.xls`, or `.csv`

**Required columns** (case-insensitive): `client_name`, `week_start`, `day`, `exercise`, `sets`, `reps`  
**Optional**: `rest`, `notes`

---

## Cron Jobs

```bash
# Send scheduled push notifications / reminders every minute
* * * * * cd /path/to/backend && php scripts/send-reminders.php >> /dev/null 2>&1
```

---

## Docker

```bash
docker-compose up --build
```

The `docker-compose.yml` starts the PHP server on port 8000. Configure `.env` before running.
