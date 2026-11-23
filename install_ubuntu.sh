#!/bin/sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SOURCE_PROJECT="$HERE/project"
INSTALL_ROOT="${DOCGEN_INSTALL_DIR:-/opt/docgen-handoff}"
APP_DIR="$INSTALL_ROOT/app"
STORAGE_DIR="${DOCGEN_STORAGE_DIR:-/var/lib/docgen}"
CONFIG_DIR="/etc/docgen"
SERVICE_NAME="docgen.service"
PORT="${DOCGEN_PORT:-8080}"
BIND="${DOCGEN_BIND:-0.0.0.0}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Запустите установщик от root: sudo sh ./install_ubuntu.sh" >&2
  exit 1
fi
if [ ! -f "$SOURCE_PROJECT/vendor/autoload.php" ] || [ ! -f "$SOURCE_PROJECT/index.php" ]; then
  echo "Не найдена папка project/ с приложением и vendor/. Запускайте скрипт из распакованного пакета." >&2
  exit 1
fi
case "$PORT" in
  ''|*[!0-9]*) echo "DOCGEN_PORT должен быть числом от 1 до 65535." >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
  echo "DOCGEN_PORT должен быть числом от 1 до 65535." >&2
  exit 1
fi
case "$BIND" in
  *[!A-Za-z0-9._-]*|'') echo "DOCGEN_BIND должен быть адресом IPv4 или именем хоста." >&2; exit 1 ;;
esac
if ! command -v apt-get >/dev/null 2>&1; then
  echo "Этот установщик рассчитан на Ubuntu/Debian с apt-get." >&2
  exit 1
fi

# Some older stock repositories install PHP < 8.1, so reject these releases
# before apt downloads and installs hundreds of MB of packages.
OS_ID=''
OS_VERSION=''
if [ -r /etc/os-release ]; then
  . /etc/os-release
  OS_ID=${ID:-}
  OS_VERSION=${VERSION_ID:-}
fi
if [ "$OS_ID" = "ubuntu" ] && [ "$OS_VERSION" = "20.04" ]; then
  echo "Ubuntu 20.04 (focal) устанавливает PHP 7.4 из стандартного архива; приложению нужен PHP 8.1+." >&2
  echo "Установщик остановлен до apt-get update/install. Используйте новый LXC на Ubuntu 24.04 LTS или Debian 12+, затем повторно запустите этот скрипт." >&2
  exit 1
fi
case "$OS_ID:$OS_VERSION" in
  ubuntu:18.04|debian:10|debian:11)
    echo "$OS_ID $OS_VERSION со штатными пакетами устанавливает PHP ниже 8.1; приложению нужен PHP 8.1+." >&2
    echo "Установщик остановлен до apt-get update/install. Используйте Ubuntu 22.04/24.04 или Debian 12+; предпочтительно новый LXC на Ubuntu 24.04 либо Debian 12." >&2
    exit 1
    ;;
esac

export DEBIAN_FRONTEND=noninteractive
echo "Устанавливаю PHP, расширения и LibreOffice для PDF…"
apt-get update
apt-get install -y --no-install-recommends \
  php-cli php-zip php-xml php-mbstring php-gd php-curl php-openssl \
  libreoffice-writer fonts-liberation ca-certificates unzip openssl

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
PHP_MAJOR=${PHP_VERSION%%.*}
PHP_MINOR=${PHP_VERSION#*.}
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; then
  echo "Нужен PHP 8.1 или новее, найден PHP $PHP_VERSION." >&2
  exit 1
fi
for extension in zip dom xml xmlwriter mbstring gd ctype openssl; do
  if ! php -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
    echo "PHP extension '$extension' не загружен. Проверьте установку пакетов PHP." >&2
    exit 1
  fi
done
if ! command -v libreoffice >/dev/null 2>&1; then
  echo "Команда libreoffice не найдена после установки libreoffice-writer." >&2
  exit 1
fi

install -d -m 0755 "$APP_DIR"
cp -a "$SOURCE_PROJECT/." "$APP_DIR/"
chown -R root:www-data "$INSTALL_ROOT"
find "$INSTALL_ROOT" -type d -exec chmod 0750 {} \;
find "$INSTALL_ROOT" -type f -exec chmod 0640 {} \;
install -d -o www-data -g www-data -m 0700 "$STORAGE_DIR"
install -d -m 0750 "$CONFIG_DIR"

if [ -n "${DOCGEN_ADMIN_PASSWORD:-}" ]; then
  ADMIN_PASSWORD=$DOCGEN_ADMIN_PASSWORD
  GENERATED_PASSWORD=0
else
  ADMIN_PASSWORD=$(openssl rand -base64 27 | tr -d '\n')
  GENERATED_PASSWORD=1
fi
if [ "${#ADMIN_PASSWORD}" -lt 12 ]; then
  echo "Пароль администратора должен содержать не менее 12 символов." >&2
  exit 1
fi
PASSWORD_HASH=$(printf '%s' "$ADMIN_PASSWORD" | php -r '$p=stream_get_contents(STDIN); echo password_hash($p, PASSWORD_DEFAULT);')
if [ -z "$PASSWORD_HASH" ]; then
  echo "Не удалось подготовить хеш пароля администратора." >&2
  exit 1
fi

umask 077
cat > "$CONFIG_DIR/app.env" <<EOF
DOCGEN_STORAGE_DIR=$STORAGE_DIR
DOCGEN_LIBREOFFICE_BIN=/usr/bin/libreoffice
DOCGEN_ADMIN_PASSWORD_HASH=$PASSWORD_HASH
DOCGEN_BIND=$BIND
DOCGEN_PORT=$PORT
EOF
chmod 0600 "$CONFIG_DIR/app.env"

install -d -m 0755 /usr/local/lib/docgen
cat > /usr/local/lib/docgen/run-server.sh <<EOF
#!/bin/sh
set -eu
# Several workers: a slow SMTP connection must not block other requests.
export PHP_CLI_SERVER_WORKERS="\${PHP_CLI_SERVER_WORKERS:-8}"
exec /usr/bin/php -d upload_max_filesize=24M -d post_max_size=30M -d max_execution_time=300 -d memory_limit=512M -S "\${DOCGEN_BIND:-$BIND}:\${DOCGEN_PORT:-$PORT}" -t "$APP_DIR/public" "$APP_DIR/public/index.php"
EOF
chmod 0755 /usr/local/lib/docgen/run-server.sh
cat > "/etc/systemd/system/$SERVICE_NAME" <<EOF
[Unit]
Description=Docgen local document and email test application
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$APP_DIR
EnvironmentFile=$CONFIG_DIR/app.env
ExecStart=/usr/local/lib/docgen/run-server.sh
Restart=on-failure
RestartSec=3
UMask=0077
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=$STORAGE_DIR
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
if ! systemctl enable --now "$SERVICE_NAME"; then
  echo "Не удалось запустить службу. Диагностика: systemctl status $SERVICE_NAME и journalctl -u $SERVICE_NAME." >&2
  if [ "$GENERATED_PASSWORD" -eq 1 ]; then
    echo "Сгенерированный пароль первого входа: $ADMIN_PASSWORD" >&2
  fi
  exit 1
fi

# Schedule the opt-in mail queue worker. Safe to rerun; it never touches app.env.
if [ -f "$HERE/install_queue_timer.sh" ]; then
  sh "$HERE/install_queue_timer.sh" "$APP_DIR" "$STORAGE_DIR"
fi

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -n "$IP" ] || IP=127.0.0.1
echo
echo "Установка завершена."
echo "Приложение: http://$IP:$PORT/"
echo "Bind: $BIND:$PORT"
echo "Сервис: systemctl status $SERVICE_NAME"
echo "Логи: journalctl -u $SERVICE_NAME -f"
echo "Приватное хранилище: $STORAGE_DIR (вне web-root)"
echo "SMTP-пароль впоследствии можно менять в настройках приложения; хеш пароля администратора находится в $CONFIG_DIR/app.env."
if [ "$GENERATED_PASSWORD" -eq 1 ]; then
  echo
  echo "Пароль первого входа (показывается только сейчас):"
  printf '%s\n' "$ADMIN_PASSWORD"
else
  echo "Использован пароль администратора из переменной DOCGEN_ADMIN_PASSWORD."
fi
echo
echo "Важно: приложение использует HTTP без TLS. Оставьте порт доступным только из доверенной локальной сети или настройте HTTPS/туннель; не публикуйте его в Интернет."
