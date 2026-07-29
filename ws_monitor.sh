#!/bin/bash
# ================================================================
# WebSocket 守护进程监控脚本
# 通过 cron 定时任务运行，不受 PHP disable_functions 限制
#
# 功能:
#   1. 检测 ws_start.flag → 启动 WS 守护进程
#   2. 检测 ws_stop.flag  → 停止 WS 守护进程
#   3. 自动重启：如果开启了自动启动且进程已死，自动重启
#
# 安装方法 (宝塔面板 → 计划任务 → 添加任务):
#   任务类型: Shell脚本
#   任务名称: WS守护进程监控
#   执行周期: 每N分钟 (建议1分钟)
#   脚本内容: /bin/bash /www/wwwroot/你的路径/ws_monitor.sh
#
# 或者通过 SSH 添加 crontab:
#   crontab -e
#   * * * * * /bin/bash /www/wwwroot/jcy.lvlong.xyz/xin/ws_monitor.sh >> /www/wwwroot/jcy.lvlong.xyz/xin/data/logs/ws_monitor.log 2>&1
# ================================================================

# 自动检测脚本所在目录（兼容各种安装路径）
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="$SCRIPT_DIR"

# 关键文件路径
PID_FILE="$APP_DIR/data/ws_master.pid"
LOG_FILE="$APP_DIR/data/logs/ws_daemon.log"
MONITOR_LOG="$APP_DIR/data/logs/ws_monitor.log"
START_FLAG="$APP_DIR/data/ws_start.flag"
STOP_FLAG="$APP_DIR/data/ws_stop.flag"
AUTOSTART_FILE="$APP_DIR/data/ws_autostart.flag"
STATUS_FILE="$APP_DIR/data/ws_monitor_status.json"

# 确保目录存在
mkdir -p "$APP_DIR/data/logs" 2>/dev/null

# 查找 PHP 可执行文件
find_php() {
    # 尝试常见路径
    for php_path in /www/server/php/*/bin/php /usr/bin/php /usr/local/bin/php /opt/remi/php*/root/usr/bin/php; do
        if [ -x "$php_path" ]; then
            echo "$php_path"
            return 0
        fi
    done
    # 使用 PATH 中的 php
    which php 2>/dev/null && return 0
    return 1
}

PHP_BIN=$(find_php)
if [ -z "$PHP_BIN" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 错误: 未找到 PHP 可执行文件" >> "$MONITOR_LOG"
    exit 1
fi

# 写入状态文件（供网页读取）
write_status() {
    local running=$1
    local pid=$2
    local msg=$3
    cat > "$STATUS_FILE" << EOF
{"running":$running,"pid":$pid,"message":"$msg","last_check":"$(date '+%Y-%m-%d %H:%M:%S')"}
EOF
}

# 检查进程是否存活
is_running() {
    if [ ! -f "$PID_FILE" ]; then
        return 1
    fi
    local pid=$(cat "$PID_FILE" 2>/dev/null)
    if [ -z "$pid" ]; then
        return 1
    fi
    # 方法1: /proc 文件系统
    if [ -d "/proc/$pid" ]; then
        # 验证是 PHP 进程
        local cmdline=$(cat "/proc/$pid/cmdline" 2>/dev/null | tr '\0' ' ')
        if echo "$cmdline" | grep -q "ws_client"; then
            return 0
        fi
        # cmdline 不匹配但进程存在，也可能是我们的进程
        return 0
    fi
    # 方法2: ps 命令
    if ps -p "$pid" > /dev/null 2>&1; then
        return 0
    fi
    return 1
}

# 获取当前 PID
get_pid() {
    if [ -f "$PID_FILE" ]; then
        cat "$PID_FILE" 2>/dev/null
    fi
}

# 启动守护进程
start_daemon() {
    if is_running; then
        local pid=$(get_pid)
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] 守护进程已在运行 (PID:$pid)，跳过启动" >> "$MONITOR_LOG"
        write_status true "$pid" "already_running"
        return 0
    fi

    # 清理旧 PID 文件
    rm -f "$PID_FILE" 2>/dev/null

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 正在启动 WebSocket 守护进程..." >> "$MONITOR_LOG"

    # 使用 nohup 后台启动
    nohup "$PHP_BIN" "$APP_DIR/ws_client.php" >> "$LOG_FILE" 2>&1 &
    local shell_pid=$!

    # 写入初始 PID（PHP 启动后会覆盖为自己的 PID）
    echo "$shell_pid" > "$PID_FILE"

    # 等待 PHP 进程初始化
    sleep 2

    # 验证进程是否启动成功
    if is_running; then
        local pid=$(get_pid)
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] 启动成功 (PID:$pid)" >> "$MONITOR_LOG"
        write_status true "$pid" "started_by_monitor"
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] 启动失败，请检查日志: $LOG_FILE" >> "$MONITOR_LOG"
        write_status false 0 "start_failed"
        # 清理无效 PID 文件
        rm -f "$PID_FILE" 2>/dev/null
    fi
}

# 停止守护进程
stop_daemon() {
    local pid=$(get_pid)
    if [ -z "$pid" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] 守护进程未运行（无PID文件）" >> "$MONITOR_LOG"
        write_status false 0 "not_running"
        return 0
    fi

    if ! is_running; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] 进程 $pid 已不存在，清理 PID 文件" >> "$MONITOR_LOG"
        rm -f "$PID_FILE" 2>/dev/null
        write_status false 0 "already_stopped"
        return 0
    fi

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 正在停止守护进程 (PID:$pid)..." >> "$MONITOR_LOG"

    # 发送 SIGTERM
    kill -15 "$pid" 2>/dev/null

    # 等待最多 5 秒
    local waited=0
    while [ $waited -lt 5 ]; do
        sleep 1
        waited=$((waited + 1))
        if ! is_running; then
            break
        fi
    done

    # 如果还没退出，强制杀死
    if is_running; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] SIGTERM 超时，发送 SIGKILL" >> "$MONITOR_LOG"
        kill -9 "$pid" 2>/dev/null
        sleep 1
    fi

    # 清理子进程（如果有）
    pkill -P "$pid" 2>/dev/null

    rm -f "$PID_FILE" 2>/dev/null
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 守护进程已停止" >> "$MONITOR_LOG"
    write_status false 0 "stopped_by_monitor"
}

# ==================== 主逻辑 ====================

# 1. 检查停止请求（最高优先级）
if [ -f "$STOP_FLAG" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 收到网页停止请求" >> "$MONITOR_LOG"
    stop_daemon
    rm -f "$STOP_FLAG" 2>/dev/null
    # 停止时也移除自动启动标记（用户主动停止，不自动重启）
    rm -f "$AUTOSTART_FILE" 2>/dev/null
    exit 0
fi

# 2. 检查启动请求
if [ -f "$START_FLAG" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 收到网页启动请求" >> "$MONITOR_LOG"
    start_daemon
    rm -f "$START_FLAG" 2>/dev/null
    # 启动成功后写入自动启动标记（保持运行）
    if is_running; then
        touch "$AUTOSTART_FILE" 2>/dev/null
    fi
    exit 0
fi

# 3. 自动重启检查
if [ -f "$AUTOSTART_FILE" ]; then
    if is_running; then
        # 进程正常运行，更新状态
        CURR_PID=$(get_pid)
        write_status true "$CURR_PID" "running"
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] 自动重启：进程已死，正在重启..." >> "$MONITOR_LOG"
        start_daemon
    fi
else
    # 未开启自动启动，仅更新状态
    if is_running; then
        CURR_PID=$(get_pid)
        write_status true "$CURR_PID" "running"
    else
        write_status false 0 "stopped"
    fi
fi
