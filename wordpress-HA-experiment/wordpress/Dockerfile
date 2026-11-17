FROM wordpress:php8.3-apache

# phpredis (object cache + PHP sessions) and unzip (to bake the cache plugin)
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions redis \
 && apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

# wp-cli, used by the wp-init and wp-cron services
ADD --chmod=0755 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar /usr/local/bin/wp

# Code is immutable and identical on every node. The Redis object-cache plugin
# and its drop-in are baked in, so no node depends on writable shared code.
RUN curl -fsSL -o /tmp/redis-cache.zip https://downloads.wordpress.org/plugin/redis-cache.latest-stable.zip \
 && unzip -q /tmp/redis-cache.zip -d /usr/src/wordpress/wp-content/plugins \
 && cp /usr/src/wordpress/wp-content/plugins/redis-cache/includes/object-cache.php /usr/src/wordpress/wp-content/object-cache.php \
 && rm /tmp/redis-cache.zip \
 && chown -R www-data:www-data /usr/src/wordpress/wp-content

COPY wp-config.php /usr/src/wordpress/wp-config.php
COPY healthz.php   /usr/src/wordpress/healthz.php
COPY php-ha.ini    /usr/local/etc/php/conf.d/zz-ha.ini
COPY apache-ha.conf /etc/apache2/conf-available/ha.conf
COPY --chmod=0755 entrypoint.sh /usr/local/bin/ha-entrypoint.sh
COPY --chmod=0755 wp-init.sh    /usr/local/bin/wp-init.sh
COPY --chmod=0755 wp-cron.sh    /usr/local/bin/wp-cron.sh

RUN a2enmod headers && a2enconf ha

ENTRYPOINT ["ha-entrypoint.sh"]
CMD ["apache2-foreground"]
