#!/bin/sh
#
# Compilează extensia ifx_pdo pe Linux/Unix.
# Rulează din directorul ifx_informix/ (sau din orice copie scriibilă a lui):
#
#     ./build-ifx-linux.sh [output.so]
#
set -eu

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
out=${1:-"$here/ifx_pdo.so"}

cd "$here"

phpize
./configure --enable-ifx-pdo
make -j"${JOBS:-2}"
cp modules/ifx_pdo.so "$out"

echo "Built: $out"
