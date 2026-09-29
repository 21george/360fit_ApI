# Agent Guide — 360Fit Backend

This file is for AI agents working on the 360Fit PHP backend. It explains the codebase shape, conventions, common pitfalls, and how to verify changes without breaking existing behavior.

---

## 1. Project Basics

- **Language:** PHP 8.2+, `declare(strict_types=1)` in every file.
- **Database:** MongoDB (Atlas in production).
- **No framework.** Custom router, custom request/response helpers.
- **Entry point:** `public/index.php` → `bootstrap/app.php` → `routes/api.php`.
- **Env file:** `.env` (copy from `.env.example`).
- **Tests:** `./vendor/bin/phpunit` (currently 20 passing tests in `tests/Unit/Helpers`).
- **Index setup:** `php -r "require 'vendor/autoload.php'; (new App\Services\MongoIndexes())->create();"`

---

## 2. Directory Layout

```
backend/
├── app/Controllers/        # HTTP request handlers
├── app/Services/           # Business logic / external integrations
├── app/Helpers/            # Router, Request, Response, RLS, Diff, Id, RequestContext
├── app/Middleware/         # Auth/role/subscription/rate-limit middleware
├── config/                 # Database singleton, CORS origins
├── routes/                 # api.php is the active file; others are unused placeholders
├── scripts/                # Cron scripts
├── tests/                  # PHPUnit tests
├── public/                 # Web root
├── bootstrap/app.php       # Application bootstrap
└── composer.json
```

### 2.1 Wearable collections

- `wearable_data` — daily summaries per client (steps, sleep, HR, recovery score, etc.)
- `wearable_tokens` — encrypted OAuth tokens (Fitbit)
- `wearable_connections` — pairing state + per-metric privacy settings
- `wearable_sessions` — device workout sessions imported from Fitbit / Apple Watch
- `wearable_oauth_states` — short-lived Fitbit OAuth CSRF states
- `wearable_trend_cache` / `wearable_sync_jobs` — trend cache-aside + refresh queue

---

## 3. Conventions

### 3.1 Controllers

- Every public controller method signature is:
  ```php
  public function someAction(array $params): void
  ```
- `$params` always contains `_auth` (after middleware) plus route placeholders (`:id`, `:clientId`, etc.).
- Responses must use `\App\Helpers\Response`:
  - `Response::success($data, $message, $code = 200)`
  - `Response::error($message, $code, $details = [])`
  - `Response::json($data, $code = 200)`
  - `Response::paginated($items, $total, $page, $perPage)`
- These helpers call `exit`, so no code runs after them.

### 3.2 Database Access

- Use `\App\Config\Database::collection('name')` for raw MongoDB access.
- Use `\App\Helpers\ScopedCollection::for('name')` when you want RLS/ownership filtering applied automatically.
- `ScopedCollection` relies on `RequestContext` set by middleware. Do **not** use it in code paths that run before middleware (e.g., public auth routes).

### 3.3 ObjectId Handling

- Route IDs are strings; cast with `new \MongoDB\BSON\ObjectId($params['id'])` inside methods.
- Always wrap casts in try/catch for `InvalidArgumentException` on malformed IDs and return 400.
- Stored foreign keys may be strings or `ObjectId`s; normalize with `new ObjectId((string) $value)` before passing to typed parameters in `strict_types` mode.

### 3.4 Authorization

- Middleware sets `_auth` and (for staff) `_org_id` + `_role`.
- `ScopedCollection` handles most ownership filtering for coach/client-owned collections.
- For collections where `RlsScope` returns `null` for the coach role (`workout_logs`, `meal_logs`, `wearable_data`, `predictions`, etc.), the controller **must** manually verify the client belongs to the coach:
  ```php
  $client = Database::collection('clients')->findOne([
      '_id'      => $clientId,
      'coach_id' => $coachId,
  ]);
  if (!$client) Response::error('Client not found', 404);
  ```

### 3.5 Routes

- All active routes are in `routes/api.php`.
- `routes/notifications.php` is required by `api.php` — do not delete it.
- Other route files (`auth.php`, `messages.php`, `media.php`, etc.) are unused placeholders.
- Static routes must be registered before dynamic ones with `:param` placeholders.

### 3.6 Environment Variables

- Access with `$_ENV['VAR_NAME'] ?? 'default'`.
- Never commit real secrets. `.env.example` should contain placeholders only.

---

## 4. Common Pitfalls

1. **`Response::error` and `Response::success` call `exit`.** Don't expect cleanup code after them to run.
2. **`curl_getinfo` must be called before `curl_close`.**
3. **Docblocks must be properly closed.** A malformed docblock can swallow the next method signature and break the route at runtime.
4. **Unrevoked refresh tokens:** each refresh should revoke the consumed token before issuing a new one.
5. **Dead code:** check that new controller methods are wired in `routes/api.php`.
6. **MongoDB indexes:** add new indexes to `app/Services/MongoIndexes.php` and run the index creation script.
7. **Tests:** keep `tests/Unit/Helpers` green. Add tests for helper-level changes.

---

## 5. How to Verify a Change

```bash
# 1. Syntax lint the files you touched
php -l app/Controllers/FooController.php
php -l app/Services/BarService.php

# 2. Run the test suite
./vendor/bin/phpunit

# 3. If possible, run the dev server and hit the endpoint
php -S 0.0.0.0:8000 -t public public/router.php
# In another shell:
curl -i http://localhost:8000/v1/health

# 4. Wearable index setup
php -r "require 'vendor/autoload.php'; (new App\Services\MongoIndexes())->create();"

# 5. Wearable daily sync (dry-run manually; schedule via cron)
php scripts/sync-wearables.php
```

---

## 6. Areas to Treat with Extra Care

- **AuthController** — any change affects login, refresh, logout; refresh-token revocation state lives in `refresh_tokens`.
- **SubscriptionController** — Stripe integration + onboarding flow; `selectPlan` currently has a broken signature in the working tree.
- **RlsScope / ScopedCollection** — changing ownership rules can open IDOR holes.
- **ClientController::permanentDelete** — incomplete cascade; extending it is safe, but removing items is risky.
- **WorkoutPlanController::importDrive** — external URL download; watch for curl lifecycle and file cleanup.
- **StudioController** — `coach_id` strings passed to `ObjectId`-typed helpers can throw.

---

## 7. Useful Commands

```bash
# List all controller public methods
for f in app/Controllers/*.php; do echo "=== $f ==="; grep -oP 'public function \K[a-zA-Z_]+' "$f"; done

# Check route coverage
rg "\[.*Controller::class, '.*'\]" routes/api.php

# Lint all app files
for f in app/Controllers/*.php app/Services/*.php app/Helpers/*.php app/Middleware/*.php routes/*.php; do php -l "$f"; done
```

---

## 8. Known Issues (high-level)

See `README.md` "Known Issues & Fix List" and `PRD.md` Section 4 for the full list. The most urgent are:

1. `SubscriptionController::selectPlan` is missing its method signature.
2. Several coach-view client-data endpoints lack coach-ownership checks.
3. `AnalyticsController::clientAnalytics` is dead code.
4. `.env.example` contains a real-looking `USDA_API_KEY`.

---

## 9. Communication Style

- Keep changes minimal and targeted.
- Preserve existing code style.
- Add/update tests when changing helpers or core behavior.
- Do not modify git history unless explicitly asked.
