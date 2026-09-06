#!/bin/sh
# Fait écouter Apache sur le port fourni par l'hébergeur ($PORT, défaut 10000),
# puis lance Apache au premier plan.
set -e

PORT="${PORT:-10000}"

sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
