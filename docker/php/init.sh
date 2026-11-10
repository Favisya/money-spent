#!/bin/sh
set -eu

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console app:seed
php bin/console cache:warmup
php bin/console app:recurring:run
php bin/console app:rates:fetch --days=7 || echo 'Exchange rates are unavailable now, the worker will retry daily.'
