#!/bin/bash
set -e
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
[ -x "$PHP_BIN" ] || { echo "未找到 PHP CLI，请先设置 PHP_BIN=/www/server/php/XX/bin/php"; exit 1; }
WEB_USER="${WEB_USER:-}"
if [ -z "$WEB_USER" ]; then
  for u in www www-data nginx apache; do id "$u" >/dev/null 2>&1 && { WEB_USER="$u"; break; }; done
fi
[ -n "$WEB_USER" ] || { echo "未找到 Web 用户，请使用 WEB_USER=www 执行"; exit 1; }
mkdir -p "$APP_DIR/data/logs"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/data"
chmod 750 "$APP_DIR/data" "$APP_DIR/data/logs" || true
SERVICE=/etc/systemd/system/qqbot-ws.service
cat > "$SERVICE" <<EOF
[Unit]
Description=QQbot WebSocket Client
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$WEB_USER
Group=$WEB_USER
WorkingDirectory=$APP_DIR
ExecStart=$PHP_BIN $APP_DIR/ws_client.php
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=15
StandardOutput=append:$APP_DIR/data/logs/ws_daemon.log
StandardError=append:$APP_DIR/data/logs/ws_daemon.log

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now qqbot-ws.service
systemctl --no-pager --full status qqbot-ws.service || true
echo "安装完成：systemctl status qqbot-ws.service"
