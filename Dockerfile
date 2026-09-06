# KBForms (web + API) — image de déploiement (Render, Fly, tout hôte Docker).
# La config (base, URL, mail, captcha, secret mobile) vient de variables
# d'environnement — voir tools/DEPLOY_RENDER.md.

FROM php:8.3-apache

# Extensions PHP : pdo_mysql (base) + mbstring (ChoiceList / PushService).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring; \
    a2enmod rewrite headers; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Racine web = app/public, .htaccess actif (routage + en-tête Authorization).
RUN set -eux; \
    sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/app/public!' /etc/apache2/sites-available/000-default.conf; \
    printf '<Directory /var/www/html/app/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/kbforms.conf; \
    echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf; \
    a2enconf kbforms servername

# Réglages PHP raisonnables pour de la collecte avec photos.
RUN { \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=40M'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=60'; \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
    } > /usr/local/etc/php/conf.d/kbforms.ini

COPY . /var/www/html
RUN mkdir -p /var/www/html/app/logs && chown -R www-data:www-data /var/www/html/app/logs

COPY docker/entrypoint.sh /usr/local/bin/kbforms-entrypoint
RUN chmod +x /usr/local/bin/kbforms-entrypoint

# Render fournit $PORT (défaut 10000) ; l'entrypoint fait écouter Apache dessus.
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/kbforms-entrypoint"]
