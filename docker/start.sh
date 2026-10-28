#!/bin/sh
# Démarrage sur Render : Apache écoute le port imposé par Render ($PORT), puis caches Laravel.
# Aucune migration ici : la base Supabase est déjà à jour (migrations lancées depuis le poste).
set -e
PORT="${PORT:-10000}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec apache2-foreground
