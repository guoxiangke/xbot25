<?php

namespace Tests\Datasets;

class XbotMessageDataset
{
    /**
     * 基于真实日志的文本消息数据模板
     */
    public static function textMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => [],
                'from_wxid' => 'wxid_user123',
                'is_pc' => 0,
                'msg' => '测试消息',
                'msgid' => '8110349052485517268',
                'room_wxid' => '',
                'timestamp' => 1757652412,
                'to_wxid' => 'wxid_bot123',
                'wx_type' => 1
            ]
        ], $overrides);
    }

    /**
     * 群聊文本消息
     */
    public static function roomTextMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => [],
                'from_wxid' => 'wxid_user123',
                'is_pc' => 0,
                'msg' => '群聊测试消息',
                'msgid' => '8110349052485517269',
                'room_wxid' => '56878503348@chatroom',
                'timestamp' => 1757652412,
                'to_wxid' => '56878503348@chatroom',
                'wx_type' => 1
            ]
        ], $overrides);
    }

    /**
     * 用户登录消息
     */
    public static function userLogin(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_USER_LOGIN',
            'client_id' => 5,
            'data' => [
                'account' => '',
                'avatar' => 'https://mmhead.c2c.wechat.com/mmhead/ver_1/test.jpg',
                'nickname' => 'AI助理',
                'phone' => '+16268881668',
                'pid' => 14204,
                'unread_msg_count' => 0,
                'wx_user_dir' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_test\\',
                'wxid' => 'wxid_test123'
            ]
        ], $overrides);
    }

    /**
     * 图片消息
     */
    public static function pictureMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_PICTURE_MSG',
            'client_id' => 5,
            'data' => [
                'from_wxid' => 'wxid_user123',
                'image' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_test\\FileStorage\\Image\\2025-09\\test.dat',
                'image_thumb' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_test\\FileStorage\\Image\\Thumb\\2025-09\\test_t.dat',
                'is_pc' => 0,
                'msgid' => '496474829225181909',
                'raw_msg' => '<?xml version="1.0"?><msg><img aeskey="test" encryver="1" length="42398" md5="test"></img></msg>',
                'room_wxid' => '',
                'timestamp' => 1757655019,
                'to_wxid' => 'wxid_bot123',
                'wx_type' => 3,
                'xor_key' => 53
            ]
        ], $overrides);
    }

    /**
     * 其他应用消息（如引用回复）
     */
    public static function otherAppMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_OTHER_APP_MSG',
            'client_id' => 5,
            'data' => [
                'from_wxid' => 'wxid_user123',
                'is_pc' => 0,
                'msgid' => '282007590778292508',
                'raw_msg' => '<?xml version="1.0"?><msg><appmsg appid="" sdkver="0"><title>已改</title><type>57</type></appmsg></msg>',
                'room_wxid' => '',
                'timestamp' => 1757654464,
                'to_wxid' => 'wxid_bot123',
                'wx_sub_type' => 57,
                'wx_type' => 49
            ]
        ], $overrides);
    }

    /**
     * 机器人发送的消息
     */
    public static function botSentMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => [],
                'from_wxid' => 'wxid_bot123', // 机器人自己发送
                'is_pc' => 1,
                'msg' => '机器人回复消息',
                'msgid' => '8110349052485517270',
                'room_wxid' => '',
                'timestamp' => 1757652413,
                'to_wxid' => 'wxid_user123',
                'wx_type' => 1
            ]
        ], $overrides);
    }

    /**
     * @用户的群消息
     */
    public static function atMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => ['wxid_bot123'],
                'from_wxid' => 'wxid_user123',
                'is_pc' => 0,
                'msg' => '@AI助理 你好',
                'msgid' => '8110349052485517271',
                'room_wxid' => '56878503348@chatroom',
                'timestamp' => 1757652414,
                'to_wxid' => '56878503348@chatroom',
                'wx_type' => 1
            ]
        ], $overrides);
    }

    /**
     * 系统消息（如群成员变化）
     */
    public static function systemMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_SYSTEM_MSG',
            'client_id' => 5,
            'data' => [
                'from_wxid' => '',
                'msg' => '"张三"邀请"李四"加入了群聊',
                'msgid' => '8110349052485517272',
                'room_wxid' => '56878503348@chatroom',
                'timestamp' => 1757652415,
                'to_wxid' => '56878503348@chatroom',
                'wx_type' => 10000
            ]
        ], $overrides);
    }

    /**
     * 联系人数据同步消息
     */
    public static function contactDataMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_DATA_FRIENDS_MSG',
            'client_id' => 5,
            'data' => [
                [
                    'wxid' => 'wxid_friend1',
                    'nickname' => '好友一',
                    'remark' => '备注名',
                    'avatar' => 'https://wx.qlogo.cn/mmhead/test1.jpg',
                    'type' => 1
                ],
                [
                    'wxid' => 'wxid_friend2',
                    'nickname' => '好友二',
                    'remark' => '',
                    'avatar' => 'https://wx.qlogo.cn/mmhead/test2.jpg',
                    'type' => 1
                ]
            ]
        ], $overrides);
    }

    /**
     * 单个联系人数据消息
     */
    public static function singleContactMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_DATA_WXID_MSG',
            'client_id' => 5,
            'data' => [
                'wxid' => 'wxid_contact123',
                'nickname' => '联系人昵称',
                'remark' => '联系人备注',
                'avatar' => 'https://wx.qlogo.cn/mmhead/contact123.jpg',
                'type' => 1
            ]
        ], $overrides);
    }

    /**
     * 群成员数据消息
     */
    public static function chatroomMembersMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_DATA_CHATROOM_MEMBERS_MSG',
            'client_id' => 5,
            'data' => [
                'group_wxid' => '56878503348@chatroom',
                'member_list' => [
                    [
                        'nickname' => '群成员一',
                        'wxid' => 'wxid_member1'
                    ],
                    [
                        'nickname' => '群成员二',
                        'wxid' => 'wxid_member2'
                    ]
                ]
            ]
        ], $overrides);
    }

    /**
     * 机器人自身数据更新消息
     */
    public static function ownerDataMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_DATA_OWNER_MSG',
            'client_id' => 1,
            'data' => null
        ], $overrides);
    }

    /**
     * 客户端连接消息
     */
    public static function clientConnectedMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_CLIENT_CONTECTED',
            'client_id' => 1
        ], $overrides);
    }

    /**
     * QR码消息
     */
    public static function qrCodeMessage(array $overrides = []): array
    {
        return array_merge([
            'type' => 'MT_RECV_QRCODE_MSG',
            'client_id' => 1,
            'data' => [
                'qrcode' => 'https://login.weixin.qq.com/qrcode/test123',
                'status' => '0'
            ]
        ], $overrides);
    }

    /**
     * 获取所有消息类型的示例
     */
    public static function getAllMessageTypes(): array
    {
        return [
            'text_message' => self::textMessage(),
            'room_text_message' => self::roomTextMessage(),
            'user_login' => self::userLogin(),
            'picture_message' => self::pictureMessage(),
            'other_app_message' => self::otherAppMessage(),
            'bot_sent_message' => self::botSentMessage(),
            'at_message' => self::atMessage(),
            'system_message' => self::systemMessage(),
            'contact_data_message' => self::contactDataMessage(),
            'single_contact_message' => self::singleContactMessage(),
            'chatroom_members_message' => self::chatroomMembersMessage(),
            'owner_data_message' => self::ownerDataMessage(),
            'client_connected_message' => self::clientConnectedMessage(),
            'qr_code_message' => self::qrCodeMessage()
        ];
    }

    /**
     * 生成随机的微信ID
     */
    public static function randomWxid(string $prefix = 'wxid_'): string
    {
        return $prefix . substr(md5(uniqid()), 0, 12);
    }

    /**
     * 生成随机的消息ID
     */
    public static function randomMsgid(): string
    {
        return (string) mt_rand(1000000000000000000, 9999999999999999999);
    }

    /**
     * 生成当前时间戳
     */
    public static function currentTimestamp(): int
    {
        return time();
    }

    /**
     * 构建POST请求数据（模拟XbotController接收的格式）
     */
    public static function buildPostRequestData(array $messageData): array
    {
        return [
            'type' => $messageData['type'],
            'client_id' => $messageData['client_id'],
            'data' => $messageData['data'] ?? null
        ];
    }
}