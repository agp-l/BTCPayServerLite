#!/bin/sh
# Reproduce the GMP fix for the existing XAMPP PHP 8.0.30 installation only.
set -eu
xampp_root=${1:-/opt/lampp}
php_bin="$xampp_root/bin/php"
if [ "$("$php_bin" -r 'echo PHP_VERSION;')" != '8.0.30' ]; then
    echo 'This recipe is pinned to XAMPP PHP 8.0.30. Do not use it with another PHP version.' >&2
    exit 1
fi
if "$php_bin" -r 'exit(extension_loaded("gmp") ? 0 : 1);'; then
    "$php_bin" --ri gmp
    exit 0
fi
for cmd in curl tar sha256sum make autoconf cc sudo; do command -v "$cmd" >/dev/null; done
test -x "$xampp_root/bin/phpize"
test -x "$xampp_root/bin/php-config"
extension_dir=$("$xampp_root/bin/php-config" --extension-dir)
ini_file=$("$php_bin" -r 'echo php_ini_loaded_file();')
test -n "$ini_file" && test -f "$ini_file"
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/btcpay-gmp.XXXXXXXX")
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM
cd "$work_dir"
curl --fail --location --proto '=https' --tlsv1.2 -o php.tar.xz https://museum.php.net/php8/php-8.0.30.tar.xz
printf '%s\n' '216ab305737a5d392107112d618a755dc5df42058226f1670e9db90e77d777d9  php.tar.xz' | sha256sum -c -
tar -xf php.tar.xz
cd php-8.0.30/ext/gmp
"$xampp_root/bin/phpize"
./configure --with-php-config="$xampp_root/bin/php-config" --with-gmp
make -j2
# Test the module against the exact target binary BEFORE installation.
"$php_bin" -n -d "extension=$work_dir/php-8.0.30/ext/gmp/modules/gmp.so" --ri gmp
sudo install -m 0755 modules/gmp.so "$extension_dir/gmp.so"
if ! grep -Eq '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?gmp(\.so)?"?[[:space:]]*(;.*)?$' "$ini_file"; then
    sudo cp -p -- "$ini_file" "$ini_file.btcpay-gmp-$(date +%Y%m%d%H%M%S)"
    printf '\n; BTCPay Lite GMP extension\nextension=gmp.so\n' | sudo tee -a "$ini_file" >/dev/null
fi
"$php_bin" --ri gmp
printf 'GMP installed. Restart Apache when ready: sudo %s/lampp restartapache\n' "$xampp_root"
