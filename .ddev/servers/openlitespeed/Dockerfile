FROM litespeedtech/openlitespeed:1.9.4-lsphp84

ARG DDEV_UID=1000
ARG DDEV_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends lsphp84-pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd -o -g "${DDEV_GID}" ols \
    && useradd -o -u "${DDEV_UID}" -g ols -M -s /usr/sbin/nologin ols

# PHP's own timezone is UTC, the app's timezone (config/app.php); see BENCHMARKS.md.
RUN printf 'date.timezone=UTC\n' > /usr/local/lsws/lsphp84/etc/php/8.4/mods-available/zz-timezone.ini
COPY conf/zz-opcache.ini /usr/local/lsws/lsphp84/etc/php/8.4/mods-available/zz-opcache.ini

COPY conf/httpd_config.conf /usr/local/lsws/conf/httpd_config.conf
COPY conf/vhosts/interview/vhconf.conf /usr/local/lsws/conf/vhosts/interview/vhconf.conf
# SL-11: OpenLiteSpeed answers an offloaded file with the file's own MIME type, and csv is not in its list.
RUN printf 'csv     = text/csv\n' >> /usr/local/lsws/conf/mime.properties

RUN cp /usr/local/lsws/conf/httpd_config.conf /usr/local/lsws/.conf/httpd_config.conf \
    && mkdir -p /usr/local/lsws/.conf/vhosts/interview \
    && cp /usr/local/lsws/conf/vhosts/interview/vhconf.conf /usr/local/lsws/.conf/vhosts/interview/vhconf.conf \
    && chown -R lsadm:lsadm /usr/local/lsws/conf

WORKDIR /var/www/html
