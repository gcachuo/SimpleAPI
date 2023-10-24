FROM php:8.0-apache

RUN docker-php-ext-install mysqli
RUN docker-php-ext-enable mysqli

RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-enable pdo pdo_mysql

RUN a2enmod rewrite

# XDebug
RUN yes | pecl install xdebug \
        && echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/xdebug.ini \
      && echo "xdebug.remote_autostart=off" >> /usr/local/etc/php/conf.d/xdebug.ini \
      && echo "xdebug.client_host = 10.254.254.254" >> /usr/local/etc/php/conf.d/xdebug.ini \
      && docker-php-ext-enable xdebug

RUN service apache2 restart
