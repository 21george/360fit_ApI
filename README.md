# Coaching Platform — PHP Backend

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env with your MongoDB URI, JWT secrets, S3 credentials, FCM key

# 3. Create MongoDB indexes (run once)
php -r "require 'vendor/autoload.php'; \$d=Dotenv\Dotenv::createImmutable('.'); \$d->load(); (new App\Services\MongoIndexes())->create();"

# 4. Start dev server
php -S 0.0.0.0:8000 -t public public/router.php
```

## Project Structure

```
backend/
├── app/
│   ├── Config/          # Database config
│   ├── Controllers/     # Request handlers
│   ├── Helpers/         # Router, Request, Response
│   ├── Middleware/      # Auth, Coach, Client guards
│   └── Services/        # JWT, Code, S3, FCM, Indexes
├── bootstrap/           # App bootstrap (CORS, error handling)
├── config/              # DB config
├── public/              # Entry point (index.php)
├── routes/              # api.php route definitions
├── .env.example
└── composer.json
```

## API Base URL

`http://localhost:8000/v1`

## Authentication

- Coach: `POST /auth/coach/login` → Bearer JWT
- Client: `POST /auth/client/login` → Bearer JWT (one-time code)
- Refresh: `POST /auth/refresh`

## Excel Import Format

Upload `.xlsx`, `.xls`, or `.csv` to `POST /workout-plans/import`

Required columns (case-insensitive): `client_name`, `week_start`, `day`, `exercise`, `sets`, `reps`
Optional: `rest`, `notes`
# 360fit_ApI
