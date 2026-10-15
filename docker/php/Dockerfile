# PHP 8.4 FPM (alpine, pinned). Stub app, replaced by real app later.
FROM php@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a

RUN docker-php-ext-install pdo_mysql mysqli opcache \
  && rm -rf /tmp/* /var/cache/apk/*

WORKDIR /var/www/html
