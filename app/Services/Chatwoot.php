<?php

namespace App\Services;

use App\Models\WechatBot;
use App\Services\XbotConfigManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Chatwoot
{
    public $baseUrl;
    public $api_version = 'api/v1';
    public $http;
    public $inboxId;
    public $accountId;
    public $token;

    function __construct(WechatBot $wechatBot) {
        $configManager = new XbotConfigManager($wechatBot);
        $this->accountId = $configManager->getChatwootConfig('chatwoot_account_id');
        $this->inboxId = $configManager->getChatwootConfig('chatwoot_inbox_id');
        $this->token = $configManager->getChatwootConfig('chatwoot_token');
        $this->baseUrl = config('services.chatwoot.base_url');

        $headers = [
            'Content-Type' => 'application/json',
            'api_access_token' => $this->token,
            'Authorization' => 'Bearer ' . $this->token // Chatwoot v4.x 支持
        ];
        $this->http = Http::withHeaders($headers);
    }

    public function searchContact(string $wxid)
    {
        usleep(500000);//避免请求过快被chatwoot拒绝
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/search";
        $body = [
            'q' => $wxid,
            'sort' => "phone_number",
            'page' => 1,
        ];
        $response = $this->http->get($url, $body)->json();
        $response['payload'][0]??Log::error(__FUNCTION__, [$wxid, $response]);
        return $response['payload'][0] ?? false;
    }

    public function getContactById(int $id)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$id}";
        return $this->http->get($url)->json()['payload'] ?? null;
    }


    public function saveContact(array $contact)
    {
        $wxid = $contact['wxid'];
        $email = $this->generateContactEmail($contact);
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts";
        $name = $contact['remark'] ?? $contact['nickname'] ?? $wxid;
        $isRoom = !empty($contact['room_wxid']);
        if ($isRoom)  $name = "群:" . $name;
        $avatarUrl = str_replace('http://','https://', $contact['avatar'] ?? '');
        $sex = match ($contact['sex'] ?? 0) {
            0 => '未知',
            1 => '男',
            2 => '女',
            default => '未知'
        };
        $body = [
            "inbox_id" => $this->inboxId,
            "name" => $name,
            "email" => $email,
            "avatar_url" => $avatarUrl,
            "identifier" => $wxid,
            // 用于存储用户自定义的属性
            "custom_attributes" => [
                'wxid' =>$wxid,
                "avatar_url" => $avatarUrl,
                "sex" => $sex,
                "nickname" => $contact['nickname'] ?? '',
                "country" => $contact['country'] ?? '',
                "province" => $contact['province'] ?? '',
                "remark" => $contact['remark'] ?? '',
                "scene" => $contact['scene'] ?? '', // 好友来源scene字段
            ],
            // 用于存储系统预定义的属性
            "additional_attributes" => [
                "city" => $contact['city'] ?? '',
            ],
        ];

        $res = $this->http->post($url, $body)->json();
        $res['payload']['contact']??Log::error(__FUNCTION__, [$wxid, $res]);
        // ["WANGNAN_8110",{"message":"Email has already been taken, Identifier has already been taken","attributes":["email","identifier"]}]
        return $res['payload']['contact']??null;
    }

    public function updateContactAvatar(array $contact)
    {
        $contactId = $contact['id'];
        $avatarUrl = $contact['custom_attributes']['avatar_url'];
        $avatarUrl = str_replace('http://','https://', $avatarUrl);
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contactId}";
        $body = [
            "avatar_url" => $avatarUrl,
        ];
        return $this->http->put($url, $body)->json();
    }

    public function updateContactAvatarById($contactId, $avatarUrl)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contactId}";
        $avatarUrl = str_replace('http://','https://', $avatarUrl);
        $body = [
            "avatar_url" => $avatarUrl,
        ];
        return $this->http->put($url, $body)->json();
    }

    public function updateContactName($contactId, $name)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contactId}";
        $body = [
            "name" => $name,
        ];
        return $this->http->put($url, $body)->json();
    }

    public function getConversation($contact)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/conversations";
        $sourceId = $contact['contact_inboxes'][0]['source_id'];
        $body = [
            "source_id" => $sourceId,
            "inbox_id" => $this->inboxId,
        ];
        return $this->http->post($url, $body)->json();
    }


    /**
     * 以联系人身份发送消息
     * $isNewConversation = false: 接收消息，传到chatwoot
     * $isNewConversation = true: 第一次创建对话，不发消息给微信用户，只记录到chatwoot
     */
    public function sendMessageAsContact($contact, $content = 'hi', $isNewConversation = false)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contact['id']}/conversations";
        $conversation = $this->http->get($url)->json()['payload'][0] ?? $this->getConversation($contact);
        
        // 只有在不是新对话时才发送消息（根据注释逻辑）
        if (!$isNewConversation) {
            $this->sendMessageToConversation($conversation, $content, $isNewConversation);
        }
    }

    public function sendMessageToConversation($conversation, $content = "hi", $isNewConversation = false)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/conversations/{$conversation['id']}/messages";
        $body = [
            'content' => $content,
            'message_type' => $isNewConversation ? "outgoing" : "incoming",
        ];

        $response = $this->http->post($url, $body);
        return $response;
    }

    /**
     * 以客服身份发送消息到对话
     * 用于机器人发送的消息，使用客服身份而不是联系人身份
     */
    public function sendMessageAsAgent($conversation, $content = "hi")
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/conversations/{$conversation['id']}/messages";
        $body = [
            'content' => $content,
            'message_type' => 'outgoing', // 总是作为客服发送的消息
            'private' => false,
            // 添加source_id标识，用于webhook循环检测
            'source_id' => 'xbot_agent',
        ];

        $response = $this->http->post($url, $body);

        // 检查响应状态
        if ($response->failed()) {
            Log::error('Failed to send message as agent', [
                'status' => $response->status(),
                'response' => $response->json(),
                'url' => $url,
                'body' => $body
            ]);
        }

        return $response;
    }

    /**
     * 获取当前agent信息
     * 使用api_access_token获取当前认证的agent
     */
    public function getCurrentAgent()
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/agents/me";
        $response = $this->http->get($url)->json();
        return $response['payload'] ?? null;
    }

    /**
     * 通过联系人获取或创建对话，然后以客服身份发送消息
     * 这是专门为机器人发送消息设计的方法
     */
    public function sendMessageAsAgentToContact($contact, $content = 'hi')
    {
        // 获取或创建对话
        $conversation = $this->getOrCreateConversation($contact);

        if (!$conversation) {
            Log::error('Failed to get or create conversation for contact', ['contact_id' => $contact['id']]);
            return false;
        }

        // 以客服身份发送消息
        return $this->sendMessageAsAgent($conversation, $content);
    }

    /**
     * 获取或创建对话
     * 确保同一个联系人的所有消息都在同一个对话中
     */
    public function getOrCreateConversation($contact)
    {
        $contactId = $contact['id'];
        $sourceId = $contact['contact_inboxes'][0]['source_id'] ?? $contactId;

        // 首先尝试查找现有的对话
        $existingConversation = $this->findExistingConversation($contactId);
        if ($existingConversation) {
            return $existingConversation;
        }

        // 如果没有找到现有对话，则创建新对话
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/conversations";
        $body = [
            "source_id" => $sourceId,
            "inbox_id" => $this->inboxId,
        ];

        $response = $this->http->post($url, $body)->json();
        return $response['payload'] ?? null;
    }

    /**
     * 查找现有的对话
     */
    public function findExistingConversation($contactId)
    {
        // 首先尝试通过联系人获取对话
        $contactConversations = $this->getContactConversations($contactId);
        if (!empty($contactConversations)) {
            // 返回第一个活跃的对话，或者第一个对话
            foreach ($contactConversations as $conversation) {
                if ($conversation['status'] === 'open') {
                    return $conversation;
                }
            }
            return $contactConversations[0];
        }

        return null;
    }

    /**
     * 获取联系人的所有对话
     */
    public function getContactConversations($contactId)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contactId}/conversations";
        $response = $this->http->get($url)->json();
        return $response['payload'] ?? [];
    }

    public function getLabels()
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/labels";
        return $this->http->get($url)->json()['payload'] ?? null;
    }

    public function getLabelsByContact($contact)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contact['id']}/labels";
        return $this->http->get($url)->json()['payload'] ?? null;
    }

    /**
     * 生成联系人邮箱
     */
    public function generateContactEmail(array $contact): string
    {
        // 公众号（gh_开头）和群聊（@chatroom结尾）
        // 普通联系人使用邮箱格式
        $wxid = $contact['wxid'] ?? '';
        return match (true) {
            str_ends_with($wxid, '@chatroom') => $wxid,
            str_starts_with($wxid, 'gh_') => "{$wxid}@gh",
            default => "{$wxid}@wx",
        };
    }

    public function setLabel($contactId, $label)
    {
        $this->getOrCreateALabel($label);//确保标签存在
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/contacts/{$contactId}/labels";
        $body = [
            'labels' => [$label],
        ];
        return $this->http->post($url, $body)->json()['payload'] ?? null;
    }

    public function getOrCreateALabel($label)
    {
        $url = "{$this->baseUrl}/{$this->api_version}/accounts/{$this->accountId}/labels";
        $body = [
            'title' => $label,
            'description' => 'Created by System, You can change the color and description.',
            'color' => "#666666",
            'show_on_sidebar' => true,
        ];
        return $this->http->post($url, $body)->json() ?? ['null'];
    }
}
