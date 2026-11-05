# Fedora 45 + RHEL 10.2 setup log

Date: 2026-11-04 (Europe/Bucharest)

Goal: install native PHP, Informix Client SDK 4.50.FC12W12,
`pdo_informix` and direct `ifx` on both lab VMs, then run a real database
smoke test.

The VM password was supplied separately and is intentionally not stored here.

## VMs

| Host | Address | OS | Native PHP max |
|---|---|---|---|
| RHEL | `<rhel-host>` | Red Hat Enterprise Linux 10.2 (Coughlan), kernel 6.12 | **8.3.36** |
| Fedora | `<fedora-host>` | Fedora Linux 45 Workstation, kernel 7.2.15 | **8.5.12** |

Both are `x86_64`, 6+ GB RAM, >50 GB free. `<ssh-user>` has sudo with password.

## SSH discovery / access

Windows host found the Hyper-V Default Switch at `<informix-host>/20`.
ARP showed:

```text
<rhel-host>    <mac-rhel>
<fedora-host>   <mac-fedora>
```

SSH helper used (password from `DSH_VM_PASS`, never printed):

```powershell
py -3 "<kit>\tools\vm_ssh.py" <host> "<command>"
py -3 "<kit>\tools\vm_ssh.py" --sudo <host> "<command>"
py -3 "<kit>\tools\vm_scp.py" <host> <local> <remote>
```

## Native PHP versions

```bash
dnf -q list --available "php*"
```

Results:

- RHEL 10.2 AppStream: `php-8.3.36-1.el10_2.x86_64` (maximum available).
- Fedora 45: `php-8.5.12-1.fc45.x86_64` (maximum available).

No Remi repository was added or used.

## Packages installed

RHEL 10.2:

```bash
sudo dnf install -y git gcc gcc-c++ make autoconf automake libtool bison \
  re2c pkgconf-pkg-config tar curl wget libaio libnsl openssl \
  java-21-openjdk-headless php php-cli php-devel php-pdo php-fpm
```

Fedora 45:

```bash
sudo dnf install -y git gcc gcc-c++ make autoconf automake libtool bison \
  re2c pkgconf-pkg-config tar curl wget libaio libnsl \
  ncurses-compat-libs openssl java-21-openjdk-headless \
  php php-cli php-devel php-pdo php-fpm
```

Fedora later needed:

```bash
sudo dnf install -y libxcrypt-compat
```

## Informix Client SDK

Archive copied from Windows:

```text
<csdk-archive>
```

Uploaded to `/home/<ssh-user>/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar` on both VMs
(81,510,400 bytes), then:

```bash
rm -rf ~/csdk-install
mkdir -p ~/csdk-install
tar -xf ~/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar -C ~/csdk-install
tar -xf ~/csdk-install/ibm.csdk.4.50.12.12.Linux.64.x86_64.tar -C ~/csdk-install
```

Created the `informix` OS user before installation (the installer's post-install
`chown informix:informix` step otherwise hangs):

```bash
sudo groupadd -r informix
sudo useradd -r -g informix -d /opt/ibm/informix -s /bin/bash informix
```

Response file prepared from the bundled `csdk.properties`:

```bash
cd ~/csdk-install
cp csdk.properties installer.properties
sed -i 's/^LICENSE_ACCEPTED=.*/LICENSE_ACCEPTED=TRUE/' installer.properties
sed -i 's#^USER_INSTALL_DIR=.*#USER_INSTALL_DIR=/opt/ibm/informix#' installer.properties
sed -i 's/^ENCRYPTION_LIBRARY=.*/ENCRYPTION_LIBRARY=OPENSSL/' installer.properties
```

> License acceptance was performed for this lab. Do not reuse a response file
> with `LICENSE_ACCEPTED=TRUE` unless you have accepted IBM's license yourself.

Installation:

```bash
cd ~/csdk-install
sudo ./installclientsdk -i silent -f /home/<ssh-user>/csdk-install/installer.properties
```

Fedora needed Java 21 and the XSLT extension enabled; Java 25 is the default:

```bash
sudo rm -rf /opt/ibm/informix
sudo env JAVA_HOME=/usr/lib/jvm/java-21-openjdk \
  PATH=/usr/lib/jvm/java-21-openjdk/bin:$PATH \
  JAVA_TOOL_OPTIONS="-Djdk.xml.enableExtensionFunctions=true -Djava.awt.headless=true" \
  ./installclientsdk -i silent -f /home/<ssh-user>/csdk-install/installer.properties
```

Verification:

```bash
test -f /opt/ibm/informix/incl/cli/infxcli.h && echo headers=OK
test -f /opt/ibm/informix/lib/cli/libifcli.so && echo libifcli=OK
```

Both VMs passed. Fedora initially lacked `libcrypt.so.1`; `libxcrypt-compat`
fixed it. Fedora's first install also had a Java 25 XSLT error; the Java 21
`JAVA_TOOL_OPTIONS` rerun completed with 0 fatal and 0 non-fatal errors.

## Loader and PHP configuration

```bash
sudo tee /etc/ld.so.conf.d/informix.conf >/dev/null <<'EOF'
/opt/ibm/informix/lib
/opt/ibm/informix/lib/cli
/opt/ibm/informix/lib/esql
EOF
sudo ldconfig
```

`php --ini` on both showed scan directory `/etc/php.d`.

```bash
sudo tee /etc/php.d/40-pdo_informix.ini >/dev/null <<'EOF'
extension=pdo_informix.so
EOF

sudo tee /etc/php.d/50-ifx.ini >/dev/null <<'EOF'
extension=ifx.so
EOF
```

No `ifx_pdo` was enabled.

## Source and builds

Both VMs:

```bash
git clone https://github.com/rakucmr/php-informix-extension.git \
  ~/php-informix-extension
cd ~/php-informix-extension

export INFORMIXDIR=/opt/ibm/informix
export PATH="$INFORMIXDIR/bin:$PATH"
export LD_LIBRARY_PATH="$INFORMIXDIR/lib:$INFORMIXDIR/lib/cli:$INFORMIXDIR/lib/esql${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
```

### pdo_informix

```bash
cd ~/php-informix-extension/pdo_informix/src
phpize
./configure \
  --with-php-config="$(command -v php-config)" \
  --with-pdo-informix="$INFORMIXDIR"
make -j"$(nproc)"
sudo make install
```

Both builds succeeded. Installed to:

```text
/usr/lib64/php/modules/pdo_informix.so
```

### ifx direct

The first build failed with `cannot find -lifcli`, because `config.m4` only adds
`$INFORMIXDIR/lib` while the CSDK shared library is in `$INFORMIXDIR/lib/cli`.
Workaround used:

```bash
cd ~/php-informix-extension/ifx_informix
make clean
LDFLAGS="-L$INFORMIXDIR/lib/cli -L$INFORMIXDIR/lib/esql" \
  ./configure --with-php-config="$(command -v php-config)" --with-ifx="$INFORMIXDIR"
make -j"$(nproc)"
sudo make install
```

Both succeeded. Installed to:

```text
/usr/lib64/php/modules/ifx.so
```

## Extension verification

Both VMs:

```bash
php -v
php -m | grep -Ei 'ifx|informix|pdo'
```

RHEL result: PHP **8.3.36**, modules `ifx` and `pdo_informix`, no `ifx_pdo`.

Fedora result: PHP **8.5.12**, modules `ifx` and `pdo_informix`, no `ifx_pdo`.

```bash
php -r '
printf("PHP=%s\n", PHP_VERSION);
printf("pdo_informix=%s\n", extension_loaded("pdo_informix")?"yes":"no");
printf("ifx=%s\n", extension_loaded("ifx")?"yes":"no");
printf("ifx_pdo=%s\n", extension_loaded("ifx_pdo")?"yes":"no");
printf("ifx_connect=%s\n", function_exists("ifx_connect")?"yes":"no");
printf("ifx_funcs=%d\n", count(get_extension_funcs("ifx")?:[]));
printf("pdo_informix_funcs=%d\n", count(get_extension_funcs("pdo_informix")?:[]));
printf("drivers=%s\n", implode(",", PDO::getAvailableDrivers()));
'
```

Both returned:

```text
pdo_informix=yes
ifx=yes
ifx_pdo=no
ifx_connect=yes
ifx_funcs=39
pdo_informix_funcs=0
drivers=sqlite,informix
```

`ldd` showed no `not found` dependencies for either module.

## Database smoke test

The Informix IDS on the Windows host listens only on `127.0.0.1:9088`.
For testing from the VMs, a temporary Windows TCP proxy was used:

```text
0.0.0.0:19088 -> 127.0.0.1:9088
```

It was stopped after testing.

On each VM:

```bash
printf '<server> olsoctcp <informix-host> 19088\n' > "$HOME/informix-sqlhosts"
export INFORMIXDIR=/opt/ibm/informix
export INFORMIXSERVER=<server>
export INFORMIXSQLHOSTS="$HOME/sqlhosts"
export IFX_TEST_PASSWORD=<password>
php "$HOME/ifx-smoke.php"
```

Test script: `E:\AI DEV\vm-ifx-test.php` (uploaded to `/home/<ssh-user>/ifx-smoke.php`).
It checks module load, exactly 39 `ifx` functions, a PDO connection and
`SELECT COUNT(*) FROM systables`, plus the same query through direct `ifx`.

Results:

RHEL 10.2 / PHP 8.3.36:

```text
PHP 8.3.36 on Linux 6.12.0-211.73.1.el10_2.x86_64
pdo_informix=yes ifx=yes ifx_pdo=no
OK   ifx exposes 39 functions
OK   PDO connected to <database>; systables=110
OK   ifx connected to <database>; systables=110
RESULT: PASS
```

Fedora 45 / PHP 8.5.12:

```text
PHP 8.5.12 on Linux 7.2.15-300.fc45.x86_64
pdo_informix=yes ifx=yes ifx_pdo=no
OK   ifx exposes 39 functions
OK   PDO connected to <database>; systables=110
OK   ifx connected to <database>; systables=110
RESULT: PASS
```

## Status

- RHEL 10.2: PHP 8.3.36, CSDK, `pdo_informix`, `ifx` — PASS.
- Fedora 45: PHP 8.5.12, CSDK, `pdo_informix`, `ifx` — PASS.
- No Remi repository used.
- Max native PHP without Remi: RHEL **8.3.36**, Fedora **8.5.12**.
- PHP 8.5 on RHEL would require building PHP from source (not done).
- `ifx_pdo` is not enabled; it would conflict with direct `ifx`.

## Final network change (2026-11-05)

The user changed `sqlhosts` to listen on `0.0.0.0` and configured the
Windows firewall with scoped rules for `<vm-subnet>` (VMs) and
`<lan-subnet>` (LAN). Verified:

```text
netstat -ano | findstr :9088
TCP    0.0.0.0:9088    LISTENING
```

The temporary 19088 proxy is no longer needed. VM `sqlhosts` now points to:

```text
<server> olsoctcp <informix-host> 9088
```

Both VM smoke tests were repeated directly on 9088: **PASS**.

## Browser test pages

A small PHP built-in server is running on each VM as the systemd service
`ifx-browser-test.service`, port 18080:

- RHEL: <http://<rhel-host>:18080/>
- Fedora: <http://<fedora-host>:18080/>

Files:

```text
/home/<ssh-user>/ifx-browser-test.php
/var/www/ifx-test/index.php
/etc/ifx-test.env              (mode 640, root:<ssh-user>)
/etc/systemd/system/ifx-browser-test.service
```

The service is a test-only PHP development server, not a production web
server. Both HTTP endpoints returned 200 and the pages showed `PASS`.

## All-types table test

The browser page and the CLI test now create `php_ifx_all_types` with these
built-in Informix families:

- character: `CHAR`, `VARCHAR`, `LVARCHAR`, `NCHAR`, `NVARCHAR`
- numeric: `BOOLEAN`, `SMALLINT`, `INTEGER`, `BIGINT`, `INT8`, `DECIMAL`,
  `NUMERIC`, `MONEY`, `SMALLFLOAT`, `REAL`, `FLOAT`, `DOUBLE PRECISION`
- temporal: `DATE`, `DATETIME`, `INTERVAL`
- LOB: `TEXT`, `BYTE`, `BLOB`, `CLOB`
- collection: `SET`, `MULTISET`, `LIST`, `ROW`
- serial: `SERIAL`

PDO writes and reads row 1. Direct `ifx` writes and reads row 2, except that
the direct driver cannot `SELECT` a `CLOB` (SQLCODE -11013); the CLOB written by
`ifx` is verified through PDO. The BLOB written by `ifx` is also verified through
PDO. The table is created only if missing and is preserved after the test;
only rows `id=1` and `id=2` are replaced on repeated runs.

Both VMs passed the complete all-types test. CLI wrapper:

```bash
php "$HOME/ifx-all-types-run.php"
```

Browser page:

- RHEL: <http://<rhel-host>:18080/>
- Fedora: <http://<fedora-host>:18080/>

## Windows all-types test

Windows x64, PHP 8.5.11 NTS, `pdo_informix` and direct `ifx` loaded together,
no `ifx_pdo`:

```powershell
$env:INFORMIXDIR = '<CSDK>'
$env:INFORMIXSERVER = '<server>'
$env:INFORMIXSQLHOSTS = '<IDS>\etc\sqlhosts'
$env:PATH = "<CSDK>\bin;$env:PATH"

$env:IFX_TEST_HOST = '127.0.0.1'
$env:IFX_TEST_PORT = '9088'
$env:IFX_TEST_DATABASE = '<database>'
$env:IFX_TEST_USER = '<db-user>'
$env:IFX_TEST_PASSWORD = '<password>'
$env:IFX_TEST_IFX_DSN = 'informix:server=<server>;database=<database>;host=<informix-host>;service=9088;protocol=olsoctcp;'

php -n `
  -d "extension_dir=<project>\pdo_informix\extension" `
  -d extension=php_pdo_informix.dll `
  -d "extension=<project>\ifx_informix\ifx.dll" `
  tests\ifx-all-types-run.php
```

The explicit `IFX_TEST_IFX_DSN` was required for direct `ifx`; using only the
Windows `sqlhosts` entry returned `-908` in this setup.

Result: **PASS** for all PDO and ifx checks, including the `CLOB`/`BLOB`
verification through PDO.



