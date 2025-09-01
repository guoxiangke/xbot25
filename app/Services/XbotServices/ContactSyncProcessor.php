<?php

namespace App\Services\XbotServices;

use App\Jobs\XbotContactHandleQueue;
use App\Models\WechatBot;
use Illuminate\Support\Facades\Log;

/**
 * Xbot 联系人同步处理器
 * 负责处理联系人数据同步到chatwoot
 */
class ContactSyncProcessor
{
    public function processContactSync(WechatBot $wechatBot, array $requestRawData, string $msgType): void
    {
        Log::info('开始处理联系人同步', [
            'msgType' => $msgType,
            'wechatBot' => $wechatBot->wxid,
            'data_count' => is_array($requestRawData) ? count($requestRawData) : 0
        ]);

        // 特殊处理群成员消息
        if ($msgType === 'MT_DATA_CHATROOM_MEMBERS_MSG') {
            $this->processChatroomMembers($wechatBot, $requestRawData);
            return;
        }

        // 特殊处理单个联系人消息
        if ($msgType === 'MT_DATA_WXID_MSG') {
            $contactData = $requestRawData['data'] ?? $requestRawData;
            if (isset($contactData['wxid'])) {
                $wechatBot->handleContacts([$contactData]);

                // 分发到Chatwoot队列
                $chatwootEnabled = $wechatBot->getMeta('chatwoot_enabled', 1);
                if ($chatwootEnabled) {
                    $contactType = $contactData['type'] ?? 0;
                    $label = WechatBot::getContactTypeLabel($contactType);
                    XbotContactHandleQueue::dispatch($wechatBot, $contactData, $label);
                    Log::info('已分发单个联系人到队列', [
                        'msgType' => $msgType,
                        'wxid' => $contactData['wxid'],
                        'nickname' => $contactData['nickname'] ?? ''
                    ]);
                }
            }
            return;
        }

        // 返回 以 wxid 为 key 的联系人数组
        $wechatBot->handleContacts($requestRawData);

        // 如果是群列表同步，自动获取每个群的成员信息
        if ($msgType === 'MT_DATA_CHATROOMS_MSG') {
            $this->requestChatroomMembersInfo($wechatBot, $requestRawData);
        }

        $chatwootEnabled = $wechatBot->getMeta('chatwoot_enabled', 1);
        if(!$chatwootEnabled) {
            Log::info('Chatwoot未启用，跳过队列处理', ['wechatBot' => $wechatBot->wxid]);
            return;
        }

        $contacts = $requestRawData;
        $label = WechatBot::getContactTypeLabelByMsgType($msgType);

        // 确保 $contacts 是数组且每个 $contact 也是数组
        if (is_array($contacts)) {
            $dispatchedCount = 0;
            foreach ($contacts as $contact) {
                if (is_array($contact)) {
                    XbotContactHandleQueue::dispatch($wechatBot, $contact, $label);
                    $dispatchedCount++;
                }
            }
            Log::info("已分发{$dispatchedCount}个联系人到队列", [
                'msgType' => $msgType,
                'label' => $label,
                'total_contacts' => count($contacts)
            ]);
        } else {
            Log::warning('联系人数据不是数组格式', [
                'msgType' => $msgType,
                'data_type' => gettype($contacts)
            ]);
        }

        Log::info('Contact sync processed', [
            'msgType' => $msgType,
            'contact_count' => is_array($contacts) ? count($contacts) : 0,
            'label' => $label
        ]);
    }

    /**
     * 处理群成员信息同步
     */
    private function processChatroomMembers(WechatBot $wechatBot, array $requestRawData): void
    {
        // 尝试不同的数据结构解析方式
        $memberList = null;
        $groupWxid = '';

        // 方式1: 标准格式 - data.member_list
        if (isset($requestRawData['data']['member_list'])) {
            $data = $requestRawData['data'];
            $memberList = $data['member_list'];
            $groupWxid = $data['group_wxid'] ?? '';
        }
        // 方式2: 直接在根级别
        elseif (isset($requestRawData['member_list'])) {
            $memberList = $requestRawData['member_list'];
            $groupWxid = $requestRawData['group_wxid'] ?? '';
        }
        // 方式3: 整个数组就是member_list
        elseif (is_array($requestRawData) && !empty($requestRawData)) {
            // 检查第一个元素是否包含wxid（判断是否为member格式）
            $firstItem = reset($requestRawData);
            if (is_array($firstItem) && isset($firstItem['wxid'])) {
                $memberList = $requestRawData;
                $groupWxid = '未知群'; // 无法确定群ID
            }
        }

        if (empty($memberList) || !is_array($memberList)) {
            Log::warning('群成员数据为空或格式错误', [
                'group_wxid' => $groupWxid,
                'data_type' => gettype($memberList),
                'raw_data_sample' => array_slice($requestRawData, 0, 2, true)
            ]);
            return;
        }

        Log::info('开始处理群成员信息', [
            'group_wxid' => $groupWxid,
            'member_count' => count($memberList),
            'wechatBot' => $wechatBot->wxid
        ]);

        // 获取现有的联系人数据
        $existingContacts = $wechatBot->getMeta('contacts', []);
        $newContacts = [];
        $updatedContacts = [];
        $contactsNeedChatwootSync = [];

        // 处理每个群成员
        foreach ($memberList as $member) {
            if (!is_array($member) || empty($member['wxid'])) {
                continue;
            }

            $wxid = $member['wxid'];

            // 检查是否已存在该联系人
            if (!isset($existingContacts[$wxid])) {
                // 新联系人：添加type标识为群成员
                $member['type'] = 4; // 4 = 群成员
                $newContacts[$wxid] = $member;
                $updatedContacts[$wxid] = $member;
                $contactsNeedChatwootSync[$wxid] = $member;
            } else {
                // 已存在的联系人：用新数据更新，但保持原有的重要字段
                $existingContact = $existingContacts[$wxid];
                $updatedContact = array_merge($existingContact, $member);
                $updatedContacts[$wxid] = $updatedContact;

                // 检查头像是否有更新，如果有则需要同步到Chatwoot
                $oldAvatar = $existingContact['avatar'] ?? '';
                $newAvatar = $member['avatar'] ?? '';
                if ($oldAvatar !== $newAvatar && !empty($newAvatar)) {
                    $contactsNeedChatwootSync[$wxid] = $updatedContact;
                }

                // 如果已经是好友（type=1），则从Chatwoot同步队列中移除，避免重复标签
                if (($existingContact['type'] ?? 0) == 1) {
                    unset($contactsNeedChatwootSync[$wxid]);
                }
            }
        }

        // 如果有新的或更新的联系人，保存到metadata
        if (!empty($updatedContacts)) {
            $allContacts = array_merge($existingContacts, $updatedContacts);
            $wechatBot->handleContacts($allContacts);

            Log::info('群成员联系人信息已更新', [
                'group_wxid' => $groupWxid,
                'new_contacts' => count($newContacts),
                'total_members' => count($memberList)
            ]);
        }

        // 检查Chatwoot是否启用
        $chatwootEnabled = $wechatBot->getMeta('chatwoot_enabled', 1);
        if (!$chatwootEnabled) {
            Log::info('Chatwoot未启用，跳过群成员队列处理', ['wechatBot' => $wechatBot->wxid]);
            return;
        }

        // 将需要Chatwoot同步的联系人添加到队列（包括新联系人和头像更新的联系人）
        if (!empty($contactsNeedChatwootSync)) {
            $dispatchedCount = 0;
            foreach ($contactsNeedChatwootSync as $contact) {
                XbotContactHandleQueue::dispatch($wechatBot, $contact, '群联系人');
                $dispatchedCount++;
            }

            Log::info("已分发{$dispatchedCount}个群成员到Chatwoot队列", [
                'group_wxid' => $groupWxid,
                'new_contacts' => count($newContacts),
                'contacts_need_sync' => count($contactsNeedChatwootSync),
                'total_members' => count($memberList)
            ]);
        } else {
            Log::info('无群成员需要同步到Chatwoot', [
                'group_wxid' => $groupWxid,
                'new_contacts' => count($newContacts),
                'total_members' => count($memberList)
            ]);
        }
    }

    /**
     * 为每个群自动请求成员信息
     */
    private function requestChatroomMembersInfo(WechatBot $wechatBot, array $chatroomData): void
    {
        if (!is_array($chatroomData)) {
            return;
        }

        $xbot = $wechatBot->xbot();
        $requestCount = 0;

        foreach ($chatroomData as $chatroom) {
            if (is_array($chatroom) && isset($chatroom['wxid'])) {
                $roomWxid = $chatroom['wxid'];

                // 调用获取群成员API
                $xbot->getChatroomMembers($roomWxid);
                $requestCount++;

                Log::debug('请求群成员信息', [
                    'room_wxid' => $roomWxid,
                    'room_name' => $chatroom['nickname'] ?? '未知'
                ]);
            }
        }

        Log::info("已请求{$requestCount}个群的成员信息", [
            'wechatBot' => $wechatBot->wxid,
            'total_chatrooms' => count($chatroomData)
        ]);
    }
}
