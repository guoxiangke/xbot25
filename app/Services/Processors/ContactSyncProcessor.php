<?php

namespace App\Services\Processors;

use App\Jobs\XbotContactHandleQueue;
use App\Models\WechatBot;
use App\Services\Managers\ConfigManager;
use Illuminate\Support\Facades\Log;

/**
 * 联系人同步处理器
 * 负责处理联系人数据同步到chatwoot
 */
class ContactSyncProcessor
{
    public function processContactSync(WechatBot $wechatBot, array $requestRawData, string $msgType): void
    {
        Log::info(__FUNCTION__, [
            'msgType' => $msgType,
            'wechatBot' => $wechatBot->wxid,
            'message' => '开始处理联系人同步'
        ]);

        // 获取配置管理器
        $configManager = new ConfigManager($wechatBot);

        // 处理不同类型的联系人同步消息
        match ($msgType) {
            'MT_DATA_FRIENDS_MSG' => $this->handleFriends($wechatBot, $requestRawData, $configManager),
            'MT_DATA_CHATROOMS_MSG' => $this->handleChatrooms($wechatBot, $requestRawData, $configManager),
            'MT_DATA_PUBLICS_MSG' => $this->handlePublicAccounts($wechatBot, $requestRawData, $configManager),
            'MT_DATA_WXID_MSG' => $this->handleSingleContact($wechatBot, $requestRawData, $configManager),
            'MT_DATA_CHATROOM_MEMBERS_MSG' => $this->handleChatroomMembers($wechatBot, $requestRawData, $configManager),
            default => Log::warning('未知的联系人同步消息类型', ['msgType' => $msgType])
        };
    }

    private function handleFriends(WechatBot $wechatBot, array $requestRawData, ConfigManager $configManager): void
    {
        // 联系人数据直接就是 $requestRawData 本身，过滤掉非数字键的元数据
        $friends = array_filter($requestRawData, function($key) {
            return is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);
        
        Log::info(__FUNCTION__, ['count' => count($friends), 'message' => '处理好友列表']);

        if (empty($friends)) {
            return;
        }

        // 存储到 WechatBot 的 meta 数据中
        $contactsData = $wechatBot->getMeta('contacts', []);
        foreach ($friends as $friend) {
            $wxid = $friend['wxid'] ?? '';
            if ($wxid) {
                $contactsData[$wxid] = $friend;
            }
        }
        $wechatBot->setMeta('contacts', $contactsData);

        // 异步处理 Chatwoot 同步 - 逐个分发联系人
        if ($configManager->isEnabled('chatwoot')) {
            foreach ($friends as $friend) {
                if (isset($friend['wxid'])) {
                    XbotContactHandleQueue::dispatch($wechatBot, $friend, 'friends');
                }
            }
        }
    }

    private function handleChatrooms(WechatBot $wechatBot, array $requestRawData, ConfigManager $configManager): void
    {
        // 群聊数据直接就是 $requestRawData 本身，过滤掉非数字键的元数据
        $chatrooms = array_filter($requestRawData, function($key) {
            return is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);
        
        Log::info(__FUNCTION__, ['count' => count($chatrooms), 'message' => '处理群聊列表']);

        if (empty($chatrooms)) {
            return;
        }

        // 存储到 WechatBot 的 meta 数据中
        $contactsData = $wechatBot->getMeta('contacts', []);
        foreach ($chatrooms as $chatroom) {
            $wxid = $chatroom['wxid'] ?? '';
            if ($wxid) {
                $contactsData[$wxid] = $chatroom;
            }
        }
        $wechatBot->setMeta('contacts', $contactsData);

        // 异步处理 Chatwoot 同步 - 逐个分发联系人
        if ($configManager->isEnabled('chatwoot')) {
            foreach ($chatrooms as $chatroom) {
                if (isset($chatroom['wxid'])) {
                    XbotContactHandleQueue::dispatch($wechatBot, $chatroom, 'chatrooms');
                }
            }
        }else {
            Log::info(__FUNCTION__, ['message' => 'Chatwoot 同步未开启']);
        }
    }

    private function handlePublicAccounts(WechatBot $wechatBot, array $requestRawData, ConfigManager $configManager): void
    {
        // 公众号数据直接就是 $requestRawData 本身，过滤掉非数字键的元数据
        $publicAccounts = array_filter($requestRawData, function($key) {
            return is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);
        
        Log::info(__FUNCTION__, ['count' => count($publicAccounts), 'message' => '处理公众号列表']);

        if (empty($publicAccounts)) {
            return;
        }

        // 存储到 WechatBot 的 meta 数据中
        $contactsData = $wechatBot->getMeta('contacts', []);
        foreach ($publicAccounts as $publicAccount) {
            $wxid = $publicAccount['wxid'] ?? '';
            if ($wxid) {
                $contactsData[$wxid] = $publicAccount;
            }
        }
        $wechatBot->setMeta('contacts', $contactsData);

        // 异步处理 Chatwoot 同步 - 逐个分发联系人
        if ($configManager->isEnabled('chatwoot')) {
            foreach ($publicAccounts as $publicAccount) {
                if (isset($publicAccount['wxid'])) {
                    XbotContactHandleQueue::dispatch($wechatBot, $publicAccount, 'public_accounts');
                }
            }
        }
    }

    private function handleSingleContact(WechatBot $wechatBot, array $requestRawData, ConfigManager $configManager): void
    {
        // 修复数据获取逻辑：MessageDispatcher已经传递了data字段的内容
        $contact = $requestRawData;
        $wxid = $contact['wxid'] ?? '';

        if (!$wxid) {
            Log::warning('联系人数据缺少wxid', ['contact' => $contact]);
            return;
        }

        Log::info(__FUNCTION__, ['wxid' => $wxid, 'message' => '处理单个联系人']);

        // 存储到 WechatBot 的 meta 数据中
        $contactsData = $wechatBot->getMeta('contacts', []);
        $contactsData[$wxid] = $contact;
        $wechatBot->setMeta('contacts', $contactsData);

        // 异步处理 Chatwoot 同步
        if ($configManager->isEnabled('chatwoot')) {
            XbotContactHandleQueue::dispatch($wechatBot, $contact, 'single_contact');
        }
    }

    private function handleChatroomMembers(WechatBot $wechatBot, array $requestRawData, ConfigManager $configManager): void
{
    // 修复数据获取逻辑：MessageDispatcher已经传递了data字段的内容，不需要再次访问['data']
    $members = $requestRawData['member_list'] ?? [];
    // 修复：使用 group_wxid 而不是 room_wxid
    $roomWxid = $requestRawData['group_wxid'] ?? $requestRawData['room_wxid'] ?? '';

    Log::info(__FUNCTION__, [
        'room_wxid' => $roomWxid,
        'count' => count($members),
        'message' => '处理群成员列表'
    ]);

    if (empty($members) || !$roomWxid) {
        return;
    }

    // 存储到 WechatBot 的 meta 数据中
    $contactsData = $wechatBot->getMeta('contacts', []);
    foreach ($members as $member) {
        $wxid = $member['wxid'] ?? '';
        if ($wxid) {
            // 为群成员添加所属群信息
            $member['room_wxid'] = $roomWxid;
            $contactsData[$wxid] = $member;
        }
    }
    $wechatBot->setMeta('contacts', $contactsData);

    // 异步处理 Chatwoot 同步 - 逐个分发群成员
    if ($configManager->isEnabled('chatwoot')) {
        foreach ($members as $member) {
            if (isset($member['wxid'])) {
                XbotContactHandleQueue::dispatch($wechatBot, $member, 'chatroom_members');
            }
        }
    }
}
}