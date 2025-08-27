#!/bin/bash

# 简化的 Xbot 队列管理脚本

echo "==========================================="
echo "    Xbot 队列管理脚本"
echo "==========================================="

# 停止现有队列
echo "停止现有队列..."
pkill -f "queue:work" 2>/dev/null
sleep 2

# 启动默认队列
echo "启动默认队列..."
nohup php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=90 > storage/logs/default-queue.log 2>&1 &
DEFAULT_PID=$!
echo $DEFAULT_PID > storage/logs/default-queue.pid
echo "默认队列已启动 (PID: $DEFAULT_PID)"

echo ""
echo "队列状态:"
if ps -p $DEFAULT_PID > /dev/null; then
    echo "✓ 默认队列正在运行 (PID: $DEFAULT_PID)"
else
    echo "✗ 默认队列启动失败"
fi

echo ""
echo "日志文件:"
echo "- 默认队列: storage/logs/default-queue.log"
echo ""
echo "使用 './stop-queues.sh' 停止队列"