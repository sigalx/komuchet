ARG NGINX_VERSION=stable-alpine
ARG APP_IMAGE=komuchet/php:latest

FROM ${APP_IMAGE} AS app

FROM nginx:${NGINX_VERSION} AS prod

COPY docker/nginx/nginx.prod.conf /etc/nginx/nginx.conf
COPY --from=app /var/www/html/public /var/www/html/public
