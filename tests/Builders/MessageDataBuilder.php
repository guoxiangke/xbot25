<?php

namespace Tests\Builders;

use Tests\Datasets\XbotMessageDataset;

class MessageDataBuilder
{
    private array $data;
    private string $msgType;

    public function __construct(string $msgType = 'MT_RECV_TEXT_MSG')
    {
        $this->msgType = $msgType;
        $this->data = $this->getBaseDataForType($msgType);
    }

    /**
     * 创建文本消息构建器
     */
    public static function textMessage(): self
    {
        return new self('MT_RECV_TEXT_MSG');
    }

    /**
     * 创建群消息构建器
     */
    public static function roomMessage(): self
    {
        $builder = new self('MT_RECV_TEXT_MSG');
        return $builder->inRoom('56878503348@chatroom');
    }

    /**
     * 创建图片消息构建器
     */
    public static function pictureMessage(): self
    {
        return new self('MT_RECV_PICTURE_MSG');
    }

    /**
     * 创建语音消息构建器
     */
    public static function voiceMessage(): self
    {
        return new self('MT_RECV_VOICE_MSG');
    }

    /**
     * 创建系统消息构建器
     */
    public static function systemMessage(): self
    {
        return new self('MT_RECV_SYSTEM_MSG');
    }

    /**
     * 创建登录消息构建器
     */
    public static function loginMessage(): self
    {
        return new self('MT_USER_LOGIN');
    }

    /**
     * 创建联系人数据消息构建器
     */
    public static function contactDataMessage(): self
    {
        return new self('MT_DATA_FRIENDS_MSG');
    }

    /**
     * 设置消息内容
     */
    public function withMessage(string $message): self
    {
        $this->data['data']['msg'] = $message;
        return $this;
    }

    /**
     * 设置发送者
     */
    public function from(string $fromWxid): self
    {
        $this->data['data']['from_wxid'] = $fromWxid;
        return $this;
    }

    /**
     * 设置接收者
     */
    public function to(string $toWxid): self
    {
        $this->data['data']['to_wxid'] = $toWxid;
        return $this;
    }

    /**
     * 设置为群消息
     */
    public function inRoom(string $roomWxid): self
    {
        $this->data['data']['room_wxid'] = $roomWxid;
        $this->data['data']['to_wxid'] = $roomWxid;
        return $this;
    }

    /**
     * 设置@用户列表
     */
    public function withAtUsers(array $atUsers): self
    {
        $this->data['data']['at_user_list'] = $atUsers;
        return $this;
    }

    /**
     * 设置客户端ID
     */
    public function withClientId(int $clientId): self
    {
        $this->data['client_id'] = $clientId;
        return $this;
    }

    /**
     * 设置消息ID
     */
    public function withMsgId(string $msgId): self
    {
        $this->data['data']['msgid'] = $msgId;
        return $this;
    }

    /**
     * 设置时间戳
     */
    public function withTimestamp(int $timestamp): self
    {
        $this->data['data']['timestamp'] = $timestamp;
        return $this;
    }

    /**
     * 设置为当前时间
     */
    public function withCurrentTime(): self
    {
        return $this->withTimestamp(time());
    }

    /**
     * 设置为PC端消息
     */
    public function fromPC(): self
    {
        $this->data['data']['is_pc'] = 1;
        return $this;
    }

    /**
     * 设置为移动端消息
     */
    public function fromMobile(): self
    {
        $this->data['data']['is_pc'] = 0;
        return $this;
    }

    /**
     * 设置微信消息类型
     */
    public function withWxType(int $wxType): self
    {
        $this->data['data']['wx_type'] = $wxType;
        return $this;
    }

    /**
     * 设置图片文件路径
     */
    public function withImagePath(string $imagePath, string $thumbPath = null): self
    {
        $this->data['data']['image'] = $imagePath;
        if ($thumbPath) {
            $this->data['data']['image_thumb'] = $thumbPath;
        }
        return $this;
    }

    /**
     * 设置语音文件路径
     */
    public function withVoicePath(string $voicePath): self
    {
        $this->data['data']['voice'] = $voicePath;
        return $this;
    }

    /**
     * 设置原始消息XML
     */
    public function withRawMsg(string $rawMsg): self
    {
        $this->data['data']['raw_msg'] = $rawMsg;
        return $this;
    }

    /**
     * 添加自定义数据字段
     */
    public function withCustomData(string $key, $value): self
    {
        $this->data['data'][$key] = $value;
        return $this;
    }

    /**
     * 批量设置数据字段
     */
    public function withData(array $data): self
    {
        $this->data['data'] = array_merge($this->data['data'] ?? [], $data);
        return $this;
    }

    /**
     * 设置根级别字段
     */
    public function withField(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 创建机器人发送的消息
     */
    public function asBotMessage(string $botWxid): self
    {
        return $this->from($botWxid)->fromPC();
    }

    /**
     * 创建用户发送的消息
     */
    public function asUserMessage(string $userWxid): self
    {
        return $this->from($userWxid)->fromMobile();
    }

    /**
     * 生成随机的消息数据
     */
    public function withRandomData(): self
    {
        $this->data['data']['msgid'] = XbotMessageDataset::randomMsgid();
        $this->data['data']['timestamp'] = XbotMessageDataset::currentTimestamp();
        
        if (!isset($this->data['data']['from_wxid'])) {
            $this->data['data']['from_wxid'] = XbotMessageDataset::randomWxid();
        }
        
        if (!isset($this->data['data']['to_wxid'])) {
            $this->data['data']['to_wxid'] = XbotMessageDataset::randomWxid();
        }
        
        return $this;
    }

    /**
     * 构建最终的消息数据
     */
    public function build(): array
    {
        return $this->data;
    }

    /**
     * 构建为POST请求格式
     */
    public function buildAsPostRequest(): array
    {
        return XbotMessageDataset::buildPostRequestData($this->data);
    }

    /**
     * 根据消息类型获取基础数据结构
     */
    private function getBaseDataForType(string $msgType): array
    {
        return match ($msgType) {
            'MT_RECV_TEXT_MSG' => XbotMessageDataset::textMessage(),
            'MT_RECV_PICTURE_MSG' => XbotMessageDataset::pictureMessage(),
            'MT_RECV_VOICE_MSG' => [
                'type' => 'MT_RECV_VOICE_MSG',
                'client_id' => 5,
                'data' => [
                    'from_wxid' => 'wxid_user123',
                    'to_wxid' => 'wxid_bot123',
                    'voice' => 'C:\\test\\voice.silk',
                    'voice_len' => 3000,
                    'msgid' => XbotMessageDataset::randomMsgid(),
                    'timestamp' => time(),
                    'room_wxid' => '',
                    'wx_type' => 34
                ]
            ],
            'MT_RECV_SYSTEM_MSG' => XbotMessageDataset::systemMessage(),
            'MT_USER_LOGIN' => XbotMessageDataset::userLogin(),
            'MT_DATA_FRIENDS_MSG' => XbotMessageDataset::contactDataMessage(),
            'MT_DATA_CHATROOM_MEMBERS_MSG' => XbotMessageDataset::chatroomMembersMessage(),
            'MT_RECV_OTHER_APP_MSG' => XbotMessageDataset::otherAppMessage(),
            default => XbotMessageDataset::textMessage()
        };
    }

    /**
     * 创建多条消息的数组
     */
    public static function createMultiple(int $count, callable $configurator = null): array
    {
        $messages = [];
        
        for ($i = 0; $i < $count; $i++) {
            $builder = new self();
            $builder->withRandomData();
            
            if ($configurator) {
                $configurator($builder, $i);
            }
            
            $messages[] = $builder->build();
        }
        
        return $messages;
    }

    /**
     * 创建对话消息序列
     */
    public static function createConversation(string $userWxid, string $botWxid, array $messages): array
    {
        $conversation = [];
        
        foreach ($messages as $index => $messageText) {
            $isUserMessage = $index % 2 === 0;
            $builder = self::textMessage()
                ->withMessage($messageText)
                ->withRandomData()
                ->withTimestamp(time() + $index * 10); // 每条消息间隔10秒
                
            if ($isUserMessage) {
                $builder->asUserMessage($userWxid)->to($botWxid);
            } else {
                $builder->asBotMessage($botWxid)->to($userWxid);
            }
            
            $conversation[] = $builder->build();
        }
        
        return $conversation;
    }

    /**
     * 创建群对话消息序列
     */
    public static function createRoomConversation(
        string $roomWxid, 
        array $participants, 
        array $messages
    ): array {
        $conversation = [];
        
        foreach ($messages as $index => $messageText) {
            $participantIndex = $index % count($participants);
            $fromWxid = $participants[$participantIndex];
            
            $builder = self::roomMessage()
                ->withMessage($messageText)
                ->inRoom($roomWxid)
                ->from($fromWxid)
                ->withRandomData()
                ->withTimestamp(time() + $index * 15); // 群消息间隔15秒
                
            $conversation[] = $builder->build();
        }
        
        return $conversation;
    }
}