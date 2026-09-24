FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

# Fail the build if the photographs referenced by the catalog were omitted.
RUN php -r 'foreach(["public/images/insumos/catalogo-20260922.json","public/images/maquinas/catalogo.json"] as $f) { $m=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR); foreach($m["images"] as $i) { $p="public".$i["path"]; if(!is_file($p)||hash_file("sha256",$p)!==$i["sha256"]) { fwrite(STDERR,"Missing or altered catalog image: ".$p.PHP_EOL); exit(1); } } }'


RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      bootstrap/cache \
      storage/app/public \
    && chmod -R 777 storage bootstrap/cache \
    && chmod +x railway-start.sh

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && php artisan package:discover --ansi

ENV PORT=8080
EXPOSE 8080

CMD ["bash", "railway-start.sh"]
