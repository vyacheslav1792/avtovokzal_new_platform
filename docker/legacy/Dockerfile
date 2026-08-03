# Legacy-окружение для сверки: PHP 5.6 + Apache (как на боевом сервере, 5.6.33).
# db_work переведён на НАТИВНЫЙ Firebird (pdo_firebird/interbase) вместо ODBC — см. config-override.
FROM php:5.6-apache

# Debian jessie снят с поддержки -> ставим пакеты из archive.debian.org
RUN printf 'deb [trusted=yes] http://archive.debian.org/debian jessie main\n' > /etc/apt/sources.list \
    && (apt-get -o Acquire::Check-Valid-Until=false update || true) \
    && apt-get install -y --allow-unauthenticated --no-install-recommends \
        firebird-dev libfbclient2 \
    && docker-php-ext-install pdo_firebird interbase mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
# gd/curl для сайта avtovokzal57 добавим отдельным слоём позже (в jessie-архиве конфликтуют пакеты).

# Боевой DocumentRoot = ais/ (ais/agents.php открывается по корню).
# AllowOverride None: игнорируем боевой .htaccess (в нём "Deny from all / Allow from 192.168.x"
# и AddDefaultCharset cp1251) — для локальной сверки открываем доступ и ставим UTF-8.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/ais
RUN sed -ri 's!/var/www/html!/var/www/html/ais!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html>\n    AllowOverride None\n    Require all granted\n</Directory>\nAddDefaultCharset UTF-8\n' > /etc/apache2/conf-available/ais.conf \
    && a2enconf ais
