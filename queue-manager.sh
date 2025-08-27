#!/bin/bash

# Xbot 队列管理脚本
# 用于启动 default 和 voice 队列进程

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_DIR="/Users/guo/Herd/xbot25"

echo -e "${BLUE}===========================================${NC}"
echo -e "${BLUE}    Xbot 队列管理脚本${NC}"
echo -e "${BLUE}===========================================${NC}"

# 检查是否在项目目录中
if [ ! -f "artisan" ]; then
    echo -e "${RED}错误: 请在 Laravel 项目根目录中运行此脚本${NC}"
    exit 1
fi

# 函数：启动队列
start_queue() {
    local queue_name=$1
    local priority=$2
    local sleep_time=$3
    
    echo -e "${GREEN}正在启动 $queue_name 队列...${NC}"
    
    # 使用 nohup 在后台启动队列进程
    nohup php artisan queue:work \
        --queue=$queue_name \
        --sleep=$sleep_time \
        --tries=3 \
        --timeout=90 \
        --memory=128M \
        > "storage/logs/queue-${queue_name}.log" 2>&1 &
    
    local pid=$!
    echo $pid > "storage/logs/queue-${queue_name}.pid"
    echo -e "${GREEN}$queue_name 队列已启动 (PID: $pid)${NC}"
    echo -e "${YELLOW}日志文件: storage/logs/queue-${queue_name}.log${NC}"
}

# 函数：停止队列
stop_queue() {
    local queue_name=$1
    
    if [ -f "storage/logs/queue-${queue_name}.pid" ]; then
        local pid=$(cat "storage/logs/queue-${queue_name}.pid")
        echo -e "${YELLOW}正在停止 $queue_name 队列 (PID: $pid)...${NC}"
        kill $pid 2>/dev/null
        rm -f "storage/logs/queue-${queue_name}.pid"
        echo -e "${GREEN}$queue_name 队列已停止${NC}"
    else
        echo -e "${YELLOW}$queue_name 队列未运行${NC}"
    fi
}

# 函数：检查队列状态
check_queue_status() {
    local queue_name=$1
    
    if [ -f "storage/logs/queue-${queue_name}.pid" ]; then
        local pid=$(cat "storage/logs/queue-${queue_name}.pid")
        if ps -p $pid > /dev/null 2>&1; then
            echo -e "${GREEN}✓ $queue_name 队列正在运行 (PID: $pid)${NC}"
        else
            echo -e "${RED}✗ $queue_name 队列进程已停止${NC}"
            rm -f "storage/logs/queue-${queue_name}.pid"
        fi
    else
        echo -e "${RED}✗ $queue_name 队列未运行${NC}"
    fi
}

# 函数：显示所有队列状态
show_all_status() {
    echo -e "${BLUE}队列状态:${NC}"
    check_queue_status "default"
    check_queue_status "voice"
}

# 函数：显示帮助信息
show_help() {
    echo -e "${BLUE}使用方法:${NC}"
    echo -e "  $0 start     - 启动所有队列"
    echo -e "  $0 stop      - 停止所有队列"
    echo -e "  $0 restart   - 重启所有队列"
    echo -e "  $0 status    - 显示队列状态"
    echo -e "  $0 logs      - 查看队列日志"
    echo -e ""
    echo -e "${BLUE}队列说明:${NC}"
    echo -e "  default  - 默认队列，处理联系人等普通任务"
    echo -e "  voice    - 语音队列，优先处理语音转换任务"
    echo -e ""
    echo -e "${BLUE}手动启动命令:${NC}"
    echo -e "  启动默认队列: php artisan queue:work --queue=default"
    echo -e "  启动语音队列: php artisan queue:work --queue=voice"
    echo -e ""
    echo -e "${BLUE}监控命令:${NC}"
    echo -e "  监控队列: php artisan queue:monitor"
    echo -e "  查看失败任务: php artisan queue:failed"
    echo -e "  重试失败任务: php artisan queue:retry all"
}

# 函数：显示日志
show_logs() {
    echo -e "${BLUE}队列日志:${NC}"
    echo -e "${YELLOW}默认队列日志:${NC}"
    if [ -f "storage/logs/queue-default.log" ]; then
        tail -n 20 storage/logs/queue-default.log
    else
        echo "日志文件不存在"
    fi
    
    echo ""
    echo -e "${YELLOW}语音队列日志:${NC}"
    if [ -f "storage/logs/queue-voice.log" ]; then
        tail -n 20 storage/logs/queue-voice.log
    else
        echo "日志文件不存在"
    fi
}

# 主逻辑
case "${1:-help}" in
    start)
        echo -e "${GREEN}启动所有队列...${NC}"
        start_queue "voice" "high" "1"
        sleep 2
        start_queue "default" "normal" "3"
        show_all_status
        ;;
    stop)
        echo -e "${YELLOW}停止所有队列...${NC}"
        stop_queue "voice"
        stop_queue "default"
        ;;
    restart)
        echo -e "${YELLOW}重启所有队列...${NC}"
        stop_queue "voice"
        stop_queue "default"
        sleep 3
        start_queue "voice" "high" "1"
        sleep 2
        start_queue "default" "normal" "3"
        show_all_status
        ;;
    status)
        show_all_status
        ;;
    logs)
        show_logs
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        echo -e "${RED}未知命令: $1${NC}"
        echo ""
        show_help
        exit 1
        ;;
esac

echo ""
echo -e "${BLUE}===========================================${NC}"