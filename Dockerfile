# ============================================================================
#  Image de l'application « Groupe Helisce » — PHP 8.2 + Apache
#  Prête pour Dokploy / Docker. Contient GD (redimensionnement d'images),
#  PDO MySQL (base de données) et mod_rewrite.
# ============================================================================
FROM php:8.2-apache

# Dépendances système pour GD (images) et l'internationalisation.
# Note : dom, xml et simplexml — requis par le générateur de PDF — sont déjà
# compilés dans l'image PHP officielle. Les réinstaller ferait échouer la
# construction : on ne les ajoute donc pas ici.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libonig-dev unzip default-mysql-client cron gzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mysqli zip mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Réglages PHP adaptés à l'application (uploads jusqu'à 200 Mo)
RUN { \
        echo 'upload_max_filesize = 200M'; \
        echo 'post_max_size = 210M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 120'; \
        echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/zzz-app.ini

# Activer la réécriture d'URL et autoriser les .htaccess
RUN a2enmod rewrite headers
RUN sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copie du code de l'application
WORKDIR /var/www/html
COPY . /var/www/html/

# L'entrée d'amorçage : attend la base, installe/migre, puis lance Apache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/sauvegarde.sh /usr/local/bin/sauvegarde.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/sauvegarde.sh \
    && mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
