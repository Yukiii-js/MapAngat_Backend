ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite
RUN sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
ENV PORT=10000

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf

COPY docker-entrypoint.sh /usr/local/bin/mapangat-entrypoint
RUN chmod +x /usr/local/bin/mapangat-entrypoint

EXPOSE 10000
CMD ["mapangat-entrypoint"]