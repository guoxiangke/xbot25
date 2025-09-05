FROM dunglas/frankenphp

RUN install-php-extensions \
    pdo_mysql \
    gd \
    intl \
    zip \
    opcache \
    pcntl \
    bcmath
    
COPY . /app

WORKDIR /app

ENTRYPOINT ["php", "artisan", "octane:frankenphp"]