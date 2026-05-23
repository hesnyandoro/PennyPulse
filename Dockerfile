FROM php:8.2.38-fpm-alpine AS production-alpine

#Update packages to patch security vulnerabilities
RUN apk update && apk upgrade && rm -rf /var/cache/apk/*

ENV APP_ENV=production \
    LOG_TO_STDOUT=true

# Install minimal dependencies for Alpine
RUN apk add --no-cache \
    apache2 \
    apache2-proxy \
    curl \
    wget \
    git \
    unzip \
    zip \
    gcc \
    musl-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    openssl-dev 
    

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    pdo \
    pdo_mysql \
    mysqli \
    zip \
    bcmath \
    opcache

# Install Composer
RUN curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Enable Apache modules for Alpine and add ServerName to suppress warning
RUN sed -i 's|^#LoadModule rewrite_module|LoadModule rewrite_module|' /etc/apache2/httpd.conf && \
    sed -i 's|^#LoadModule headers_module|LoadModule headers_module|' /etc/apache2/httpd.conf && \
    sed -i 's|^#LoadModule mpm_prefork_module|LoadModule mpm_prefork_module|' /etc/apache2/httpd.conf && \
    sed -i 's|^#LoadModule proxy_module|LoadModule proxy_module|' /etc/apache2/httpd.conf && \         
    sed -i 's|^#LoadModule proxy_fcgi_module|LoadModule proxy_fcgi_module|' /etc/apache2/httpd.conf && \ 
    sed -i 's|ErrorLog /var/www/logs/error.log|ErrorLog /proc/self/fd/2|' /etc/apache2/httpd.conf && \   
    echo "ServerName localhost" >> /etc/apache2/httpd.conf

# Create /var/www/logs directory BEFORE running as www-data
RUN rm -rf /var/www/logs && mkdir -p /var/www/logs \
    && chown www-data:www-data /var/www/logs \
    && chmod 777 /var/www/logs

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .

# Create application directories
RUN mkdir -p /var/www/html/uploads/receipts /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/logs 
    

# Install dependencies
RUN if [ -f "composer.json" ]; then \
    composer install --no-interaction --optimize-autoloader --no-dev; \
    fi

# Configure Apache VirtualHost
COPY apache-vhost.conf /etc/apache2/conf.d/default.conf

# Startup script to run both Apache and PHP-FPM
RUN echo '#!/bin/sh' > /start.sh && \
    echo 'php-fpm -D' >> /start.sh && \
    echo 'httpd -D FOREGROUND' >> /start.sh && \
    chmod +x /start.sh

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://localhost/health.php || exit 1

EXPOSE 80

CMD ["/start.sh"]
