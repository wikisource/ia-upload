FROM debian:jessie

RUN apt-get update \
    && apt-get install --yes --no-install-recommends lighttpd php5-cgi php5-curl djvulibre-bin graphicsmagick \
    && rm -rf /var/lib/apt/lists/* \
    && lighttpd-enable-mod fastcgi \
    && lighttpd-enable-mod fastcgi-php

COPY ./dev/php.ini /etc/php5/cgi/conf.d/99-ia-upload.ini

WORKDIR "/work"

EXPOSE 80

# Run the webserver, and enter Bash so we can run ./bin/ia-upload
ENTRYPOINT lighttpd -f "/work/dev/lighttpd.conf" && /bin/bash
