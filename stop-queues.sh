#!/bin/bash

# 停止队列脚本

echo "停止所有队列..."
pkill -f "queue:work" 2>/dev/null

# 删除 PID 文件
rm -f storage/logs/voice-queue.pid storage/logs/default-queue.pid

echo "队列已停止"