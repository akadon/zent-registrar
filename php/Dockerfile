FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo pdo_mysql
WORKDIR /app
COPY . .
EXPOSE 5000
CMD ["php", "-S", "0.0.0.0:5000", "index.php"]
