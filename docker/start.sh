#!/bin/sh
set -eu

if [ "${1:-}" = 'apache2-foreground' ]; then
    if [ "${APP_ENV:-production}" = 'production' ] && [ "${APP_DEBUG:-false}" != 'false' ] && [ "${APP_DEBUG:-false}" != '0' ]; then
        echo 'APP_DEBUG must be false in production' >&2
        exit 1
    fi
    if [ -z "${APP_KEY:-}" ]; then
        echo 'APP_KEY must be provided at runtime' >&2
        exit 1
    fi

    PORT="${PORT:-10000}"
    case "$PORT" in
        ''|*[!0-9]*) echo 'PORT must be a number between 1 and 65535' >&2; exit 1 ;;
    esac
    if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
        echo 'PORT must be a number between 1 and 65535' >&2
        exit 1
    fi

    printf 'Listen 0.0.0.0:%s\n' "$PORT" > /etc/apache2/ports.conf
    sed "s/@PORT@/$PORT/g" /etc/apache2/sites-available/000-default.conf.template \
        > /etc/apache2/sites-available/000-default.conf
fi

exec "$@"
