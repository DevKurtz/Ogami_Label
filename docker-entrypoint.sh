#!/bin/sh
set -eu

php /app/deploy/migrate.php
exec "$@"
