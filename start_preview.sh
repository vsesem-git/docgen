#!/bin/sh
set -eu
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT="$HERE/project"
WEBROOT="$PROJECT/public"
PORT="${PORT:-8765}"
# The built-in server is single-threaded by default: one slow SMTP check would
# freeze every other request. Several workers keep the UI responsive.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
export DOCGEN_STORAGE_DIR="${DOCGEN_STORAGE_DIR:-$HERE/storage}"

if [ -n "${PHP_BIN:-}" ]; then
  exec "$PHP_BIN" -d upload_max_filesize=24M -d post_max_size=30M -d max_execution_time=300 -d memory_limit=512M -S "0.0.0.0:$PORT" -t "$WEBROOT" "$WEBROOT/index.php"
fi

# Arena's portable PHP runtime may lose executable bits and Debian SONAME
# aliases between snapshots; recreate those aliases before launching it.
RUNTIME="$HERE/../.php-runtime"
PHP_WRAPPER="$RUNTIME/php"
PHP_ROOT="$RUNTIME/root"
PHP_BINARY="$PHP_ROOT/usr/bin/php8.4"
LIB_DIR="$PHP_ROOT/usr/lib/x86_64-linux-gnu"
if [ -f "$PHP_WRAPPER" ] && [ -f "$PHP_BINARY" ]; then
  chmod +x "$PHP_BINARY" 2>/dev/null || true
  for library in "$LIB_DIR"/*.so.*; do
    [ -f "$library" ] || continue
    filename=${library##*/}
    stem=${filename%%.so.*}
    version=${filename#"$stem".so.}
    soname="$LIB_DIR/$stem.so.${version%%.*}"
    if [ ! -e "$soname" ] && [ ! -L "$soname" ]; then
      ln -s "$filename" "$soname" 2>/dev/null || true
    fi
  done
  exec sh "$PHP_WRAPPER" -d upload_max_filesize=24M -d post_max_size=30M -d max_execution_time=300 -d memory_limit=512M -S "0.0.0.0:$PORT" -t "$WEBROOT" "$WEBROOT/index.php"
fi

if command -v php >/dev/null 2>&1; then
  exec php -d upload_max_filesize=24M -d post_max_size=30M -d max_execution_time=300 -d memory_limit=512M -S "0.0.0.0:$PORT" -t "$WEBROOT" "$WEBROOT/index.php"
fi

echo "PHP не найден. Установите PHP 8.2+ с zip, dom/xml, xmlwriter, mbstring, gd и ctype; либо задайте PHP_BIN." >&2
exit 127
