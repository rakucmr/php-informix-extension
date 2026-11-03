# Test pdo_informix pe Linux (Debian) cu PHP 8.5, construit din ..\src
#
#   docker build -t pdo-informix-linux .
#   docker run --rm -e INFORMIXSERVER=informix_server pdo-informix-linux
#
FROM php:8.5-cli

# ---------------------------------------------------------------------------
# Build tools for phpize + runtime libs used by the Informix CLI driver
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential autoconf pkg-config file \
        libncurses6 libtinfo6 libssl3 ca-certificates \
        openjdk-21-jre-headless \
    && rm -rf /var/lib/apt/lists/*

# libelf soname (libelf.so.1) is required by the CSDK prerequisite check; the
# package is called libelf1t64 on Debian 13 and libelf1 on older releases.
RUN (apt-get update && apt-get install -y --no-install-recommends libelf1t64) \
    || (apt-get update && apt-get install -y --no-install-recommends libelf1) \
    ; rm -rf /var/lib/apt/lists/* ; ldconfig

# ---------------------------------------------------------------------------
# Informix Client SDK 4.50.FC12W12 (Linux x86_64), silent install.
# The installer is an InstallAnywhere launcher that needs a JRE.
# ---------------------------------------------------------------------------
COPY docker/csdk/ /opt/installers/csdk/
RUN cd /opt/installers/csdk \
    && tar -xf clientsdk.tar \
    && (./installclientsdk -i silent -f /opt/installers/csdk/installer.properties -l en || true) \
    && if [ ! -d /opt/IBM/informix/incl/cli ]; then \
         echo "== CSDK install failed =="; \
         ls -la /opt/installers/csdk; \
         cat /tmp/installclientsdk*.log 2>/dev/null || true; \
         cat /opt/installers/csdk/*.log 2>/dev/null || true; \
         exit 1; \
       fi

ENV INFORMIXDIR=/opt/IBM/informix \
    INFORMIXSQLHOSTS=/opt/IBM/informix/etc/sqlhosts \
    INFORMIXSERVER=informix_server \
    LD_LIBRARY_PATH=/opt/IBM/informix/lib:/opt/IBM/informix/lib/cli:/opt/IBM/informix/lib/esql \
    PATH=/opt/IBM/informix/bin:/opt/IBM/informix/lib/esql:/opt/IBM/informix/lib/cli:${PATH}

# the server is on the host; expose its port under the generic service name
RUN echo "informix_server 9088/tcp" >> /etc/services

# ---------------------------------------------------------------------------
# pdo_informix from the local sources
# ---------------------------------------------------------------------------
COPY src/ /build/pdo_informix/
RUN cd /build/pdo_informix \
    && phpize \
    && ./configure --with-pdo-informix="${INFORMIXDIR}" \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable pdo_informix \
    && rm -rf /build

RUN php -m | grep -i informix && php -r "var_dump(PDO::getAvailableDrivers());"

WORKDIR /tests
CMD ["php", "-v"]

