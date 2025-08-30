#!/bin/bash

# Xbot 队列管理脚本

ACTION=${1:-start}

case $ACTION in
    start)
        echo "==========================================="
        echo "    启动 Xbot 队列"
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
        echo "使用 './queue-manager.sh stop' 停止队列"
        ;;
        
    stop)
        echo "==========================================="
        echo "    停止 Xbot 队列"
        echo "==========================================="
        
        echo "停止所有队列..."
        pkill -f "queue:work" 2>/dev/null
        
        # 删除 PID 文件
        rm -f storage/logs/voice-queue.pid storage/logs/default-queue.pid
        
        echo "队列已停止"
        ;;
        
    status)
        echo "==========================================="
        echo "    Xbot 队列状态"
        echo "==========================================="
        
        # 检查队列进程
        QUEUE_PROCESSES=$(ps aux | grep "queue:work" | grep -v grep | wc -l)
        echo "运行中的队列进程数: $QUEUE_PROCESSES"
        
        if [ $QUEUE_PROCESSES -gt 0 ]; then
            echo ""
            echo "运行中的队列进程:"
            ps aux | grep "queue:work" | grep -v grep
        fi
        
        # 检查 PID 文件
        if [ -f "storage/logs/default-queue.pid" ]; then
            DEFAULT_PID=$(cat storage/logs/default-queue.pid)
            if ps -p $DEFAULT_PID > /dev/null 2>&1; then
                echo "✓ 默认队列正在运行 (PID: $DEFAULT_PID)"
            else
                echo "✗ 默认队列 PID 文件存在但进程不运行"
            fi
        else
            echo "✗ 默认队列 PID 文件不存在"
        fi
        ;;
        
    restart)
        echo "==========================================="
        echo "    重启 Xbot 队列"
        echo "==========================================="
        
        $0 stop
        sleep 3
        $0 start
        ;;
        
    *)
        echo "使用方法: $0 {start|stop|status|restart}"
        echo ""
        echo "命令:"
        echo "  start   - 启动队列"
        echo "  stop    - 停止队列"
        echo "  status  - 查看队列状态"
        echo "  restart - 重启队列"
        exit 1
        ;;
esac