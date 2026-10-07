# Product Requirements Document — 360Fit Backend

> This PRD captures the *current* product scope, feature set, and the bugs / gaps discovered during a full codebase scan. It is **not** a wish-list; it reflects what is implemented today and what must be fixed before the implementation is production-ready.

---

## 1. Product Overview

360Fit is a multi-tenant coaching platform backend written in PHP (no framework). It supports independent coaches, multi-trainer organizations (staff RBAC), and clients. The API provides:

- Coach and client authentication via JWT + httpOnly cookies.
- Stripe-based subscription lifecycle (free / pro / business).
- Client management, workout/nutrition plans, check-ins, messaging.
- Live training and 1-on-1 coaching sessions via LiveKit.
- Media uploads via S3/R2 presigned URLs.
- Push notifications via FCM v1.
- Food logging and USDA food search.
- Wearable data + Fitbit OAuth.
- Progress predictions.
- Studio operations: classes, bookings, memberships, check-ins, consent.
- Rehabilitation tracking: PAR-Q, rehab tracks, PROMs, pain logs, SOAP notes.

---

## 2. Current Architecture

| Layer | Purpose |
|-------|---------|
| `public/index.php` | Loads `.env`, bootstrap, routes. |
| `bootstrap/app.php` | CORS, security headers, exception handling, route dispatch. |
| `routes/api.php` | All v1 route definitions. |
| `routes/notifications.php` | Coach notification routes (loaded by `api.php`). |
| `app/Controllers/*.php` | HTTP request handlers. |
| `app/Services/*.php` | Business logic / external API wrappers. Includes `ActivityLogService` (staff action tracking), `WearableSyncService` (Fitbit OAuth), `WearableTrendCacheService` (trend caching), `ProfilePhotoService` (S3/local uploads). |
| `app/Helpers/*.php` | Router, Request, Response, RLS, RequestContext, Diff, Id. |
| `app/Middleware/*.php` | Auth, role, subscription, rate-limit, setup-token middleware. `StaffMiddleware` resolves legacy coach + staff JWTs into unified org context. |
| `config/Database.php` | MongoDB singleton. |

---

## 3. Functional Modules

### 3.1 Authentication

- Coach registration → email verification → plan selection.
- Coach login, client login by FIT code, staff login.
- Refresh token rotation with revocation persistence in `refresh_tokens`.
- Logout clears cookies and revokes the provided refresh token.

### 3.2 Subscriptions

- Tiers: `free` (3 clients), `pro` (50 clients), `business` (effectively unlimited).
- Stripe Checkout for upgrades, billing portal, cancellation, invoices, payment methods.
- Trial setup / confirm flow.

### 3.3 Client Management

- CRUD clients, bulk import, login-code regeneration, block/unblock.
- Profile photos via S3 or local storage.
- Soft delete + partial hard-delete cascade.
- Body measurements.

### 3.4 Workout Domain

- Workout plans: individual and group/team, status workflow, assignments.
- Excel / CSV / Google Drive import.
- Workout logs with unique index on `(client_id, workout_plan_id, day)`.
- AI workout analyses with approve/reject/assign.

### 3.5 Nutrition Domain

- Nutrition plans for clients.
- Meal logging (mark/unmark completed).
- Adherence reports.
- Food search (local custom foods + USDA FoodData Central cache).

### 3.6 Messaging

- Coach/client 1-on-1 threads.
- Group chat for group workout plans.
- Typing indicators.
- Media upload support.

### 3.7 Live Video

- Live training sessions with join requests.
- 1-on-1 coaching sessions.
- LiveKit token generation for both.

### 3.8 Notifications

- In-app notifications for coach and client.
- Scheduled push reminders via cron.
- FCM v1 push delivery.

### 3.9 Staff / Organization

- **Legacy coach JWT** is automatically treated as `owner` of their own organization (`org_id` = `coach._id`).
- **Staff JWT** is resolved via `StaffMiddleware` into `org_id`, `_role`, and `_staff` document.
- Staff roles: `owner` (implicit), `admin`, `manager`, `front_desk`, `instructor_coach`.
- **Role permissions:**
  - `instructor_coach` — full access to clients, workout plans, nutrition, check-ins, messages, live training, coaching sessions, analytics, predictions. No billing/subscription access.
  - `admin` — same as instructor plus team management (invite/deactivate staff, view all staff activity). No billing ownership transfer.
  - `manager` — staff scheduling and client roster oversight.
  - `front_desk` — check-ins, bookings, and membership status only.
- **Activity logging:** `ActivityLogService::log()` records staff actions to `staff_activities` and updates `clients.last_staff_id`, `last_staff_name`, `last_staff_activity`, `last_staff_activity_at` so admins can see which instructor a client last worked with.
- Staff invite flow: generate JWT invite token → send email with link (defaults to backend-hosted `APP_URL/staff/accept-invite.html`, overridable via `STAFF_INVITE_URL`) → staff accepts via `POST /org/staff/accept-invite` → sets password → receives access/refresh tokens and is redirected to the dashboard.
- Client reassignment on deactivation: `reassign_to` can be `"owner"` or another staff ID.

### 3.10 Studio Operations

- Class schedules, capacity, recurring rule field.
- Class bookings with waitlist promotion on cancellation.
- Memberships with status tracking.
- Front-desk / self check-ins gated on signed waiver.
- Utilization reports.

### 3.11 Rehab / Physiotherapy

- PAR-Q+ submission.
- Rehab track start (requires `rehab_consent`).
- PROMs, pain logs, SOAP notes.
- Clinical role gating (`owner`, `admin`, `instructor_coach`).

### 3.12 Wearables

- Push daily summaries.
- Fitbit OAuth connect/callback/disconnect.
- Encrypted token storage.
- Trend caching for coaches.
- Apple Watch / HealthKit daily push endpoint (server-side normalization into the same daily summary schema).
- Per-metric sharing controls (`sharing_steps`, `sharing_sleep`, etc.) that gate what a coach can view.
- Device workout-session ingestion for sessions imported from wearables.

### 3.13 Predictions

- Heuristic 60-day weight/body-fat prediction based on adherence and wearable data.
- Persists one prediction per client per month for accuracy tracking.

### 3.14 Exercise Library

- WGER (wger.de) open-source exercise database integration.
- `ExerciseLibraryService::syncExercises()` fetches categories, muscles, equipment, and exercises in English, upserting into local `exercises` collection.
- `ExerciseLibraryService::syncExerciseImages()` fetches image URLs per exercise.
- `ExerciseController::index` supports text search, category filter, and muscle filter.
- `WorkoutPlanController::format()` enriches plan days with exercise names, images, descriptions, equipment, and muscles from the library.

### 3.15 Personal Record (PR) Tracking

- `PrRecordController` stores PRs using the Epley 1RM formula: `weight * (1 + reps/30)`.
- Client endpoints: list all PRs, history per exercise, record new PR.
- Coach endpoints: view client PRs and per-exercise history.
- MongoDB aggregation pipeline deduplicates to the best PR per exercise.

### 3.16 Progress Photos

- `ProgressPhotoController` supports front, side, and back photo types with custom labels.
- Client endpoints: upload, list, delete own photos.
- Coach endpoints: list client photos by type/label.
- Photos stored via S3/R2 presigned URLs.

### 3.17 Food Diary

- `FoodDiaryController` lets clients log individual food entries with macros.
- Coach can view client's food diary.
- Entries include calories, protein, carbs, fat, quantity, and unit.

### 3.18 Habits

- `HabitController` manages habit assignments (coach) and daily logging (client).
- Habits support frequency (daily/weekly) and streak tracking.
- Client can log/unlog habit completion.

### 3.19 Goals

- `GoalController` supports numeric goals with target values, deadlines, and milestones.
- Auto-calculates progress percentage.
- Client sees goals in FitApp with 50% and 100% milestone celebrations.

### 3.20 Session Packages

- `SessionPackageController` lets coaches sell prepaid session bundles.
- Clients can view available packages and remaining sessions.
- Usage tracking via `POST /session-packages/:id/use`.

### 3.21 Trainer Review Workflow

- `WorkoutLogController::pendingReview()` lists workouts without `review_status` or with `review_status: 'pending'`.
- `WorkoutLogController::reviewLog()` lets coaches approve or request a redo with feedback.
- `WorkoutLogController::clientReview()` exposes review status and feedback to the client.
- MongoDB index `{client_id: 1, review_status: 1}` added for query performance.

---

## 4. Recently Closed / Implemented Changes

### 4.0 Login-code revocation

| # | Change | Details |
|---|--------|---------|
| 0.1 | `ClientController::regenerateCode` now bumps `clients.token_version` and deletes every `refresh_tokens` row for that client. | Old login codes and refresh tokens stop working immediately; FitApp clients are forced to log in again with the new code. |
| 0.2 | `AuthMiddleware` enforces `token_version` for client access tokens. | Previously only the refresh flow checked the version, so a stolen or cached access token could remain valid until expiry. The middleware now rejects stale access tokens with `401 Session invalidated`. |
| 0.3 | **Staff RBAC fully implemented.** `StaffMiddleware` resolves legacy coach JWTs as `owner` and staff JWTs into `org_id` + role. All coach-facing routes (clients, plans, nutrition, check-ins, messages, live training, coaching sessions, analytics, predictions) switched from `CoachMiddleware` to `StaffMiddleware`. Controllers updated to use `$params['_org_id'] ?? $params['_auth']['sub']` for client lookups so staff members can access the same organization data. | Instructors (`instructor_coach`) can now manage clients, create plans, and run sessions under the organization. Admin (`admin`) can invite staff, change roles, and view team activity. Billing remains owner-only. |
| 0.4 | **Activity logging backend.** `ActivityLogService::log()` writes staff actions to `staff_activities` and updates `clients.last_staff_id` / `last_staff_name` / `last_staff_activity` / `last_staff_activity_at`. Frontend client list and detail sidebar display assigned instructor and last activity. | Admin dashboard shows recent staff activity and which instructor each client last worked with. |
| 0.5 | **Frontend staff login + accept-invite pages.** New `/staff/login` and `/staff/accept-invite` pages. Auth store extended with `staff` state, `setStaff`, and `clearStaff`. API client `AUTH_PATHS` exempt list updated so public invite/login endpoints are not hijacked by the 401 interceptor. | Staff can log in with email/password and accept invite links. Hydration errors fixed via `Suspense` boundary around `useSearchParams`. |
| 0.6 | **Analytics + Prediction routes switched to `StaffMiddleware`.** `AnalyticsController::clientAnalytics` now accepts `staff` role and uses `_org_id` for client lookup. `PredictionController::show` uses `_org_id` for coach-level ownership check. | Instructors can view client analytics and predictions. Dead-code `AnalyticsController::clientAnalytics` is now live behind `GET /coach/clients/:id/analytics`. |
| 0.7 | **Staff Dashboard Client Count Card.** When `isStaff === true`, the dashboard shows a "Your Clients" card displaying the number of clients where `primary_staff_id === staff.id` and where `last_staff_id === staff.id`, plus a quick link to `/clients`. | Staff instructors can see their workload at a glance. |
| 0.8 | **Sessions Card — Full Week View.** `UpcomingSessions` component changed from filtering future-only sessions to showing **all sessions for the current calendar week** (Monday 00:00 → Sunday 23:59). Card title updated to "This Week's Sessions". | Staff and coaches see the complete weekly schedule, including past sessions. |
| 0.9 | **Team Profile Edit Form.** Inline edit form added to `/settings/team/[id]` sidebar so owners/admins can update staff member's name and role. Backend `PUT /org/staff/:id` endpoint added to `StaffController`, restricted to `owner` and `admin` roles. Frontend `useUpdateStaff` mutation added. | Admins can update staff details without navigating away. |
| 0.10 | **Staff Profile Photo Upload.** New endpoints `POST /staff/profile/photo` and `DELETE /staff/profile/photo` in `StaffController`, registered with `StaffMiddleware`. Frontend edit profile page updated to use staff endpoints when `isStaff` is true, and settings page displays staff photos. | Staff can upload and manage their own profile photos. |
| 0.11 | **Admin Team Activity Center.** Added "Activity Log" tab to `/settings/team` with category filter pills (All, Schedule, Workout Plans, 1-on-1 Sessions, Nutrition Plans). Displays staff activities with photos, action labels, linked client names, and timestamps. Uses existing `useStaffActivities(page)` pagination. | Admins can browse and filter all team activities by category. |
| 0.12 | **Client List — Primary Coach Name Display.** Frontend `clients/page.tsx` now imports `useStaffList` and resolves `primary_staff_id` to actual staff names. "Assigned To" column shows primary coach name + "Primary Coach" label when available, falling back to `last_staff_name`. | Admins and staff see who is primarily responsible for each client. |
| 0.13 | **Staff Photos in Header & Sidebar.** `DashboardHeader` and `Sidebar` components updated to use `staff?.profile_photo` instead of defaulting to initials. Staff avatar now visible site-wide. | Consistent staff identity across the application. |
| 0.14 | **Staff Can Add/Import Clients.** "Add Client" and "Import" buttons added to the clients list header. Backend routes `/coach/clients` and `/coach/clients/import` already use `StaffMiddleware`, so instructors can create and bulk-import clients. | Instructors can onboard new clients directly. |
| 0.15 | **Do Not Disturb (DND) Push Suppression.** `NotificationTriggerService::sendPushToCoach` now checks `coach.notification_settings.dnd_enabled` and suppresses push notifications during DND hours (supports overnight windows like 22:00 → 08:00). In-app notifications are still stored. | Coaches are not disturbed during off-hours while still receiving in-app notifications. |

## 5. Open Bugs & Required Fixes

### 5.1 Critical

| # | Issue | Impact | Required Fix |
|---|-------|--------|--------------|
| 1 | `SubscriptionController::selectPlan` method signature is missing (docblock not closed). | `POST /subscription/select-plan` throws a fatal error; new coach onboarding is broken. | Restore `public function selectPlan(array $params): void` and close the docblock. |

### 5.2 Authorization / IDOR

| # | Issue | Impact | Required Fix |
|---|-------|--------|--------------|
| 2 | `MealLogController::clientLogs` and `adherence`, `WearableController::clientData` and `trend` do not verify the requested client belongs to the authenticated coach. `PredictionController::show` **fixed** — now verifies ownership via `$params['_org_id']`. | Any authenticated coach can read any client's meal logs, adherence, and wearable data by changing the `:id` parameter. | Add `clients.findOne(['_id' => $clientId, 'coach_id' => $coachId])` ownership check and return 404 if missing. |

### 5.3 Broken / Unreachable

| # | Issue | Impact | Required Fix |
|---|-------|--------|--------------|
| 3 | ~~`AnalyticsController::clientAnalytics` has no route.~~ **Fixed.** Route `GET /coach/clients/:id/analytics` now maps to `AnalyticsController::clientAnalytics` behind `StaffMiddleware`; method accepts `staff` role and uses `$params['_org_id']` for client lookup. | — | — |
| 4 | `WorkoutPlanController::importDrive` reads `CURLINFO_CONTENT_TYPE` after `curl_close`. | Content type is always empty; extension detection falls back to `xlsx` and large-file detection may behave unexpectedly. | Move `curl_getinfo` calls before `curl_close`. |
| 5 | `WorkoutAnalysisController::assign` uses undefined `$plan` in the inline-plan branch. | PHP undefined-variable notice/error when notifying the client. | Use the created plan document or `$planData['title']` when calling `notifyClientNewPlan`. |
| 6 | `StudioController::signConsentAsClient` and `checkinAsClient` pass `$client['coach_id']` directly to functions expecting `ObjectId`. | `TypeError` if `coach_id` is stored as a string. | Cast with `new ObjectId((string) $client['coach_id'])`. |
| 6a | `WorkoutPlanController::enrichDays` expects native `array` but receives `MongoDB\Model\BSONArray`. | `TypeError` on `GET /workout-plans/:id` when plan has `days`. | Cast BSONArray to array with `(array)` before passing to `enrichDays()`. **Fixed.** |

### 5.4 Data Integrity / Performance

| # | Issue | Impact | Required Fix |
|---|-------|--------|--------------|
| 7 | ~~`ClientController::permanentDelete` cascade omits many client-related collections.~~ **Fixed.** Hard-delete now cascades through `pr_records`, `progress_photos`, `workout_plans`, `workout_logs`, `body_measurements`, `media_uploads`, `messages`, `checkin_meetings`, `coaching_sessions`, `notifications`. | Orphaned records remain after hard delete. | Extend the cascade list to cover all collections linked to the client. |
| 8 | `clients` index set does not include `code_lookup`. | Client login scans the full collection. | Add unique index on `code_lookup`. |
| 9 | `AuthController::refresh` stores a new refresh token but never revokes the consumed one. | Multiple concurrent refresh tokens remain valid. | Revoke old token immediately after validation. |

### 5.5 Security Fixes (Applied)

| # | Issue | Impact | Fix |
|---|-------|--------|-----|
| 7a | `AuthController` debug logging leaked password hash prefixes in error logs. | Credential exposure in server logs. | Removed all `error_log("[STAFF LOGIN]...")` lines from `AuthController`. **Fixed.** |
| 7b | `CoachController::updateBranding` accepted arbitrary URLs for `logo_url` and `favicon_url`. | Open redirect / XSS risk via malicious branding URLs. | Added `FILTER_VALIDATE_URL` + HTTPS enforcement. **Fixed.** |
| 7c | `MediaController::storeClientMeasurement` had no bounds validation. | Invalid data (e.g., 9999 kg weight) could be stored. | Added bounds: weight 0.1–300 kg, body fat 0–70%, circumferences 0–250 cm. **Fixed.** |

### 5.6 Documentation / Environment

| # | Issue | Impact | Required Fix |
|---|-------|--------|--------------|
| 10 | README/PRD email documentation drift: README listed SMTP via PHPMailer + `EMAIL_HOST` etc., but `EmailService` used PHP `mail()` and PHPMailer was not a composer dependency. | Misleading setup; emails silently fail because `mail()` is usually unconfigured on production hosts. | **Done** — PHPMailer added to `composer.json`; `EmailService` uses SMTP when `EMAIL_HOST` is set and falls back to PHP `mail()` otherwise; `.env.example` documents `EMAIL_HOST`, `EMAIL_PORT`, `EMAIL_USERNAME`, `EMAIL_PASSWORD`, `EMAIL_ENCRYPTION`, `EMAIL_SMTP_AUTH`; `AuthController::coachRegister` now returns 503 if the verification email cannot be sent. README updated. |
| 11 | `.env.example` contains a non-placeholder `USDA_API_KEY`. | Potential secret leakage. | Blank out or rotate the key. |

### 5.7 Functional Gaps

| # | Issue | Impact | Required Fix |
|---|-------|--------|--------------|
| 12 | Client can request a reschedule, but coach has no endpoint to approve/decline. | Reschedule requests are stuck in `reschedule_requested` status. | Add coach endpoint to act on reschedule requests. |
| 13 | `MediaController::uploadFile` reads `type` from `$_GET`/`$_POST` only, ignoring JSON body. | Media type may be wrong for JSON requests. | Read `type` from `Request::body()`. |
| 14 | `NotificationTriggerService::sendPushToCoach` requires `coach['fcm_token']` which coaches cannot set. | Coach push notifications never fire. | Add coach FCM-token endpoint or remove coach push path. |

---

## 6. Non-Functional Requirements

- PHP 8.2+ with `ext-mongodb`, `ext-openssl`, `ext-curl`.
- MongoDB Atlas or self-hosted MongoDB 5+.
- Optional Redis for hot caching (falls back to MongoDB).
- Stripe, LiveKit, AWS S3/R2, FCM v1, Fitbit OAuth configured via `.env`.
- PHPUnit tests for `Diff`, `RlsScope`, `JwtServiceInvite`, and `ActivityLogService` pass (28 tests, 45 assertions).

---

## 7. Change Log

- 2026-08-31 — PRD created from full backend scan; issues and required fixes documented.
- 2026-09-02 — Login-code revocation implemented (`regenerateCode` bumps `token_version` + clears refresh tokens; `AuthMiddleware` enforces version for client access tokens). Wearables section expanded to include Apple Watch push, per-metric sharing, and device workout sessions.
- 2026-09-12 — Email stack fixed: PHPMailer added to `composer.json`; `EmailService` now sends via SMTP when `EMAIL_HOST` is configured and falls back to PHP `mail()` otherwise; `.env.example` documents all SMTP variables; `AuthController::coachRegister` returns 503 when the verification email cannot be delivered; README updated.
- 2026-09-15 — Staff RBAC fully implemented: `StaffMiddleware`, staff invite/accept-invite/login flow, role-based access control (`instructor_coach`, `admin`, `manager`, `front_desk`), activity logging (`ActivityLogService`), `TeamOverview` dashboard widget, client list/detail staff assignment display. All coach-facing routes switched to `StaffMiddleware`. Controllers updated to use `$params['_org_id']` for staff JWT compatibility. Analytics and prediction routes now accessible to instructors. Frontend hydration errors fixed (`Suspense` boundary around `useSearchParams`, `ClientDate` SSR-safe components). PHPUnit tests expanded to 28 (added `ActivityLogServiceTest`, `JwtServiceInviteTest`).
- 2026-10-01 — Staff dashboard enhancements: client count card, weekly sessions view, team profile editing, staff profile photo upload, admin activity center with category filtering, primary coach name resolution on client list, DND push notification suppression. Staff can now add/import clients directly. Staff photos display site-wide in header and sidebar.
- 2026-10-02 — **Client Experience A+ Features:** Personal Record (PR) tracking with Epley 1RM formula (`PrRecordController`). Client progress photo upload with front/side/back categorization (`ProgressPhotoController` client endpoints). Client body measurement input (`MediaController::storeClientMeasurement`). Weekly calendar view in FitApp with color-coded workouts/check-ins/rest days. Trainer review workflow: `pendingReview`, `reviewLog`, `clientReview` in `WorkoutLogController`. Group class discovery with day-filtered browsing in FitApp. Habit tracking UI with streak counters. Goal setting with milestone celebrations (50% / 100%). Offline exercise video caching via `expo-file-system`. Frontend-G updated with 4 new client detail tabs (PRs, Photos, Habits, Goals) and Pending Reviews dashboard widget. Security fixes: removed debug logging from `AuthController`, added measurement bounds validation, HTTPS-only branding URLs, timezone-aware DND.
- 2026-10-02 — **Documentation & Bug Fixes:** Updated README.md and PRD.md to reflect all new controllers (`ExerciseController`, `ProgressPhotoController`, `FoodDiaryController`, `HabitController`, `GoalController`, `SessionPackageController`, `PrRecordController`), services (`ExerciseLibraryService`), and routes. Marked security fixes and resolved issues in PRD. Fixed `WorkoutPlanController::enrichDays` `BSONArray` type error. Fixed FitApp TypeScript errors: `expo-file-system` legacy API import, `router.push` object-form for params, `InviteRow` missing `styles` prop, `biometricSecurity` → `biometricsSecurityLevel`. All backend PHP syntax checks pass; Frontend-G and FitApp `tsc --noEmit` compile cleanly.
