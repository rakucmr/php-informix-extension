#!/usr/bin/env bash
# RHEL 10.2 native PHP 8.3.36 + pdo_informix + direct ifx.
# Run as <ssh-user> (uses sudo). No Remi repository.
#
# Before running:
#   export ACCEPT_IBM_LICENSE=yes   # only after you accept the IBM CSDK license
#   export CSDK_ARCHIVE=/home/<ssh-user>/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar
set -euo pipefail

: "${ACCEPT_IBM_LICENSE:=no}"
if [ "$ACCEPT_IBM_LICENSE" != "yes" ]; then
    echo "Set ACCEPT_IBM_LICENSE=yes only after accepting the IBM CSDK license." >&2
    exit 1
fi

CSDK_ARCHIVE="${CSDK_ARCHIVE:-$HOME/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar}"
REPO_DIR="${REPO_DIR:-$HOME/php-informix-extension}"
INFORMIXDIR="${INFORMIXDIR:-/opt/ibm/informix}"
JOBS="${JOBS:-$(nproc)}"

sudo dnf install -y \
    git gcc gcc-c++ make autoconf automake libtool bison re2c \
    pkgconf-pkg-config tar curl wget libaio libnsl openssl \
    java-21-openjdk-headless \
    php php-cli php-devel php-pdo php-fpm

export INFORMIXDIR
export PATH="$INFORMIXDIR/bin:$PATH"
export LD_LIBRARY_PATH="$INFORMIXDIR/lib:$INFORMIXDIR/lib/cli:$INFORMIXDIR/lib/esql${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

if [ ! -f "$INFORMIXDIR/incl/cli/infxcli.h" ]; then
    [ -f "$CSDK_ARCHIVE" ] || {
        echo "CSDK archive not found: $CSDK_ARCHIVE" >&2
        exit 1
    }

    rm -rf "$HOME/csdk-install"
    mkdir -p "$HOME/csdk-install"
    tar -xf "$CSDK_ARCHIVE" -C "$HOME/csdk-install"
    tar -xf "$HOME/csdk-install/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar" \
        -C "$HOME/csdk-install"

    sudo groupadd -r informix 2>/dev/null || true
    sudo useradd -r -g informix -d "$INFORMIXDIR" -s /bin/bash informix 2>/dev/null || true

    cd "$HOME/csdk-install"
    cp csdk.properties installer.properties
    sed -i 's/^LICENSE_ACCEPTED=.*/LICENSE_ACCEPTED=TRUE/' installer.properties
    sed -i "s#^USER_INSTALL_DIR=.*#USER_INSTALL_DIR=$INFORMIXDIR#" installer.properties
    sed -i 's/^ENCRYPTION_LIBRARY=.*/ENCRYPTION_LIBRARY=OPENSSL/' installer.properties
    sudo ./installclientsdk -i silent -f "$HOME/csdk-install/installer.properties"
fi

sudo tee /etc/ld.so.conf.d/informix.conf >/dev/null <<'EOF'
/opt/ibm/informix/lib
/opt/ibm/informix/lib/cli
/opt/ibm/informix/lib/esql
EOF
sudo ldconfig

if [ ! -d "$REPO_DIR/.git" ]; then
    git clone https://github.com/rakucmr/php-informix-extension.git "$REPO_DIR"
else
    git -C "$REPO_DIR" pull --ff-only
fi

cd "$REPO_DIR/pdo_informix/src"
phpize
./configure --with-php-config="$(command -v php-config)" --with-pdo-informix="$INFORMIXDIR"
make -j"$JOBS"
sudo make install

cd "$REPO_DIR/ifx_informix"
make clean 2>/dev/null || true
LDFLAGS="-L$INFORMIXDIR/lib/cli -L$INFORMIXDIR/lib/esql" \
    ./configure --with-php-config="$(command -v php-config)" --with-ifx="$INFORMIXDIR"
make -j"$JOBS"
sudo make install

printf 'extension=pdo_informix.so\n' | sudo tee /etc/php.d/40-pdo_informix.ini >/dev/null
printf 'extension=ifx.so\n' | sudo tee /etc/php.d/50-ifx.ini >/dev/null
sudo systemctl restart php-fpm || true

php -v
php -m | grep -Ei 'ifx|informix|pdo'
php -r '
printf("PHP=%s\n", PHP_VERSION);
printf("pdo_informix=%s\n", extension_loaded("pdo_informix") ? "yes" : "no");
printf("ifx=%s\n", extension_loaded("ifx") ? "yes" : "no");
printf("ifx_pdo=%s\n", extension_loaded("ifx_pdo") ? "yes" : "no");
printf("ifx_funcs=%d\n", count(get_extension_funcs("ifx") ?: []));
printf("pdo_funcs=%d\n", count(get_extension_funcs("pdo_informix") ?: []));
printf("drivers=%s\n", implode(",", PDO::getAvailableDrivers()));
'
