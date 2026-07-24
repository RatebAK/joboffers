#!/usr/bin/env bash
# Runs at container startup (after Render injects env vars),
# so APP_URL is the real production domain when Scribe generates docs.
echo "==> Generating Scribe API documentation..."
php /var/www/html/artisan scribe:generate --no-interaction
echo "==> Scribe docs generated."
