# Tech Stack

## Core

- **PHP 8.2+** / **Laravel 12**
- **MongoDB** via `mongodb/laravel-mongodb` (Eloquent driver) — all models extend `MongoDB\Laravel\Eloquent\Model`
- **JWT Authentication** via `php-open-source-saver/jwt-auth` — middleware alias `jwt.auth`
- **API Documentation** via `knuckleswtf/scribe` (config: `config/scribe.php`, output: `public/docs/`)

## Frontend Assets (minimal, API-only project)

- Vite + Tailwind CSS + Alpine.js (scaffolded but not the focus)

## Testing

- **Pest v4** with `pestphp/pest-plugin-laravel`
- Test database: MongoDB (`laravel_test` on `mongodb://localhost:27017`)
- Config is in `phpunit.xml`

## Common Commands

```bash
# Run all tests (clears config cache first)
composer test

# Run a specific test file
./vendor/bin/pest tests/Feature/JobListFilterTest.php --run

# Start dev server + queue + vite together
composer dev

# Code style (Laravel Pint)
./vendor/bin/pint

# Generate API docs (Scribe)
php artisan scribe:generate

# Clear caches
php artisan config:clear && php artisan cache:clear

# Seed database
php artisan db:seed
```

## Environment

- Copy `.env.example` to `.env` and set `MONGODB_URI`, `JWT_SECRET`, and `CV_ANALYSIS_API_URL`
- `CV_ANALYSIS_API_URL` is the external AI service endpoint used by `CvAnalysisService`
