<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

class TestXbot
{
    private array $calls = [];

    /**
     * 模拟发送文本消息的方法
     */
    public function sendTextMessage(string $target, string $message): bool
    {
        $this->calls[] = [
            'method' => 'sendTextMessage',
            'args' => [$target, $message]
        ];
        
        // 使用HTTP客户端发送模拟请求，这样测试可以验证请求是否被发送
        // 修复：匹配真实XbotClient的数据结构
        Http::post('http://localhost:8001/send_text', [
            'client_id' => 1,
            'type' => 'MT_SEND_TEXTMSG',
            'data' => [
                'to_wxid' => $target,
                'content' => $message
            ]
        ]);
        
        return true;
    }

    /**
     * 模拟发送音乐的方法
     */
    public function sendMusic(string $target, string $url, string $title = '', string $description = '', ?string $image = null, ?string $lrc = null): bool
    {
        $this->calls[] = [
            'method' => 'sendMusic',
            'args' => [$target, $url, $title, $description, $image, $lrc]
        ];
        
        return true;
    }

    /**
     * 模拟发送链接的方法
     */
    public function sendLink(string $target, string $url, string $image = '', string $title = '', string $description = ''): bool
    {
        $this->calls[] = [
            'method' => 'sendLink',
            'args' => [$target, $url, $image, $title, $description]
        ];
        
        return true;
    }

    /**
     * 模拟发送图片的方法
     */
    public function sendImageByUrl(string $target, string $url): bool
    {
        $this->calls[] = [
            'method' => 'sendImageByUrl',
            'args' => [$target, $url]
        ];
        
        return true;
    }

    /**
     * 获取所有调用记录
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * 清除调用记录
     */
    public function clearCalls(): void
    {
        $this->calls = [];
    }
    
    /**
     * 模拟获取好友列表
     */
    public function getFriendsList(): bool
    {
        Http::post('http://localhost:8001/get_friends_list', [
            'type' => 'MT_DATA_FRIENDS_MSG'
        ]);
        
        return true;
    }
    
    /**
     * 模拟获取群列表
     */
    public function getChatroomsList(): bool
    {
        Http::post('http://localhost:8001/get_chatrooms_list', [
            'type' => 'MT_DATA_CHATROOMS_MSG'
        ]);
        
        return true;
    }
    
    /**
     * 模拟获取公众号列表
     */
    public function getPublicAccountsList(): bool
    {
        Http::post('http://localhost:8001/get_public_accounts_list', [
            'type' => 'MT_DATA_PUBLIC_ACCOUNTS_MSG'
        ]);
        
        return true;
    }
    
    /**
     * 模拟获取群成员信息
     */
    public function getChatroomMembers(string $roomWxid): bool
    {
        Http::post('http://localhost:8001/get_chatroom_members', [
            'type' => 'MT_DATA_CHATROOM_MEMBERS_MSG',
            'room_wxid' => $roomWxid
        ]);
        
        return true;
    }
    
    /**
     * 通用的魔术方法，模拟其他可能的方法调用
     */
    public function __call($name, $arguments)
    {
        // 默认情况下，所有方法调用都返回true
        return true;
    }
}