# 360Fit — PHP Backend API

RESTful PHP API powering the 360Fit coaching platform. Uses MongoDB Atlas as the database, JWT (Bearer + httpOnly cookies) for authentication, Stripe for subscriptions, LiveKit for video sessions, and AWS S3 / Cloudflare R2 for media storage.

---

## Tech Stack

| Layer    | Technology                                        |
| -------- | ------------------------------------------------- |
| Language | PHP 8.2+                                          |
| Database | MongoDB Atlas                                     |
| Auth     | JWT (Bearer tokens + httpOnly cookies)            |
| Payments | Stripe (subscriptions, invoices, payment methods) |
| Email    | SMTP via PHPMailer                                |
| Media    | AWS S3 / Cloudflare R2 (presigned URLs)           |
| Push     | Firebase Cloud Messaging (FCM v1)                 |
| Video    | LiveKit (coaching sessions + live training)       |
| Router   | Custom lightweight PHP router (no framework)      |

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
│       ├── JwtService.php                    # Access + refresh + setup token generation/validation
│       ├── CodeService.php                   # Client login code generation + bcrypt hashing
│       ├── StripeService.php                 # Full Stripe API wrapper (cURL)
│       ├── EmailService.php                  # Transactional email via SMTP
│       ├── S3Service.php                     # Media upload / presigned URLs (S3 / R2)
│       ├── MediaService.php                  # Photo upload helper
│       ├── FcmService.php                    # Firebase push notifications (FCM v1)
│       ├── LiveKitService.php                # LiveKit room token generation
│       ├── ExcelImportService.php            # Bulk client import from XLSX/CSV
│       ├── WorkoutAnalysisService.php        # AI workout metrics computation
│       ├── NotificationService.php           # In-app notification sender
│       ├── NotificationTriggerService.php    # Domain event → notification triggers
│       ├── PushNotificationService.php       # Expo push notification delivery
│       ├── ScheduledNotificationService.php  # Cron reminder sender
│       └── MongoIndexes.php                  # Index definitions (run setup-indexes.php)
├── bootstrap/app.php             # CORS headers, error/exception handlers, router init
├── config/
│   ├── cors.php                  # Allowed origins list (merged with .env ALLOWED_ORIGINS)
│   └── Database.php              # MongoDB singleton (reads MONGO_URI)
├── public/
│   ├── index.php                 # Entry point (loads .env, bootstrap, router)
│   └── router.php                # PHP built-in dev-server router
├── routes/
│   ├── api.php                   # All route registrations (v1 prefix)
│   └── notifications.php         # Coach notification routes
├── scripts/
│   └── send-reminders.php        # Cron script: send scheduled push reminders
├── setup-indexes.php             # One-time MongoDB index creation script
├── .env.example
└── composer.json
```

---

## Database Schema (MongoDB)

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
  "token_version": "number",
  "active": "boolean",
  "is_blocked": "boolean",
  "deleted_at": "UTCDateTime | null",
  "created_at": "UTCDateTime",
  "updated_at": "UTCDateTime"
}
```

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

| Endpoint               | Method | Auth        | Description                            |
| ---------------------- | ------ | ----------- | -------------------------------------- |
| `/health`              | GET    | —           | Health check + DB ping                 |
| `/auth/coach/register` | POST   | —           | Register coach → returns `setup_token` |
| `/auth/coach/login`    | POST   | —           | Coach login → tokens + coach object    |
| `/auth/client/login`   | POST   | —           | Client login by FIT-XXXX-XX code       |
| `/auth/refresh`        | POST   | Cookie/Body | Refresh access token                   |
| `/auth/logout`         | POST   | Bearer      | Invalidate session, clear cookies      |

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
| `/coach/clients/:id/workout-analyses`                     | GET    | List AI analyses                    |
| `/coach/clients/:id/workout-analyses`                     | POST   | Generate new AI analysis            |
| `/coach/clients/:id/workout-analyses/recommendations`     | GET    | Get recommendations                 |
| `/coach/clients/:id/workout-analyses/:analysisId`         | GET    | View single analysis                |
| `/coach/clients/:id/workout-analyses/:analysisId/approve` | POST   | Approve analysis                    |
| `/coach/clients/:id/workout-analyses/:analysisId/reject`  | POST   | Reject analysis                     |
| `/coach/clients/:id/workout-analyses/:analysisId/assign`  | POST   | Assign to client                    |

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

| Endpoint                                       | Method | Description                      |
| ---------------------------------------------- | ------ | -------------------------------- |
| `/client/profile`                              | GET    | Get own profile                  |
| `/client/profile`                              | PUT    | Update profile fields            |
| `/client/profile/photo`                        | PUT    | Update profile photo URL         |
| `/client/fcm-token`                            | POST   | Register / update FCM push token |
| `/client/workout-plan/current`                 | GET    | Current assigned workout plan    |
| `/client/workout-plan/history`                 | GET    | Past workout plans               |
| `/client/group-workout-plans`                  | GET    | Assigned group workout plans     |
| `/client/workout-logs`                         | POST   | Log completed workout            |
| `/client/workout-logs/:id`                     | PUT    | Update workout log               |
| `/client/workout-logs/:id/media`               | POST   | Attach media to workout log      |
| `/client/nutrition-plan`                       | GET    | Current nutrition plan           |
| `/client/checkins`                             | GET    | Upcoming check-in meetings       |
| `/client/checkins/:id/respond`                 | POST   | Accept / decline check-in        |
| `/client/checkins/:id/reschedule`              | POST   | Request reschedule               |
| `/client/messages`                             | GET    | Message thread with coach        |
| `/client/messages`                             | POST   | Send message to coach            |
| `/client/messages/typing`                      | GET    | Coach typing status              |
| `/client/messages/typing`                      | POST   | Update client typing status      |
| `/client/messages/upload-media`                | POST   | Upload message media             |
| `/client/group-workout-plans/:planId/messages` | GET    | Group chat messages              |
| `/client/group-workout-plans/:planId/messages` | POST   | Send group chat message          |
| `/client/media`                                | GET    | Own media uploads                |
| `/client/media/upload`                         | POST   | Upload media file                |
| `/media/presigned-url`                         | POST   | Get presigned S3 upload URL      |
| `/client/live-training`                        | GET    | Available live training sessions |
| `/client/live-training/:id`                    | GET    | Session details                  |
| `/client/live-training/:id/join`               | POST   | Request to join session          |
| `/client/live-training/:id/participants`       | GET    | Participant list                 |
| `/client/live-training/:id/chat`               | GET    | Live chat messages               |
| `/client/live-training/:id/chat`               | POST   | Send live chat message           |
| `/client/coaching-sessions`                    | GET    | Upcoming 1-on-1 sessions         |
| `/client/coaching-sessions/:id`                | GET    | Session details                  |
| `/client/coaching-sessions/:id/token`          | POST   | Get LiveKit room token (client)  |
| `/client/analytics`                            | GET    | Own progress analytics           |
| `/client/notifications`                        | GET    | List notifications               |
| `/client/notifications/unread-count`           | GET    | Unread count                     |
| `/client/notifications/:id/read`               | POST   | Mark single as read              |
| `/client/notifications/read-all`               | POST   | Mark all as read                 |

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
```

---

## Docker

```bash
docker-compose up --build
```

The `docker-compose.yml` starts the PHP server on port 8000. Configure `.env` before running.
