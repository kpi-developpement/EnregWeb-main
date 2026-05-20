FROM php:8.2-apache

# Extensions PHP
RUN docker-php-ext-install pdo pdo_mysql

# Supervisor pour gérer Apache + indexeur en parallèle
RUN apt-get update && apt-get install -y supervisor && rm -rf /var/lib/apt/lists/*

# Config supervisord
RUN mkdir -p /var/log/supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Fichiers app
COPY . /var/www/html/

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
