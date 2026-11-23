#!/bin/sh
# Idempotent setup for the scheduled letter worker. Safe to run on every deploy:
# it only replaces the timer units and never touches app.env or stored data.
set -eu

APP_DIR="${1:-/opt/docgen-handoff/app}"
STORAGE_DIR="${2:-/var/lib/docgen}"
CONFIG_DIR=/etc/docgen
SERVICE=docgen-mailer.service
TIMER=docgen-mailer.timer

if [ "$(id -u)" -ne 0 ]; then
  echo "Запустите от root: sudo sh ./install_queue_timer.sh" >&2
  exit 1
fi
if [ ! -f "$APP_DIR/cron_send.php" ]; then
  echo "Не найден $APP_DIR/cron_send.php" >&2
  exit 1
fi
if [ ! -f "$CONFIG_DIR/app.env" ]; then
  echo "Не найден $CONFIG_DIR/app.env — сначала выполните install_ubuntu.sh" >&2
  exit 1
fi

PHP_BIN=$(command -v php || true)
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

cat > "/etc/systemd/system/$SERVICE" <<EOF
[Unit]
Description=Docgen scheduled letter queue worker
After=network.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=$APP_DIR
EnvironmentFile=$CONFIG_DIR/app.env
ExecStart=$PHP_BIN $APP_DIR/cron_send.php
UMask=0077
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=$STORAGE_DIR

[Install]
WantedBy=multi-user.target
EOF

cat > "/etc/systemd/system/$TIMER" <<EOF
[Unit]
Description=Run Docgen letter queue worker every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=15s
Unit=$SERVICE

[Install]
WantedBy=timers.target
EOF

chmod 0644 "/etc/systemd/system/$SERVICE" "/etc/systemd/system/$TIMER"
systemctl daemon-reload
systemctl enable --now "$TIMER"
echo "Планировщик очереди писем активен: systemctl status $TIMER ; логи: journalctl -u $SERVICE -f"
