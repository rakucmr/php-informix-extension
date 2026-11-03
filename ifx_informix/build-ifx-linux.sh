#!/usr/bin/env bash
#
# Build ifx.so from the direct ifx extension.
#
# Requirements:
#   - PHP development tools (phpize, php-config)
#   - Informix Client SDK; set IFX_CSDK or pass the directory as $1
#
# The Linux build is not part of the Windows verification matrix.  It uses
# the same ifx.c as the Windows DLL and links against the CSDK CLI library.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
csdk=${IFX_CSDK:-${1:-/opt/ibm/informix}}
jobs=${JOBS:-2}

if [ ! -f "$csdk/incl/cli/infxcli.h" ]; then
    echo "Informix Client SDK headers not found in $csdk/incl/cli" >&2
    exit 1
fi

cd "$here"
phpize
./configure --with-ifx="$csdk"
make -j"$jobs"
cp modules/ifx.so ./ifx.so
echo "Built: $here/ifx.so"
