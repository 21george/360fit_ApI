# 360Fit — PHP Backend API

RESTful PHP API powering the 360Fit coaching platform. Uses MongoDB Atlas as the database, JWT (Bearer + httpOnly cookies) for authentication, Stripe for subscriptions, LiveKit for video sessions, and AWS S3 / Cloudflare R2 for media storage.

---

## Tech Stack

| Layer      | Technology                                                                |
| ---------- | ------------------------------------------------------------------------- |
| Language   | PHP 8.2+                                                                  |
| Database   | MongoDB Atlas                                                             |
| Auth       | JWT (Bearer tokens + httpOnly cookies)                                    |
| Payments   | Stripe (subscriptions, invoices, payment methods)                         |
| Email      | SMTP via PHPMailer (falls back to PHP `mail()` if SMTP is not configured) |
| Media      | AWS S3 / Cloudflare R2 (presigned URLs)                                   |
| Push       | Firebase Cloud Messaging (FCM v1)                                         |
| Video      | LiveKit (coaching sessions + live training)                               |
| Router     | Custom lightweight PHP router (no framework)                              |
| Staff RBAC | `owner`, `admin`, `manager`, `front_desk`, `instructor_coach`             |
| Studio     | Class schedules, bookings, memberships, check-ins, consent docs           |

**Staff role permissions**

- `owner` (legacy coach JWT) — full access to everything including billing.
- `admin` — full access to clients, plans, analytics, team management; can view all staff activity.
- `instructor_coach` — full access to clients, workout plans, nutrition, check-ins, messages, live training, coaching sessions, analytics, and predictions. **No billing/subscription access.**
- `manager` — staff scheduling and client roster oversight.
- `front_desk` — check-ins, bookings, and membership status only.

---

## Quick Start

```bash
# 1. Install PHP dependencies
composer install

# 2. Copy and configure environment
cp .env.example .env
# Fill in: MONGO_URI, JWT_SECRET, JWT_REFRESH_SECRET, STRIPE_SECRET_KEY, etc.

# 3. Create MongoDB indexes (run once after first setup)
php -r "require 'vendor/autoload.php'; (new App\Services\MongoIndexes())->create();"

# 4. Start development server
php -S 0.0.0.0:8000 -t public public/router.php
```

The API will be available at `http://localhost:8000/v1`.

Health check: `GET http://localhost:8000/v1/health`

---

## Staff / Organization RBAC

Invite links are sent from `POST /org/staff/invite`. By default the link points to the frontend at `STAFF_INVITE_URL` (e.g. `http://localhost:3000/staff/accept-invite`). If `STAFF_INVITE_URL` is not set, it falls back to the backend-hosted page at `APP_URL/staff/accept-invite.html`, which redirects to the frontend.

`StaffMiddleware` resolves both legacy coach JWTs (role=`coach`) and staff JWTs (role=`staff`) into a unified organization context:

- **Legacy coach** → treated as `owner`; `org_id` = coach `_id`.
- **Staff JWT** → looked up in `staff` collection; `org_id` = `staff.org_id`; `_role` = `staff.role`.

Controllers read `$params['_org_id']` (falling back to `$params['_auth']['sub']`) for all client-scoped queries. This lets existing solo-coach accounts work unchanged while staff members are scoped to the same organization.

`ActivityLogService::log()` records staff actions (create client, update client, etc.) to `staff_activities` and updates `clients.last_staff_id` / `last_staff_name` / `last_staff_activity` so admins can see which instructor each client last worked with.

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
| `STAFF_INVITE_URL`   | Optional override for the staff invite acceptance page URL  |

### Stripe (required for subscription features)

| Variable                                                | Description                                        |
| ------------------------------------------------------- | -------------------------------------------------- |
| `STRIPE_SECRET_KEY`                                     | Stripe secret key (`sk_test_...` or `sk_live_...`) |
| `STRIPE_WEBHOOK_SECRET`                                 | Stripe webhook signing secret (`whsec_...`)        |
| `STRIPE_PRICE_PRO`                                      | Stripe Price ID for Pro monthly plan               |
| `STRIPE_PRICE_BUSINESS`                                 | Stripe Price ID for Business monthly plan          |
| `STRIPE_PRICE_*_{MONTHLY,QUARTERLY,SEMI_ANNUAL,ANNUAL}` | Period-specific price IDs (override generics)      |

### Optional

| Variable                   | Description                                                            |
| -------------------------- | ---------------------------------------------------------------------- |
| `MAIL_FROM`                | From address for outgoing email                                        |
| `MAIL_FROM_NAME`           | From name for outgoing email                                           |
| `MAIL_DOMAIN`              | Default mail domain                                                    |
| `EMAIL_HOST`               | SMTP host (e.g. `smtp.sendgrid.net`) — leave blank to use PHP `mail()` |
| `EMAIL_PORT`               | SMTP port (default: `587`)                                             |
| `EMAIL_USERNAME`           | SMTP username                                                          |
| `EMAIL_PASSWORD`           | SMTP password                                                          |
| `EMAIL_ENCRYPTION`         | `tls`, `ssl`, or none (default: `tls`)                                 |
| `EMAIL_SMTP_AUTH`          | `true` or `false` (default: `true`)                                    |
| `FCM_SERVICE_ACCOUNT_PATH` | Path to Firebase service account JSON                                  |
| `FCM_PROJECT_ID`           | Firebase project ID                                                    |
| `AWS_ACCESS_KEY_ID`        | S3/R2 access key                                                       |
| `AWS_SECRET_ACCESS_KEY`    | S3/R2 secret key                                                       |
| `AWS_S3_BUCKET`            | Bucket name                                                            |
| `AWS_REGION`               | S3/R2 region (default `eu-central-1`)                                  |
| `AWS_ENDPOINT`             | Custom endpoint (for Cloudflare R2)                                    |
| `USDA_API_KEY`             | FoodData Central API key (blank in committed example)                  |
| `NUTRITION_*`              | Nutrition cache driver / TTL / async settings                          |
| `WEARABLE_*`               | Wearable trend cache TTL / async settings                              |
| `REDIS_URL`                | Optional hot-cache Redis URL                                           |
| `FITBIT_CLIENT_ID` etc.    | Fitbit OAuth credentials                                               |

---

## Project Structure

```
backend/
├── app/
│   ├── Controllers/
│   │   ├── AuthController.php           # Login, register, refresh, logout (coach/client/staff)
│   │   ├── ClientController.php         # Coach: manage clients + client profile endpoints
│   │   ├── CoachController.php          # Coach profile, photo, password
│   │   ├── WorkoutPlanController.php    # Workout plans + Excel/CSV/Google Drive import
│   │   ├── WorkoutLogController.php     # Client workout logging + coach progress views
│   │   ├── WorkoutAnalysisController.php # AI workout analyses + recommendations/assign
│   │   ├── NutritionController.php      # Nutrition plans
│   │   ├── MealLogController.php      # Client meal completion + coach adherence
│   │   ├── CheckinController.php        # Coach/client check-in meetings
│   │   ├── MessageController.php        # Coach/client messaging + typing
│   │   ├── GroupChatController.php      # Group workout plan chat
│   │   ├── MediaController.php          # Presigned uploads + local upload + coach/client media
│   │   ├── AnalyticsController.php      # Coach + client analytics (aggregate + self)
│   │   ├── LiveTrainingController.php   # Live training sessions (coach + client)
│   │   ├── CoachingSessionController.php # 1-on-1 coaching sessions + LiveKit tokens
│   │   ├── SubscriptionController.php   # Stripe subscriptions, invoices, payment methods
│   │   ├── SettingsController.php       # Integrations, notification prefs, support tickets
│   │   ├── NotificationsController.php  # In-app notifications (coach + client)
│   │   ├── FoodController.php           # Food search (local + USDA), custom foods
│   │   ├── WearableController.php     # Wearable push/Fitbit + coach trend data
│   │   ├── PredictionController.php     # 2-month weight/body-fat prediction
│   │   ├── StaffController.php          # Org staff invites + role management
│   │   ├── AssessmentController.php     # Sports-science assessments
│   │   ├── StudioController.php         # Consent, check-ins, utilization reports
│   │   ├── StudioClassController.php    # Class schedules
│   │   ├── StudioBookingController.php  # Class bookings + waitlist
│   │   ├── StudioMembershipController.php # Memberships
│   │   └── RehabController.php          # PAR-Q, rehab tracks, PROMs, pain logs, SOAP notes
│   ├── Helpers/
│   │   ├── Router.php            # Lightweight HTTP router
│   │   ├── Request.php           # Body parsing, validation
│   │   ├── Response.php          # JSON response helpers
│   │   ├── ScopedCollection.php  # RLS-aware collection wrapper
│   │   ├── RlsScope.php          # Role-based ownership filter builder
│   │   ├── RequestContext.php    # Per-request auth context
│   │   ├── Diff.php              # Change detection
│   │   └── Id.php                # Safe ObjectId helpers
│   ├── Middleware/
│   │   ├── AuthMiddleware.php          # Validates Bearer JWT (coach or client)
│   │   ├── CoachMiddleware.php         # Requires coach role
│   │   ├── ClientMiddleware.php        # Requires client role
│   │   ├── SetupAuthMiddleware.php     # Setup token (post-registration)
│   │   ├── SubscriptionMiddleware.php  # Enforces client cap by tier
│   │   ├── AuthRateLimitMiddleware.php # Rate-limited auth routes
│   │   ├── RateLimitMiddleware.php     # Generic rate limiter
│   │   └── StaffMiddleware.php         # Resolves staff/coach JWT into org context
│   └── Services/
│       ├── JwtService.php                    # Access + refresh + setup + staff-invite tokens
│       ├── CodeService.php                   # FIT code generation + bcrypt + SHA-256 lookup
│       ├── StripeService.php                 # Full Stripe API wrapper (cURL)
│       ├── EmailService.php                  # Transactional email via PHP mail()
│       ├── S3Service.php                     # Media upload / presigned URLs (S3 / R2)
│       ├── MediaService.php                  # Photo upload helper (legacy/redundant)
│       ├── ProfilePhotoService.php           # S3/local profile photo upload/delete
│       ├── FcmService.php                    # Firebase push notifications (FCM v1)
│       ├── LiveKitService.php                # LiveKit room token generation
│       ├── ExcelImportService.php            # Bulk client / plan import from XLSX/CSV
│       ├── WorkoutAnalysisService.php        # AI workout metrics computation
│       ├── FoodService.php                   # USDA FoodData Central wrapper + local cache
│       ├── NutritionCacheService.php         # Cache-aside wrapper over FoodService
│       ├── WearableTrendCacheService.php     # Cache-aside wrapper over wearable data
│       ├── WearableSyncService.php           # Fitbit OAuth + daily sync runner
│       ├── CacheAsideTrait.php               # Redis hot-cache helpers
│       ├── AssessmentScoringService.php      # FMS / 1RM / VO2max / body comp scoring
│       ├── ActivityLogService.php            # Staff action logging + client last_staff_id tracking
│       ├── ConsentSigningService.php         # SHA-256 consent signature
│       ├── NotificationService.php           # Legacy FCM wrapper (unused)
│       ├── NotificationTriggerService.php    # Domain event → notifications + push
│       ├── PushNotificationService.php       # Legacy Expo/FCM (unused)
│       ├── ScheduledNotificationService.php    # Cron reminder runner
│       └── MongoIndexes.php                  # Index definitions (run once after setup)
├── bootstrap/app.php             # CORS headers, error/exception handlers, router init
├── config/
│   ├── cors.php                  # Allowed origins list (merged with .env ALLOWED_ORIGINS)
│   └── Database.php              # MongoDB singleton (reads MONGO_URI)
├── public/
│   ├── index.php                 # Entry point (loads .env, bootstrap, router)
│   └── router.php                # PHP built-in dev-server router
├── routes/
│   ├── api.php                   # All route registrations (v1 prefix)
│   ├── notifications.php         # Coach notification routes (required by api.php)
│   └── auth.php, messages.php, media.php, analytics.php, checkins.php, coaches.php # unused placeholders
├── scripts/
│   ├── send-reminders.php        # Cron: scheduled push notification reminders
│   ├── nutrition-cache-refresh.php # Cron: nutrition cache refresh queue
│   └── wearable-trend-cache-refresh.php # Cron: wearable trend cache refresh queue
├── .env.example
└── composer.json
```

---

## Database Schema (MongoDB)

### `coaches` subscription snapshot

Stripe remains the payment authority. Webhooks persist this non-sensitive
snapshot on the owning coach document so authenticated billing reads remain
available when Stripe is temporarily unavailable:

```json
{
  "stripe_customer_id": "string | null",
  "stripe_subscription_id": "string | null",
  "subscription_tier": "free | pro | business",
  "subscription_status": "pending | trialing | active | past_due | cancelled",
  "subscription_period": "monthly | quarterly | semi_annual | annual",
  "subscription_price_cents": "number | null",
  "subscription_currency": "string | null",
  "trial_starts_at": "UTCDateTime | null",
  "trial_ends_at": "UTCDateTime | null",
  "subscription_started_at": "UTCDateTime | null",
  "subscription_period_end": "UTCDateTime | null",
  "default_payment_method": "Stripe payment method ID | null",
  "cancel_at_period_end": "boolean",
  "last_stripe_sync_at": "UTCDateTime | null",
  "last_stripe_sync_status": "success | error | null",
  "failed_payment_attempts": "number | null",
  "failed_payment_last_attempt_at": "UTCDateTime | null"
}
```

The application never stores card numbers, CVVs, or Stripe secret data. After
deploying subscription changes, create the supporting indexes:

```bash
php -r "define('BASE_PATH', getcwd()); require 'vendor/autoload.php'; Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad(); require BASE_PATH . '/config/Database.php'; (new App\Services\MongoIndexes())->create();"
```

### `clients` collection

```json
{
  "_id": "ObjectId",
  "coach_id": "ObjectId",
  "name": "string",
  "email": "string | null",
  "phone": "string | null",
  "gender": "male | female | other | null",
  "date_of_birth": "string (ISO date) | null",
  "language": "en | de",
  "address": "string | null",
  "city": "string | null",
  "postal_code": "string | null",
  "nationality": "string | null",
  "occupation": "string | null",
  "notes": "string | null",
  "sickness": "string | null",
  "current_weight_kg": "number | null",
  "height_cm": "number | null",
  "chest_cm": "number | null",
  "waist_cm": "number | null",
  "body_fat_pct": "number | null",
  "bmi": "number | null",
  "lean_mass_kg": "number | null",
  "profile_photo_url": "string | null",
  "profile_photo_key": "string | null",
  "login_code_hash": "string",
  "code_lookup": "string (SHA-256)",
  "token_version": "number",
  "active": "boolean",
  "is_blocked": "boolean",
  "is_blocked": "boolean",
  "deleted_at": "UTCDateTime | null",
  "created_at": "UTCDateTime",
  "updated_at": "UTCDateTime",
  "primary_staff_id": "ObjectId | null",
  "last_staff_id": "ObjectId | null",
  "last_staff_name": "string | null",
  "last_staff_role": "string | null",
  "last_staff_activity": "string | null",
  "last_staff_activity_at": "UTCDateTime | null"
}
```

### Client session lifecycle

Every client token embeds `token_version` from the `clients` document. Any action that revokes access (coach regenerates the login code, blocks the client, or permanently deletes the client) bumps `token_version` and deletes the client's refresh tokens. The access-token middleware rejects stale versions immediately, so a changed login code automatically signs the client out of FitApp.

---

## API Reference

### Base URL

```
http://localhost:8000/v1
```

All protected endpoints require `Authorization: Bearer <access_token>`.  
Tokens are also set as httpOnly cookies (`access_token`, `refresh_token`).

---

### Auth

| Endpoint                    | Method | Auth        | Description                            |
| --------------------------- | ------ | ----------- | -------------------------------------- |
| `/health`                   | GET    | —           | Health check + DB ping                 |
| `/auth/coach/register`      | POST   | —           | Register coach → returns `setup_token` |
| `/auth/coach/login`         | POST   | —           | Coach login → tokens + coach object    |
| `/auth/client/login`        | POST   | —           | Client login by FIT-XXXX-XX code       |
| `/auth/staff/login`         | POST   | —           | Staff login                            |
| `/auth/verify-email`        | POST   | —           | Verify coach email (returns tokens)    |
| `/auth/resend-verification` | POST   | —           | Resend verification code               |
| `/auth/refresh`             | POST   | Cookie/Body | Refresh access token                   |
| `/auth/logout`              | POST   | Bearer      | Invalidate session, clear cookies      |

---

### Coach — Profile

| Endpoint                  | Method | Description                    |
| ------------------------- | ------ | ------------------------------ |
| `/coach/profile`          | GET    | Get own coach profile          |
| `/coach/profile`          | PUT    | Update name, email, language … |
| `/coach/profile/photo`    | POST   | Upload / replace profile photo |
| `/coach/profile/photo`    | DELETE | Remove profile photo           |
| `/coach/profile/password` | PUT    | Change password                |
| `/coach/profile`          | DELETE | Delete own account             |
| `/coaches/registered`     | GET    | List all registered coaches    |

---

### Coach — Clients

| Endpoint                                                  | Method | Description                         |
| --------------------------------------------------------- | ------ | ----------------------------------- |
| `/coach/clients`                                          | GET    | List clients (search, sort, page)   |
| `/coach/clients`                                          | POST   | Create client (returns login code)  |
| `/coach/clients/import`                                   | POST   | Bulk import from JSON array         |
| `/coach/clients/:id`                                      | GET    | Get single client                   |
| `/coach/clients/:id`                                      | PUT    | Update client fields                |
| `/coach/clients/:id`                                      | DELETE | Soft-delete (sets `active=false`)   |
| `/coach/clients/:id/permanent`                            | DELETE | Hard-delete (cascades all data)     |
| `/coach/clients/:id/photo`                                | POST   | Upload client profile photo         |
| `/coach/clients/:id/regenerate-code`                      | POST   | Regenerate FIT login code           |
| `/coach/clients/:id/block`                                | POST   | Block client, invalidate all tokens |
| `/coach/clients/:id/unblock`                              | POST   | Restore client access               |
| `/coach/clients/:id/analytics`                            | GET    | Completion rate, weight progress …  |
| `/coach/clients/:id/logs`                                 | GET    | All workout logs                    |
| `/coach/clients/:id/live-progress`                        | GET    | Live workout progress               |
| `/coach/clients/:id/workout-progress`                     | GET    | Historical workout progress         |
| `/coach/clients/:id/measurements`                         | POST   | Store body measurement              |
| `/coach/clients/:id/meal-logs`                            | GET    | Client meal logs                    |
| `/coach/clients/:id/adherence`                            | GET    | Combined workout + meal adherence   |
| `/coach/clients/:id/wearable`                             | GET    | Wearable daily summaries            |
| `/coach/clients/:id/wearable/trend`                       | GET    | Wearable trend aggregate            |
| `/coach/clients/:id/prediction`                           | GET    | 2-month weight/body-fat prediction  |
| `/coach/clients/:id/workout-analyses`                     | GET    | List AI analyses                    |
| `/coach/clients/:id/workout-analyses`                     | POST   | Generate new AI analysis            |
| `/coach/clients/:id/workout-analyses/recommendations`     | GET    | Get recommendations                 |
| `/coach/clients/:id/workout-analyses/:analysisId`         | GET    | View single analysis                |
| `/coach/clients/:id/workout-analyses/:analysisId/approve` | POST   | Approve analysis                    |
| `/coach/clients/:id/workout-analyses/:analysisId/reject`  | POST   | Reject analysis                     |
| `/coach/clients/:id/workout-analyses/:analysisId/assign`  | POST   | Assign to client                    |

---

### Wearables

Wearable data lives in `wearable_data` (daily summaries) and `wearable_sessions` (device workouts). Coach views are filtered by the client's per-metric sharing flags in `wearable_connections` (`sharing_steps`, `sharing_sleep`, `sharing_heart_rate`, `sharing_workouts`).

| Client endpoint                                 | Method | Description                                  |
| ----------------------------------------------- | ------ | -------------------------------------------- |
| `/client/wearable/status`                       | GET    | Provider, last sync, sync status             |
| `/client/wearable/fitbit/connect`               | GET    | Redirect URL to Fitbit OAuth                 |
| `/client/wearable/fitbit/callback?code=&state=` | GET    | Exchange code for tokens (stateless backend) |
| `/client/wearable/fitbit/sync`                  | POST   | Pull recent Fitbit data                      |
| `/client/wearable/apple-watch/push`             | POST   | Push normalized Apple HealthKit summary      |
| `/client/wearable/sharing`                      | GET    | Read privacy settings                        |
| `/client/wearable/sharing`                      | PUT    | Update privacy settings                      |
| `/client/wearable/disconnect`                   | DELETE | Remove connection + tokens                   |

| Coach endpoint                         | Method | Description                                         |
| -------------------------------------- | ------ | --------------------------------------------------- |
| `/coach/clients/:id/wearable`          | GET    | Daily summaries (respects sharing flags)            |
| `/coach/clients/:id/wearable/trend`    | GET    | 7/30/90-day aggregates                              |
| `/coach/clients/:id/wearable/sessions` | GET    | Device workout sessions                             |
| `/coach/clients/:id/prediction`        | GET    | 2-month weight/body-fat prediction (wearable-aware) |

Required `.env` variables: `FITBIT_CLIENT_ID`, `FITBIT_CLIENT_SECRET`, `FITBIT_REDIRECT_URI`, `APP_URL`.

Fitbit flow:

1. Client app calls `GET /client/wearable/fitbit/connect` to get the Fitbit authorization URL.
2. User authorizes Fitbit; Fitbit redirects to `FITBIT_REDIRECT_URI` (should be the backend or an app deep-link that forwards `code` + `state` to the backend).
3. Client app calls `GET /client/wearable/fitbit/callback?code=...&state=...`. The backend exchanges the code, stores encrypted tokens in `wearable_tokens`, and creates/updates `wearable_connections`.
4. Subsequent data pulls happen via cron (`scripts/sync-wearables.php`) or `POST /client/wearable/fitbit/sync`.

---

### Coach — Workout Plans

| Endpoint                      | Method | Description                               |
| ----------------------------- | ------ | ----------------------------------------- |
| `/workout-plans`              | GET    | List all plans (filter by client, status) |
| `/workout-plans/saved`        | GET    | Library of saved (unassigned) plans       |
| `/workout-plans`              | POST   | Create workout plan                       |
| `/workout-plans/import`       | POST   | Import from XLSX/XLS/CSV file             |
| `/workout-plans/import-drive` | POST   | Import from Google Drive URL              |
| `/workout-plans/:id`          | GET    | Get single plan                           |
| `/workout-plans/:id`          | PUT    | Update plan                               |
| `/workout-plans/:id`          | DELETE | Delete plan                               |
| `/workout-plans/:id/assign`   | POST   | Assign plan to one or more clients        |

**Excel/CSV import required columns** (case-insensitive): `client_name`, `week_start`, `day`, `exercise`, `sets`, `reps`. Optional: `rest`, `notes`.

---

### Coach — Nutrition Plans

| Endpoint                      | Method | Description           |
| ----------------------------- | ------ | --------------------- |
| `/nutrition-plans`            | GET    | List nutrition plans  |
| `/nutrition-plans`            | POST   | Create nutrition plan |
| `/nutrition-plans/:id`        | GET    | Get single plan       |
| `/nutrition-plans/:id`        | PUT    | Update plan           |
| `/nutrition-plans/:id`        | DELETE | Delete plan           |
| `/nutrition-plans/:id/assign` | POST   | Assign to clients     |

---

### Coach — Check-ins

| Endpoint        | Method | Description                       |
| --------------- | ------ | --------------------------------- |
| `/checkins`     | GET    | List check-ins (filter by client) |
| `/checkins`     | POST   | Schedule check-in meeting         |
| `/checkins/:id` | PUT    | Update meeting details            |
| `/checkins/:id` | DELETE | Cancel / delete meeting           |

---

### Coach — Messages

| Endpoint                     | Method | Description                        |
| ---------------------------- | ------ | ---------------------------------- |
| `/messages/unread`           | GET    | All unread message threads         |
| `/messages/unread-count`     | GET    | Total unread message count         |
| `/messages/read-all`         | POST   | Mark all messages as read          |
| `/messages/:clientId`        | GET    | Message thread with client         |
| `/messages/:clientId/typing` | GET    | Client typing status               |
| `/messages/:clientId/typing` | POST   | Update coach typing status         |
| `/messages`                  | POST   | Send message to client             |
| `/messages/upload-media`     | POST   | Upload message media (returns URL) |

---

### Coach — Live Training

| Endpoint                          | Method | Description                    |
| --------------------------------- | ------ | ------------------------------ |
| `/live-training`                  | GET    | List live training sessions    |
| `/live-training`                  | POST   | Create session                 |
| `/live-training/:id`              | GET    | Get session details            |
| `/live-training/:id`              | PUT    | Update session                 |
| `/live-training/:id`              | DELETE | Delete session                 |
| `/live-training/:id/go-live`      | POST   | Start / go live                |
| `/live-training/:id/end`          | POST   | End session                    |
| `/live-training/:id/requests`     | GET    | List join requests             |
| `/live-training/:id/requests`     | POST   | Approve / deny join request    |
| `/live-training/:id/participants` | GET    | Current participant list       |
| `/live-training/:id/chat`         | GET    | Live session chat              |
| `/live-training/:id/chat`         | POST   | Send live session chat message |

---

### Coach — Coaching Sessions (1-on-1)

| Endpoint                        | Method | Description                              |
| ------------------------------- | ------ | ---------------------------------------- |
| `/coaching-sessions`            | GET    | List sessions (filter by client, status) |
| `/coaching-sessions`            | POST   | Create session                           |
| `/coaching-sessions/:id`        | GET    | Get session details                      |
| `/coaching-sessions/:id`        | PUT    | Update session                           |
| `/coaching-sessions/:id`        | DELETE | Delete session                           |
| `/coaching-sessions/:id/status` | POST   | Update session status                    |
| `/coaching-sessions/:id/token`  | POST   | Get LiveKit room token (coach)           |
| `/coaching-sessions/:id/notes`  | POST   | Save session notes                       |

---

### Coach — Analytics

| Endpoint              | Method | Description                     |
| --------------------- | ------ | ------------------------------- |
| `/analytics/coach`    | GET    | Aggregate coach-level analytics |
| `/analytics/sessions` | GET    | Coaching session analytics      |

---

### Coach — Notifications

| Endpoint                      | Method | Description               |
| ----------------------------- | ------ | ------------------------- |
| `/notifications`              | GET    | List coach notifications  |
| `/notifications/unread-count` | GET    | Unread notification count |
| `/notifications/:id/read`     | POST   | Mark single as read       |
| `/notifications/read-all`     | POST   | Mark all as read          |
| `/notifications/:id`          | DELETE | Delete notification       |

---

### Coach — Settings

| Endpoint                 | Method | Description                     |
| ------------------------ | ------ | ------------------------------- |
| `/integrations/settings` | GET    | Get webhook URL + API key       |
| `/integrations/settings` | PUT    | Update webhook URL + API key    |
| `/notification-settings` | GET    | Get notification preferences    |
| `/notification-settings` | PUT    | Update notification preferences |
| `/support/contact`       | POST   | Submit support ticket           |

---

### Subscriptions & Billing

| Endpoint                              | Method | Auth  | Description                         |
| ------------------------------------- | ------ | ----- | ----------------------------------- |
| `/subscription/select-plan`           | POST   | Setup | Select plan after registration      |
| `/subscription`                       | GET    | Coach | Current subscription + tier         |
| `/subscription/checkout`              | POST   | Coach | Create Stripe checkout session      |
| `/subscription/upgrade`               | POST   | Coach | Upgrade / change plan immediately   |
| `/subscription/portal`                | POST   | Coach | Open Stripe billing portal          |
| `/subscription/cancel`                | POST   | Coach | Cancel at period end                |
| `/subscription/invoices`              | GET    | Coach | List all invoices                   |
| `/subscription/invoices/:id/download` | GET    | Coach | Download / hosted invoice URL       |
| `/subscription/webhook`               | POST   | —     | Stripe webhook (signature verified) |
| `/payment-methods`                    | GET    | Coach | List saved payment methods          |
| `/payment-methods`                    | POST   | Coach | Attach new payment method           |
| `/payment-methods/setup-intent`       | POST   | Coach | Create Stripe SetupIntent           |
| `/payment-methods/:id`                | DELETE | Coach | Detach payment method               |
| `/payment-methods/:id/default`        | POST   | Coach | Set as default payment method       |

---

### Coach — Media

| Endpoint                 | Method | Description                       |
| ------------------------ | ------ | --------------------------------- |
| `/coach/media/:clientId` | GET    | List all media uploads for client |

---

### Client Routes

| Endpoint                                       | Method | Description                        |
| ---------------------------------------------- | ------ | ---------------------------------- |
| `/client/profile`                              | GET    | Get own profile                    |
| `/client/profile`                              | PUT    | Update profile fields              |
| `/client/profile/photo`                        | PUT    | Update profile photo URL           |
| `/client/fcm-token`                            | POST   | Register / update FCM push token   |
| `/client/workout-plan/current`                 | GET    | Current assigned workout plan      |
| `/client/workout-plan/history`                 | GET    | Past workout plans                 |
| `/client/group-workout-plans`                  | GET    | Assigned group workout plans       |
| `/client/workout-logs`                         | POST   | Log completed workout              |
| `/client/workout-logs/:id`                     | PUT    | Update workout log                 |
| `/client/workout-logs/:id/media`               | POST   | Attach media to workout log        |
| `/client/nutrition-plan`                       | GET    | Current nutrition plan             |
| `/client/checkins`                             | GET    | Upcoming check-in meetings         |
| `/client/checkins/:id/respond`                 | POST   | Accept / decline check-in          |
| `/client/checkins/:id/reschedule`              | POST   | Request reschedule                 |
| `/client/messages`                             | GET    | Message thread with coach          |
| `/client/messages`                             | POST   | Send message to coach              |
| `/client/messages/typing`                      | GET    | Coach typing status                |
| `/client/messages/typing`                      | POST   | Update client typing status        |
| `/client/messages/upload-media`                | POST   | Upload message media               |
| `/client/group-workout-plans/:planId/messages` | GET    | Group chat messages                |
| `/client/group-workout-plans/:planId/messages` | POST   | Send group chat message            |
| `/client/media`                                | GET    | Own media uploads                  |
| `/client/media/upload`                         | POST   | Upload media file                  |
| `/media/presigned-url`                         | POST   | Get presigned S3 upload URL        |
| `/foods/search`                                | GET    | Search foods (local + USDA)        |
| `/foods`                                       | GET    | List custom foods                  |
| `/foods`                                       | POST   | Create custom food                 |
| `/foods/fdc/:fdcId`                            | GET    | USDA food details                  |
| `/foods/calculate`                             | GET    | Calculate nutrients for quantity   |
| `/client/meal-logs`                            | POST   | Mark meal completed                |
| `/client/meal-logs`                            | DELETE | Unmark meal completed              |
| `/client/wearable/data`                        | POST   | Push wearable data (legacy/device) |
| `/client/wearable/status`                      | GET    | Wearable connection status         |
| `/client/wearable/disconnect`                  | DELETE | Disconnect wearable provider       |
| `/client/wearable/fitbit/connect`              | GET    | Start Fitbit OAuth                 |
| `/client/wearable/fitbit/callback`             | GET    | Fitbit OAuth callback              |
| `/client/wearable/fitbit/sync`                 | POST   | Trigger Fitbit sync manually       |
| `/client/wearable/apple-watch/push`            | POST   | Push Apple Watch / HealthKit data  |
| `/client/wearable/sharing`                     | GET    | Get per-metric sharing settings    |
| `/client/wearable/sharing`                     | PUT    | Update per-metric sharing settings |
| `/client/prediction`                           | GET    | Own 2-month prediction             |
| `/client/live-training`                        | GET    | Available live training sessions   |
| `/client/live-training/:id`                    | GET    | Session details                    |
| `/client/live-training/:id/join`               | POST   | Request to join session            |
| `/client/live-training/:id/participants`       | GET    | Participant list                   |
| `/client/live-training/:id/chat`               | GET    | Live chat messages                 |
| `/client/live-training/:id/chat`               | POST   | Send live chat message             |
| `/client/coaching-sessions`                    | GET    | Upcoming 1-on-1 sessions           |
| `/client/coaching-sessions/:id`                | GET    | Session details                    |
| `/client/coaching-sessions/:id/token`          | POST   | Get LiveKit room token (client)    |
| `/client/analytics`                            | GET    | Own progress analytics             |
| `/client/notifications`                        | GET    | List notifications                 |
| `/client/notifications/unread-count`           | GET    | Unread count                       |
| `/client/notifications/:id/read`               | POST   | Mark single as read                |
| `/client/notifications/read-all`               | POST   | Mark all as read                   |

---

## Staff / Organization Routes

| Endpoint                              | Method | Auth   | Description                              |
| ------------------------------------- | ------ | ------ | ---------------------------------------- |
| `/org/staff/invite`                   | POST   | Staff  | Invite staff member to organization      |
| `/org/staff/accept-invite`            | POST   | —      | Accept staff invite using token          |
| `/org/staff`                          | GET    | Staff  | List organization staff                  |
| `/org/staff/activities`               | GET    | Staff  | Recent staff activity (owner/admin only) |
| `/org/staff/:id/role`                 | PATCH  | Staff  | Update staff role                        |
| `/org/staff/:id/deactivate`           | POST   | Staff  | Deactivate staff + reassign clients      |
| `/org/clients/:id/assignment`         | PATCH  | Staff  | Assign client to staff member            |
| `/org/clients/:id/assessments`        | POST   | Staff  | Record assessment                        |
| `/org/clients/:id/assessments`        | GET    | Staff  | List assessments                         |
| `/org/clients/:id/assessments/latest` | GET    | Staff  | Latest assessment per type               |
| `/org/clients/:id/checkin`            | POST   | Staff  | Front-desk check-in                      |
| `/org/classes`                        | POST   | Staff  | Schedule class                           |
| `/org/classes`                        | GET    | Staff  | List classes                             |
| `/org/classes/:id/book`               | POST   | Staff  | Book class for client                    |
| `/org/bookings/:id/cancel`            | POST   | Staff  | Cancel booking                           |
| `/org/memberships`                    | POST   | Staff  | Create membership                        |
| `/org/memberships`                    | GET    | Staff  | List memberships                         |
| `/org/memberships/:id/status`         | POST   | Staff  | Update membership status                 |
| `/org/reports/utilization`            | GET    | Staff  | Studio utilization report                |
| `/client/classes`                     | GET    | Client | Available classes                        |
| `/client/classes/:id/book`            | POST   | Client | Book class                               |
| `/client/bookings/:id/cancel`         | POST   | Client | Cancel own booking                       |
| `/client/consent-documents`           | POST   | Client | Sign consent document                    |
| `/client/checkin`                     | POST   | Client | Self check-in (requires waiver)          |
| `/client/parq`                        | POST   | Client | Submit PAR-Q+ screening                  |
| `/org/clients/:id/parq`               | GET    | Staff  | View latest PAR-Q+                       |
| `/org/clients/:id/rehab-track/start`  | POST   | Staff  | Start rehab track                        |
| `/org/clients/:id/rehab-track`        | GET    | Staff  | View rehab track                         |
| `/org/rehab-tracks/:id/proms`         | POST   | Staff  | Record PROM                              |
| `/org/rehab-tracks/:id/pain-logs`     | POST   | Staff  | Log pain (staff)                         |
| `/client/rehab-tracks/:id/pain-logs`  | POST   | Client | Log pain (client)                        |
| `/org/rehab-tracks/:id/soap-notes`    | POST   | Staff  | Add SOAP note                            |
| `/org/rehab-tracks/:id/soap-notes`    | GET    | Staff  | List SOAP notes                          |

---

## Subscription Tiers

| Tier       | Client Limit |
| ---------- | ------------ |
| `free`     | 3            |
| `pro`      | 50           |
| `business` | Unlimited    |

---

## Cron Jobs

```bash
# Send scheduled push notification reminders every minute
* * * * * cd /path/to/backend && php scripts/send-reminders.php >> /dev/null 2>&1

# Refresh nutrition cache every 15 minutes
*/15 * * * * cd /path/to/backend && php scripts/nutrition-cache-refresh.php >> /dev/null 2>&1

# Refresh wearable trend cache every 5 minutes
*/5 * * * * cd /path/to/backend && php scripts/wearable-trend-cache-refresh.php >> /dev/null 2>&1

# Pull yesterday's wearable data for all connected clients once per day
0 2 * * * cd /path/to/backend && php scripts/sync-wearables.php >> /dev/null 2>&1
```

---

## Known Issues & Fix List

This section documents bugs, gaps, and inconsistencies found during the codebase scan. No code was changed to produce this list.

### Critical

1. **`SubscriptionController::selectPlan` is broken in the current working tree.**
   - The method signature disappeared; the docblock is not closed and the method body starts directly after the comment.
   - `method_exists('App\Controllers\SubscriptionController', 'selectPlan')` returns `false`, so the route `POST /subscription/select-plan` will fail with a 500.
   - **Fix:** Restore the signature `public function selectPlan(array $params): void` and close the docblock.

### Authorization / IDOR gaps

2. **Coach endpoints for client-owned data do not verify coach ownership.**
   - `MealLogController::clientLogs` (`GET /coach/clients/:id/meal-logs`)
   - `MealLogController::adherence` (`GET /coach/clients/:id/adherence`)
   - `WearableController::clientData` (`GET /coach/clients/:id/wearable`)
   - `WearableController::trend` (`GET /coach/clients/:id/wearable/trend`)
   - **Status:** `PredictionController::show` now verifies ownership. Remaining items still need owner checks.

### Broken / unreachable code

3. ~~`AnalyticsController::clientAnalytics` is dead code.~~ **Fixed.**
   - Route is now wired at `GET /coach/clients/:id/analytics` behind `StaffMiddleware`; method accepts both `coach` and `staff` roles and uses `$params['_org_id']` for client lookup. Method remains available alongside `ClientController::analytics` (which also delegates to workout data).

4. **Google Drive import reads content type after `curl_close`.**
   - `WorkoutPlanController::importDrive` calls `curl_getinfo($ch, CURLINFO_CONTENT_TYPE)` after `curl_close($ch)`, so `$contentType` will be empty and extension detection falls back to `xlsx`.
   - **Fix:** Move `curl_getinfo` calls before `curl_close`.

5. **`WorkoutAnalysisController::assign` references an undefined `$plan` when creating an inline plan.**
   - The inline-plan branch inserts a document and sets `$planId`, but never defines `$plan`; the subsequent notification uses `$plan['title']`, causing an undefined-variable warning/error.
   - **Fix:** Use the created plan document or `$planData['title']` in the notification.

6. **`StudioController` passes `coach_id` strings to typed `ObjectId` parameters.**
   - `signConsentAsClient` and `checkinAsClient` pass `$client['coach_id']` (a string or ObjectId) to `signConsent(ObjectId $orgId, ...)` / `checkin(ObjectId $orgId, ...)`.
   - In `declare(strict_types=1)` mode this throws a `TypeError` if `coach_id` is stored as a string.
   - **Fix:** Wrap the value in `new ObjectId((string) $client['coach_id'])`.

### Data integrity / correctness

7. **`ClientController::permanentDelete` cascade is incomplete.**
   - Hard-delete only removes `workout_plans`, `workout_logs`, `body_measurements`, `media_uploads`, `messages`, `checkin_meetings`, `coaching_sessions`, `notifications`.
   - It does not delete `meal_logs`, `group_messages`, `predictions`, `wearable_data`, `wearable_tokens`, `parq_screenings`, `rehab_tracks`, `proms`, `pain_logs`, `soap_notes`, `consent_documents`, `checkins`, `class_bookings`, `assessments`, `client_workout_analyses`, `live_training_requests`, `live_training_chat`, `scheduled_notifications`.
   - **Fix:** Extend the cascade list to cover all client-related collections.

8. **MongoDB index missing for `clients.code_lookup`.**
   - `AuthController::clientLogin` queries by `code_lookup`; `MongoIndexes` only indexes `login_code_hash`.
   - Without an index, client login performs a full collection scan.
   - **Fix:** Add `['key' => ['code_lookup' => 1], 'unique => true]` to the `clients` indexes.

9. **`AuthController::refresh` mints a new refresh token without revoking the old one.**
   - Each refresh adds a new row to `refresh_tokens`, leaving the previous token valid until its TTL expires.
   - **Fix:** Revoke the consumed token immediately after validation and before issuing the new one.

### Environment / documentation drift

10. **README email stack is incorrect.**
    - README previously said "SMTP via PHPMailer" and listed `EMAIL_HOST`, etc., while `EmailService` actually used PHP `mail()`.
    - **Fix applied:** PHPMailer is now a composer dependency, `EmailService` uses SMTP when `EMAIL_HOST` is set and falls back to PHP `mail()` otherwise, `.env.example` documents all SMTP variables, and `AuthController::coachRegister` now returns `503` if the verification email cannot be sent.

11. **`.env.example` contains a committed API key.**
    - `USDA_API_KEY=pciIu7D9jN00k0ZTfAe3vPbhCjaek6Zmy0hrS061` looks like a real key.
    - **Fix:** Blank out the example value or rotate the key.

### UX / minor

12. **Client reschedule request has no coach approval endpoint.**
    - `CheckinController::reschedule` sets `client_response` to `reschedule_requested`, but there is no staff/coach endpoint to approve or decline the request.
    - **Fix:** Add coach endpoint to handle `reschedule_requested` check-ins.

13. **`MediaController::uploadFile` reads `type` from `$_GET` / `$_POST` instead of JSON body.**
    - The endpoint expects JSON but only looks at query or form-data for the media type.
    - **Fix:** Read `type` from `Request::body()`.

14. **NotificationTriggerService cannot push to coaches.**
    - `sendPushToCoach` looks for `coach['fcm_token']`, but coaches have no endpoint to register a token.
    - **Fix:** Add coach FCM token endpoint or remove coach push logic.

15. **Unused route files and services.**
    - `routes/auth.php`, `routes/messages.php`, `routes/media.php`, `routes/analytics.php`, `routes/checkins.php`, `routes/coaches.php` are placeholders and not loaded.
    - `app/Services/NotificationService.php` and `app/Services/PushNotificationService.php` are unused; controllers use `NotificationTriggerService` + `FcmService`.
    - **Fix:** Delete or deprecate these files to reduce confusion.

---

## Docker

```bash
docker-compose up --build
```

The `docker-compose.yml` starts the PHP server on port 8000. Configure `.env` before running.
# 360fitbackend
# 360fitbackend
