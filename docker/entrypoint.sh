#!/bin/sh
set -e

# PROCESS_ROLE menentukan apa yang dijalankan container ini:
#   web       - hanya nginx + php-fpm (dipakai saat setup dipecah 4-service)
#   worker    - hanya `php artisan queue:work` (dipakai saat setup dipecah 4-service)
#   scheduler - hanya `php artisan schedule:work` (dipakai saat setup dipecah 4-service)
#   all       - default: web + worker + scheduler dalam satu container lewat
#               supervisord (setup 2-service - lebih hemat kredit Railway)
#
# Pindah dari 2-service ke 4-service TIDAK butuh build image baru - cukup
# tambah service baru di Railway dari repo yang sama, lalu set PROCESS_ROLE
# yang sesuai di masing-masing service.
ROLE="${PROCESS_ROLE:-all}"
export PORT="${PORT:-8080}"

cd /var/www/html

# Migrasi & cache config HANYA dijalankan dari peran yang memuat "web" -
# mencegah dua proses (mis. worker + scheduler start bersamaan) berlomba
# menjalankan migration yang sama.
if [ "$ROLE" = "web" ] || [ "$ROLE" = "all" ]; then
    php artisan config:clear
    php artisan migrate --force
    php artisan storage:link || true
    php artisan optimize
fi

case "$ROLE" in
    worker)
        exec php artisan queue:work --tries=3 --max-time=3600 --sleep=3
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    web)
        envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/sites-enabled/default
        php-fpm -D
        exec nginx -g 'daemon off;'
        ;;
    all)
        envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/sites-enabled/default
        exec supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
        ;;
    *)
        echo "PROCESS_ROLE tidak dikenal: '$ROLE' (harus web/worker/scheduler/all)" >&2
        exit 1
        ;;
esac
