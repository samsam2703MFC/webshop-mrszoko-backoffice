#!/usr/bin/env bash
# Boutique Mister Szoko en local (dev). L'API et la base sont celles de
# ../backoffice/php-api ; lancez d'abord `php migrate.php --fresh` là-bas.
#   ./serve.sh [port]     (défaut 8091)
set -e
cd "$(dirname "$0")"
PORT="${1:-8091}"
echo "Sklep Mister Szoko → http://localhost:${PORT}/"
exec php -S "localhost:${PORT}" router.php
