FROM php:8.2-cli

LABEL maintainer="Nico Friedrich <n.friedrich@cipico.de>"
LABEL description="CiviCRM buildkit image for CI testing of CiviCRM extensions"

ARG BUILDKIT_VERSION=main

# System dependencies
RUN apt-get update -qq && apt-get install -y -qq \
    git \
    unzip \
    default-mysql-client \
    libldap-dev \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    mariadb-client \
    nodejs \
    npm \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by CiviCRM
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    ldap \
    pdo_mysql \
    mysqli \
    intl \
    gd \
    zip \
    bcmath \
    soap \
    exif

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Clone and install buildkit
RUN git clone --depth 1 --branch ${BUILDKIT_VERSION} \
    https://github.com/civicrm/civicrm-buildkit.git /opt/buildkit \
    && cd /opt/buildkit \
    && composer install --no-interaction --no-progress

ENV PATH="/opt/buildkit/bin:${PATH}"

WORKDIR /workspace
