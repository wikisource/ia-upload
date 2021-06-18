FROM php:7.3-apache-stretch

WORKDIR "/var/www/html"

RUN apt-get update \
    && apt-get install --yes \
	djvulibre-bin graphicsmagick \
        libzip-dev \
	libicu-dev \
	unzip \
	wget \
	xdg-utils \
	xz-utils \
      && pecl install xdebug zip \
      && docker-php-ext-enable xdebug zip \
      && docker-php-ext-install pdo_mysql intl \
      && a2enmod rewrite \
      && wget -nv -O- https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

