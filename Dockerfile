FROM php:7.2-apache
MAINTAINER paul.castle@gmail.com

RUN apt-get update && apt-get install -y git php-imagick nano libmagickwand-dev --no-install-recommends \
	&& rm -rf /var/lib/apt/lists/* \
	&& curl -s https://getcomposer.org/installer | php \
	&& mv composer.phar /usr/local/bin/composer.phar

# Install ImageMagick and add module to PHP
RUN printf "\n" | pecl install imagick-beta \
	&& touch /usr/local/etc/php/php.ini \
	&& echo "extension=imagick.so" >> /usr/local/etc/php/php.ini

WORKDIR /var/www/html
ADD . /var/www/html

RUN php /usr/local/bin/composer.phar install
RUN chown -R www-data:www-data /var/www
ENV APACHE_RUN_USER www-data
ENV APACHE_RUN_GROUP www-data
ENV APACHE_LOG_DIR /var/log/apache2

EXPOSE 80

CMD ["apachectl", "-e info -DFOREGROUND"]