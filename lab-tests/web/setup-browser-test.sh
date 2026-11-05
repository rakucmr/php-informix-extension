#!/usr/bin/env bash
set -euo pipefail

: "${WEB_USER:?Set WEB_USER to the service account, for example example_user}"
: "${INFORMIXSERVER:?Set INFORMIXSERVER}"
: "${INFORMIXSQLHOSTS:?Set INFORMIXSQLHOSTS}"
: "${IFX_TEST_HOST:?Set IFX_TEST_HOST}"
: "${IFX_TEST_SERVER:?Set IFX_TEST_SERVER}"
: "${IFX_TEST_DATABASE:?Set IFX_TEST_DATABASE}"
: "${IFX_TEST_USER:?Set IFX_TEST_USER}"
: "${IFX_TEST_PASSWORD:?Set IFX_TEST_PASSWORD}"

SOURCE_DIR="${SOURCE_DIR:-$HOME}"
INFORMIXDIR="${INFORMIXDIR:-/opt/ibm/informix}"
IFX_TEST_PORT="${IFX_TEST_PORT:-9088}"
IFX_TEST_IFX_DSN="${IFX_TEST_IFX_DSN:-}"

install -d -m 755 /var/www/ifx-test
install -m 644 "$SOURCE_DIR/ifx-browser-test.php" /var/www/ifx-test/index.php
install -m 644 "$SOURCE_DIR/ifx-all-types-lib.php" /var/www/ifx-test/ifx-all-types-lib.php

install -m 640 -o root -g "$WEB_USER" /dev/null /etc/ifx-test.env
cat > /etc/ifx-test.env <<EOF
INFORMIXDIR=${INFORMIXDIR}
INFORMIXSERVER=${INFORMIXSERVER}
INFORMIXSQLHOSTS=${INFORMIXSQLHOSTS}
IFX_TEST_HOST=${IFX_TEST_HOST}
IFX_TEST_PORT=${IFX_TEST_PORT}
IFX_TEST_SERVER=${IFX_TEST_SERVER}
IFX_TEST_DATABASE=${IFX_TEST_DATABASE}
IFX_TEST_USER=${IFX_TEST_USER}
IFX_TEST_PASSWORD=${IFX_TEST_PASSWORD}
EOF
if [ -n "$IFX_TEST_IFX_DSN" ]; then
    printf 'IFX_TEST_IFX_DSN=%s\n' "$IFX_TEST_IFX_DSN" >> /etc/ifx-test.env
fi
chown root:"$WEB_USER" /etc/ifx-test.env
chmod 640 /etc/ifx-test.env

cat > /etc/systemd/system/ifx-browser-test.service <<EOF
[Unit]
Description=PHP Informix browser smoke test
After=network.target

[Service]
Type=simple
User=${WEB_USER}
Group=${WEB_USER}
EnvironmentFile=/etc/ifx-test.env
WorkingDirectory=/var/www/ifx-test
ExecStart=/usr/bin/php -S 0.0.0.0:18080 -t /var/www/ifx-test
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now ifx-browser-test.service

if systemctl is-active --quiet firewalld; then
    firewall-cmd --permanent --add-port=18080/tcp >/dev/null
    firewall-cmd --reload >/dev/null
fi

sleep 2
systemctl --no-pager --full status ifx-browser-test.service || true
ss -ltnp | grep ':18080' || true
