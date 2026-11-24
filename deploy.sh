#!/bin/sh
# One command for a fresh install and for every later update from Git.
#   sudo ./deploy.sh                 # git pull + install/restart
#   sudo ./deploy.sh --no-pull       # only reinstall/restart current checkout
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SOURCE="$HERE/project"
INSTALL_ROOT="${DOCGEN_INSTALL_DIR:-/opt/docgen-handoff}"
APP_DIR="$INSTALL_ROOT/app"
CONFIG_DIR="/etc/docgen"
ENV_FILE="$CONFIG_DIR/app.env"
SERVICE="${DOCGEN_SERVICE:-docgen.service}"
PORT_HEALTH_HOST="${DOCGEN_HEALTH_HOST:-127.0.0.1}"

die() { echo "$*" >&2; exit 1; }

if [ "$(id -u)" -ne 0 ]; then
  die "Запустите от root: sudo ./deploy.sh"
fi
if [ ! -f "$SOURCE/index.php" ] || [ ! -f "$SOURCE/vendor/autoload.php" ]; then
  die "Не найдена папка project/ с приложением и vendor/. Проверьте, что архив/репозиторий распакован полностью."
fi
if ! command -v php >/dev/null 2>&1 && [ -f "$ENV_FILE" ]; then
  die "PHP не найден в PATH. Проверьте установку (sudo sh ./install_ubuntu.sh)."
fi

# Pull as the repository owner: running via sudo must not create root-owned
# files in the working tree and must not trip git's "dubious ownership" check.
if [ "${1:-}" != "--no-pull" ] && [ "${1:-}" != "--help" ]; then
  OWNER=$(stat -c '%U' "$HERE" 2>/dev/null || echo root)
  if [ "$OWNER" != "root" ] && command -v runuser >/dev/null 2>&1; then
    runuser -u "$OWNER" -- git -C "$HERE" pull --ff-only
  else
    git -C "$HERE" pull --ff-only
  fi
fi

if [ "${1:-}" = "--help" ]; then
  echo "Использование: sudo ./deploy.sh [--no-pull]"
  echo "  --no-pull  не делать git pull, только переустановить код и перезапустить сервис"
  exit 0
fi

# Never publish a broken revision: lint the entrypoints before swapping code.
if command -v php >/dev/null 2>&1; then
  for file in "$SOURCE/index.php" "$SOURCE/features.php" "$SOURCE/table_features.php" "$SOURCE/public/index.php" "$SOURCE/public/queue.php" "$SOURCE/queue_manager.php" "$SOURCE/cron_send.php" "$SOURCE/webinar.php" "$SOURCE/webinars_tick.php"; do
    php -l "$file" >/dev/null || die "Ошибка синтаксиса PHP в $file — обновление отменено."
  done
  # Without OpenSSL, PHP has no ssl:// transport and PHPMailer reports
  # "Could not connect to SMTP host" even when the server is reachable.
  if ! php -r "exit(extension_loaded('openssl') ? 0 : 1);"; then
    die "В PHP не загружено расширение openssl. Установите: sudo apt-get install -y php-openssl && sudo systemctl restart $SERVICE"
  fi
fi

# First run: the standard installer creates the config, storage and service.
if [ ! -f "$ENV_FILE" ]; then
  echo "Первый запуск: выполняю install_ubuntu.sh …"
  sh "$HERE/install_ubuntu.sh"
  exit 0
fi

PORT=$(sed -n 's/^DOCGEN_PORT=//p' "$ENV_FILE" | tail -n 1)
PORT=${PORT:-8080}
case "$PORT" in
  ''|*[!0-9]*) die "Некорректный DOCGEN_PORT в $ENV_FILE" ;;
esac

echo "Обновляю код приложения в $APP_DIR …"
TMP_DIR="$INSTALL_ROOT/app.next.$$"
OLD_DIR="$INSTALL_ROOT/app.old.$$"
rm -rf "$TMP_DIR" "$OLD_DIR"
mkdir -p "$TMP_DIR"
cp -a "$SOURCE/." "$TMP_DIR/"
chown -R root:www-data "$TMP_DIR"
find "$TMP_DIR" -type d -exec chmod 0750 {} \;
find "$TMP_DIR" -type f -exec chmod 0640 {} \;

if [ -d "$APP_DIR" ]; then
  mv "$APP_DIR" "$OLD_DIR"
fi
if ! mv "$TMP_DIR" "$APP_DIR"; then
  [ -d "$OLD_DIR" ] && mv "$OLD_DIR" "$APP_DIR"
  die "Не удалось установить новую версию приложения."
fi

restore() {
  echo "Откатываю обновление …" >&2
  rm -rf "$APP_DIR"
  [ -d "$OLD_DIR" ] && mv "$OLD_DIR" "$APP_DIR"
  systemctl restart "$SERVICE" || true
}
trap restore INT TERM HUP

if ! systemctl restart "$SERVICE"; then
  restore
  trap - INT TERM HUP
  die "Не удалось перезапустить $SERVICE. Логи: journalctl -u $SERVICE -n 50"
fi
if ! systemctl is-active --quiet "$SERVICE"; then
  restore
  trap - INT TERM HUP
  die "$SERVICE не активен. Логи: journalctl -u $SERVICE -n 50"
fi

if command -v curl >/dev/null 2>&1; then
  if ! curl -fsS --max-time 10 -o /dev/null "http://$PORT_HEALTH_HOST:$PORT/"; then
    restore
    trap - INT TERM HUP
    die "Приложение не отвечает на http://$PORT_HEALTH_HOST:$PORT/ после обновления."
  fi
fi

STORAGE_DIR=$(sed -n 's/^DOCGEN_STORAGE_DIR=//p' "$ENV_FILE" | tail -n 1)
STORAGE_DIR=${STORAGE_DIR:-/var/lib/docgen}
if [ -f "$HERE/install_queue_timer.sh" ]; then
  sh "$HERE/install_queue_timer.sh" "$APP_DIR" "$STORAGE_DIR"
fi

rm -rf "$OLD_DIR"
trap - INT TERM HUP
echo "Готово. Приложение обновлено из $(git -C "$HERE" rev-parse --short HEAD 2>/dev/null || echo unknown)."
echo "Проверка: systemctl status $SERVICE ; логи: journalctl -u $SERVICE -f"
echo "Адрес: http://<ip-сервера>:$PORT/"
