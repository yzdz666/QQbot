#!/bin/bash
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
echo "=== QQbot WS 环境诊断 ==="
echo "APP_DIR: $APP_DIR"
echo "PHP: $(command -v php 2>/dev/null || echo 未找到)"
php -v 2>/dev/null | head -1 || true
echo "--- PHP extensions ---"
php -m 2>/dev/null | grep -Ei 'pdo_sqlite|sqlite|curl|sockets|openssl|mbstring' || true
echo "--- data 权限 ---"
ls -ld "$APP_DIR/data" "$APP_DIR/data/logs" 2>/dev/null || true
ls -l "$APP_DIR/data/bot.db" "$APP_DIR/data"/*.db* 2>/dev/null || true
echo "--- WS process ---"
ps aux | grep '[w]s_client.php' || true
echo "--- systemd ---"
systemctl --no-pager status qqbot-ws.service 2>/dev/null || true
echo "--- PHP disable_functions ---"
php -i 2>/dev/null | grep '^disable_functions' || true
