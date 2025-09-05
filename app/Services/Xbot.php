<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 微信机器人API服务类
 *
 * 提供微信自动化操作的完整API接口，包括：
 * - 用户管理（登录、退出、好友操作）
 * - 消息发送（文本、图片、文件、链接等）
 * - 群组管理（创建、邀请、删除成员）
 * - 朋友圈操作（发布、点赞、评论）
 */
final class Xbot
{
    private const API_ENDPOINT = '/';

    // 消息类型常量
    private const MESSAGE_TYPES = [
        'GET_SELF_INFO' => 'MT_DATA_OWNER_MSG',
        'LOGOUT' => 'MT_QUIT_LOGIN_MSG',
        'LOAD_QR_CODE' => 'MT_RECV_QRCODE_MSG',
        'INJECT_WECHAT' => 'MT_INJECT_WECHAT',
        'CLOSE_CLIENT' => 'MT_QUIT_WECHAT_MSG',
        'ACCEPT_FRIEND' => 'MT_ACCEPT_FRIEND_MSG',
        'REFUSE_TRANSFER' => 'MT_REFUSE_FRIEND_WCPAY_MSG',
        'SEARCH_CONTACT' => 'MT_SEARCH_CONTACT_MSG',
        'ADD_FRIEND' => 'MT_ADD_SEARCH_CONTACT_MSG',
        'CHECK_FRIENDSHIP' => 'MT_ZOMBIE_CHECK_MSG',
        'VOICE_TO_TEXT' => 'MT_TRANS_VOICE_MSG',
        'DECRYPT_IMAGE' => 'MT_DECRYPT_IMG_MSG',
        'SEND_FILE' => 'MT_SEND_FILEMSG',
        'SEND_IMAGE' => 'MT_SEND_IMGMSG',
        'SEND_IMAGE_URL' => 'MT_SEND_IMGMSG_BY_URL',
        'SEND_FILE_URL' => 'MT_SEND_FILEMSG_BY_URL',
        'GET_FRIENDS' => 'MT_DATA_FRIENDS_MSG',
        'GET_FRIEND_INFO' => 'MT_DATA_WXID_MSG',
        'GET_CHATROOMS' => 'MT_DATA_CHATROOMS_MSG',
        'GET_ROOM_MEMBERS' => 'MT_DATA_CHATROOM_MEMBERS_MSG',
        'GET_PUBLIC_ACCOUNTS' => 'MT_DATA_PUBLICS_MSG',
        'UPDATE_ROOM_MEMBERS' => 'MT_UPDATE_ROOM_MEMBER_MSG',
        'SEND_TEXT' => 'MT_SEND_TEXTMSG',
        'SEND_CONTACT_CARD' => 'MT_SEND_CARDMSG',
        'MODIFY_REMARK' => 'MT_MOD_FRIEND_REMARK_MSG',
        'SEND_AT_MESSAGE' => 'MT_SEND_CHATROOM_ATMSG',
        'SEND_LINK' => 'MT_SEND_LINKMSG',
        'SEND_XML' => 'MT_SEND_XMLMSG',
        'CREATE_ROOM' => 'MT_CREATE_ROOM_MSG',
        'INVITE_TO_ROOM' => 'MT_INVITE_TO_ROOM_MSG',
        'INVITE_TO_ROOM_REQUEST' => 'MT_INVITE_TO_ROOM_REQ_MSG',
        'DELETE_ROOM_MEMBER' => 'MT_DEL_ROOM_MEMBER_MSG',
        'ACCEPT_TRANSFER' => 'MT_ACCEPT_WCPAY_MSG',
        'FORWARD_MESSAGE' => 'MT_FORWARD_ANY_MSG',
        'SNS_TIMELINE' => 'MT_SNS_TIMELINE_MSG',
        'SNS_LIKE' => 'MT_SNS_LIKE_MSG',
        'SNS_COMMENT' => 'MT_SNS_COMMENT_MSG',
        'SNS_PUBLISH' => 'MT_SNS_SEND_MSG',
    ];

    // 好友添加场景常量
    private const FRIEND_ADD_SCENES = [
        'PHONE_SEARCH' => 15,
        'GROUP_SEARCH' => 14,
        'WECHAT_SEARCH' => 3,
    ];

    // 僵尸粉检测状态常量
    private const FRIENDSHIP_STATUS = [
        'NORMAL' => 0,        // 正常状态
        'BLOCKED' => 1,       // 被拉黑
        'DELETED' => 2,       // 被删除
        'UNKNOWN' => 3,       // 未知原因
    ];

    private PendingRequest $httpClient;
    private int $clientId;
    private ?string $botWxid;
    private string $fileStoragePath;

    /**
     * 构造函数
     *
     * @param string $apiBaseUrl API服务器地址
     * @param ?string $botWxid 机器人微信ID
     * @param int $clientId 客户端ID，支持多机器人实例
     * @param string $fileStoragePath 文件存储路径（Windows路径格式）
     */
    public function __construct(
        string $apiBaseUrl,
        ?string $botWxid = null,
        int $clientId = 0,
        string $fileStoragePath = ''
    ) {
        $this->httpClient = Http::withOptions([])
            ->acceptJson()
            ->baseUrl($apiBaseUrl)
            ->withoutVerifying();

        $this->clientId = $clientId;
        $this->botWxid = $botWxid;
        $this->fileStoragePath = $fileStoragePath;
    }

    /**
     * 发送API请求的通用方法
     *
     * @param string $type 消息类型 $messageType
     * @param array|null $data 额外数据
     * @return Response|null
     */
    private function sendRequest(string $type, ?array $data = null): ?Response
    {
        $requestData = array_merge(
            ['client_id' => $this->clientId],
            get_defined_vars()
        );
        Log::debug(__FUNCTION__, [$type, '已延迟0.1秒']);
        // 延迟0.1秒，从根源上避免请求过快
        usleep(100000);
        return rescue(
            fn() => $this->httpClient->post(self::API_ENDPOINT, $requestData),
            null,
            []
        );
    }

    // ========== 基础操作方法 ==========

    /**
     * 获取机器人自身信息
     */
    public function getSelfInfo(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['GET_SELF_INFO']);
    }

    /**
     * 退出登录
     */
    public function logout(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['LOGOUT']);
    }

    /**
     * 加载二维码（用于登录）
     */
    public function loadQRCode(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['LOAD_QR_CODE']);
    }

    /**
     * 注入新的微信客户端实例
     * 注意：此方法不需要client_id参数
     */
    public function createNewClient(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['INJECT_WECHAT']);
    }

    /**
     * 关闭客户端连接
     *
     * @param int|null $specificClientId 指定要关闭的客户端ID，为空则关闭当前实例
     */
    public function closeClient(?int $specificClientId = null): ?Response
    {
        if ($specificClientId !== null) {
            // 关闭指定客户端
            $requestData = [
                'type' => self::MESSAGE_TYPES['CLOSE_CLIENT'],
                'client_id' => $specificClientId
            ];
            return rescue(
                fn() => $this->httpClient->post(self::API_ENDPOINT, $requestData),
                null,
                []
            );
        }

        // 关闭当前实例
        return $this->sendRequest(self::MESSAGE_TYPES['CLOSE_CLIENT']);
    }

    // ========== 好友管理方法 ==========

    /**
     * 同意好友申请
     *
     * @param int $scene 添加场景（15=手机号搜索, 14=通过群聊）
     * @param string $v1 验证参数1
     * @param string $v2 验证参数2
     */
    public function acceptFriendRequest(int $scene, string $v1, string $v2): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['ACCEPT_FRIEND'], compact('scene', 'v1', 'v2'));
    }

    /**
     * 拒绝微信转账
     *
     * @param string $transferId 转账ID
     */
    public function refuseTransfer(string $transferId): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['REFUSE_TRANSFER'], compact('transferId'));
    }

    /**
     * 搜索联系人
     *
     * @param string $searchKeyword 搜索关键词（微信号、手机号等）
     */
    public function searchContact(string $searchKeyword): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['SEARCH_CONTACT'], ['search' => $searchKeyword]);
    }

    /**
     * 添加搜索到的联系人为好友
     *
     * @param string $v1 验证参数1
     * @param string $v2 验证参数2
     * @param string $remark 备注信息
     * @param int $sourceType 来源类型（3=微信搜索, 15=手机号搜索）
     */
    public function addSearchedContactAsFriend(
        string $v1,
        string $v2,
        string $remark = 'hi',
        int $sourceType = self::FRIEND_ADD_SCENES['WECHAT_SEARCH']
    ): ?Response {
        return $this->sendRequest(
            self::MESSAGE_TYPES['ADD_FRIEND'],
            compact('v1', 'v2', 'remark', 'sourceType')
        );
    }

    /**
     * 检测好友关系状态（僵尸粉检测）
     *
     * @param string $wxid 要检测的微信ID
     * @return Response|null
     *
     * 返回值说明：
     * 0 = 正常状态（不是僵尸粉）
     * 1 = 被对方拉黑
     * 2 = 被对方删除
     * 3 = 未知原因（请反馈）
     */
    public function checkFriendshipStatus(string $wxid): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['CHECK_FRIENDSHIP'], compact('wxid'));
    }

    /**
     * 修改好友备注
     *
     * @param string $wxid 好友微信ID
     * @param string $remark 新备注
     */
    public function updateFriendRemark(string $wxid, string $remark): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['MODIFY_REMARK'], compact('wxid', 'remark'));
    }

    // ========== 联系人和群组信息获取 ==========

    /**
     * 获取好友列表
     */
    public function getFriendsList(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['GET_FRIENDS']);
    }

    /**
     * 获取指定好友信息
     *
     * @param string $wxid 好友微信ID
     */
    public function getFriendInfo(string $wxid): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['GET_FRIEND_INFO'], compact('wxid'));
    }

    /**
     * 获取群聊列表
     */
    public function getChatroomsList(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['GET_CHATROOMS']);
    }

    /**
     * 获取群成员列表
     *
     * @param string $chatroomWxid 群聊微信ID
     */
    public function getChatroomMembers(string $chatroomWxid): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['GET_ROOM_MEMBERS'],
            ['room_wxid' => $chatroomWxid]
        );
    }

    /**
     * 获取公众号列表
     */
    public function getPublicAccountsList(): ?Response
    {
        return $this->sendRequest(self::MESSAGE_TYPES['GET_PUBLIC_ACCOUNTS']);
    }

    /**
     * 从网络更新群成员信息
     *
     * @param string $chatroomWxid 群聊微信ID
     */
    public function updateChatroomMembers(string $chatroomWxid): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['UPDATE_ROOM_MEMBERS'],
            ['room_wxid' => $chatroomWxid]
        );
    }

    // ========== 消息发送方法 ==========

    /**
     * 发送文本消息
     *
     * @param string $targetWxid 目标微信ID
     * @param string $content 消息内容
     */
    public function sendTextMessage(string $targetWxid, string $content): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_TEXT'],
            ['to_wxid' => $targetWxid, 'content' => $content]
        );
    }

    /**
     * 发送@消息（群聊中@某人）
     *
     * @param string $chatroomWxid 群聊微信ID
     * @param string $content 消息内容，使用{@}作为@的占位符
     * @param array $mentionWxids 要@的用户微信ID列表
     *
     * @example
     * $content = "Hello {@}, how are you {@}?"
     * $mentionWxids = ["wxid_user1", "wxid_user2"]
     */
    public function sendAtMessage(string $chatroomWxid, string $content, array $mentionWxids): ?Response
    {
        // 检查是否为群聊
        if (!Str::endsWith($chatroomWxid, '@chatroom')) {
            // 不是群聊，发送普通文本消息
            return $this->sendTextMessage($chatroomWxid, $content);
        }

        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_AT_MESSAGE'],
            [
                'to_wxid' => $chatroomWxid,
                'content' => $content,
                'at_list' => $mentionWxids
            ]
        );
    }

    /**
     * 发送联系人名片
     *
     * @param string $targetWxid 目标微信ID
     * @param string $contactWxid 要分享的联系人微信ID
     */
    public function sendContactCard(string $targetWxid, string $contactWxid): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_CONTACT_CARD'],
            ['to_wxid' => $targetWxid, 'card_wxid' => $contactWxid]
        );
    }

    /**
     * 构建文件的完整Windows路径
     *
     * @param string $filename 文件名
     * @return string 完整路径
     */
    private function buildFilePath(string $filename): string
    {
        return $this->fileStoragePath . '\\' . $this->botWxid . "\\FileStorage\\File\\{$filename}";
    }

    /**
     * 发送文件
     * 注意：文件必须存放在指定的Windows路径中
     *
     * @param string $targetWxid 目标微信ID
     * @param string $filename 文件名
     */
    public function sendFile(string $targetWxid, string $filename): ?Response
    {
        $filePath = $this->buildFilePath($filename);
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_FILE'],
            ['to_wxid' => $targetWxid, 'file' => $filePath]
        );
    }

    /**
     * 发送图片
     * 注意：图片文件必须存放在指定的Windows路径中
     *
     * @param string $targetWxid 目标微信ID
     * @param string $filename 图片文件名
     */
    public function sendImage(string $targetWxid, string $filename): ?Response
    {
        $filePath = $this->buildFilePath($filename);
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_IMAGE'],
            ['to_wxid' => $targetWxid, 'file' => $filePath]
        );
    }

    /**
     * 通过URL发送图片
     *
     * @param string $targetWxid 目标微信ID
     * @param string $imageUrl 图片URL
     */
    public function sendImageByUrl(string $targetWxid, string $imageUrl): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_IMAGE_URL'],
            ['to_wxid' => $targetWxid, 'url' => $imageUrl]
        );
    }

    /**
     * 通过URL发送文件（TODO: 待实现）
     *
     * @param string $targetWxid 目标微信ID
     * @param string $fileUrl 文件URL
     */
    public function sendFileByUrl(string $targetWxid, string $fileUrl): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_FILE_URL'],
            ['to_wxid' => $targetWxid, 'url' => $fileUrl]
        );
    }

    /**
     * 发送链接消息
     *
     * @param string $targetWxid 目标微信ID
     * @param string $url 链接URL
     * @param string $imageUrl 缩略图URL
     * @param string $title 链接标题
     * @param string $description 链接描述
     */
    public function sendLink(
        string $targetWxid,
        string $url,
        string $imageUrl = 'https://res.wx.qq.com/t/wx_fed/wechat-main-page/wechat-main-page-oversea-new/res/static/img/3ou3PnG.png',
        string $title = '链接标题',
        string $description = '链接描述'
    ): ?Response {
        // 使用默认缩略图
        $defaultImageUrl = 'https://mmecoa.qpic.cn/sz_mmecoa_png/dTE2nNAecJa6NSAyu8czRDDDkuZZRiayAYu74347VUy625LJ7eDibDeV6ulcLeWjkrJIe9DgdG5ibcibRazp4eyVqg/640?wx_fmt=png&amp;from=appmsg';

        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_LINK'],
            [
                'to_wxid' => $targetWxid,
                'url' => $url,
                'image_url' => $defaultImageUrl,
                'title' => $title,
                'desc' => $description
            ]
        );
    }

    // ========== 多媒体和特殊消息处理 ==========

    /**
     * 语音转文字
     *
     * @param string $messageId 语音消息ID
     */
    public function convertVoiceToText(string $messageId): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['VOICE_TO_TEXT'],
            ['msgid' => $messageId]
        );
    }

    /**
     * 解密图片文件
     *
     * @param string $sourceFile 源文件路径
     * @param string $destinationFile 目标文件路径
     * @param int $fileSize 文件大小（字节）
     */
    public function decryptImageFile(string $sourceFile, string $destinationFile, int $fileSize): ?Response
    {
        // 根据文件大小计算延迟时间，避免处理过快
        $delaySeconds = (int) ceil($fileSize / 1000000) + 1;
        sleep($delaySeconds);

        return $this->sendRequest(
            self::MESSAGE_TYPES['DECRYPT_IMAGE'],
            [
                'src_file' => $sourceFile,
                'dest_file' => $destinationFile,
                'size' => $fileSize
            ]
        );
    }

    /**
     * 转发消息
     *
     * @param string $targetWxid 目标微信ID
     * @param string $messageId 要转发的消息ID
     */
    public function forwardMessage(string $targetWxid, string $messageId): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['FORWARD_MESSAGE'],
            ['to_wxid' => $targetWxid, 'msgid' => $messageId]
        );
    }

    // ========== 群组管理方法 ==========

    /**
     * 创建群聊
     * 注意：至少需要3个用户才能创建群聊
     *
     * @param string ...$memberWxids 成员微信ID列表（至少3个）
     * @return Response|array
     */
    public function createChatroom(string ...$memberWxids)
    {
        if (count($memberWxids) < 3) {
            return ['error' => '创建群聊至少需要3个成员的微信ID'];
        }

        return $this->sendRequest(self::MESSAGE_TYPES['CREATE_ROOM'], $memberWxids);
    }

    /**
     * 直接邀请用户加入群聊（适用于小群，人数<40）
     *
     * @param string $chatroomWxid 群聊微信ID
     * @param string $memberWxid 要邀请的用户微信ID
     */
    public function inviteMemberToChatroom(string $chatroomWxid, string $memberWxid): ?Response
    {
        $requestData = [
            'room_wxid' => $chatroomWxid,
            'member_list' => [$memberWxid]
        ];

        return $this->sendRequest(self::MESSAGE_TYPES['INVITE_TO_ROOM'], $requestData);
    }

    /**
     * 发送入群邀请请求（适用于大群，人数>=40）
     *
     * @param string $chatroomWxid 群聊微信ID
     * @param string $memberWxid 要邀请的用户微信ID
     */
    public function sendChatroomInviteRequest(string $chatroomWxid, string $memberWxid): ?Response
    {
        $requestData = [
            'room_wxid' => $chatroomWxid,
            'member_list' => [$memberWxid]
        ];

        return $this->sendRequest(self::MESSAGE_TYPES['INVITE_TO_ROOM_REQUEST'], $requestData);
    }

    /**
     * 从群聊中删除成员
     *
     * @param string $chatroomWxid 群聊微信ID
     * @param string $memberWxid 要删除的成员微信ID
     */
    public function removeMemberFromChatroom(string $chatroomWxid, string $memberWxid): ?Response
    {
        $requestData = [
            'room_wxid' => $chatroomWxid,
            'member_list' => [$memberWxid]
        ];

        return $this->sendRequest(self::MESSAGE_TYPES['DELETE_ROOM_MEMBER'], $requestData);
    }

    // ========== 支付相关方法 ==========

    /**
     * 自动接受转账
     *
     * @param string $transferId 转账ID
     */
    public function acceptTransfer(string $transferId): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['ACCEPT_TRANSFER'],
            ['transferid' => $transferId]
        );
    }

    /**
     * 自动接受转账（别名方法，兼容旧代码）
     *
     * @param string $transferId 转账ID
     */
    public function autoAcceptTranster(string $transferId): ?Response
    {
        return $this->acceptTransfer($transferId);
    }

    /**
     * 拒绝/退回转账
     *
     * @param string $transferId 转账ID
     */
    public function refund(string $transferId): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['REFUSE_TRANSFER'],
            ['transferid' => $transferId]
        );
    }

    // ========== XML消息和位置消息 ==========

    /**
     * 发送XML格式消息的内部方法
     *
     * @param string $xmlContent XML内容
     * @param string $targetWxid 目标微信ID
     */
    private function sendXMLMessage(string $xmlContent, string $targetWxid = 'filehelper'): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SEND_XML'],
            ['xml' => $xmlContent, 'to_wxid' => $targetWxid]
        );
    }

    /**
     * 发送位置消息
     *
     * @param string $targetWxid 目标微信ID
     * @param string $latitude 纬度
     * @param string $longitude 经度
     * @param string $scale 地图缩放级别
     * @param string $label 位置标签
     * @param string $mapType 地图类型
     * @param string $poiName POI名称
     * @param string $poiId POI ID
     */
    public function sendLocation(
        string $targetWxid,
        string $latitude,
        string $longitude,
        string $scale,
        string $label,
        string $mapType,
        string $poiName,
        string $poiId
    ): ?Response {
        $locationXml = "<?xml version=\"1.0\"?>\n<msg><location x=\"{$latitude}\" y=\"{$longitude}\" scale=\"{$scale}\" label=\"{$label}\" maptype=\"{$mapType}\" poiname=\"{$poiName}\" poiid=\"{$poiId}\" />\n</msg>\n";

        return $this->sendXMLMessage($locationXml, $targetWxid);
    }

    // ========== 朋友圈相关方法 ==========

    /**
     * 获取朋友圈动态列表
     *
     * @param int $maxId 最大ID，用于分页获取
     */
    public function getMomentsTimeline(int $maxId = 0): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SNS_TIMELINE'],
            ['max_id' => $maxId]
        );
    }

    /**
     * 给朋友圈动态点赞
     *
     * @param string $objectId 朋友圈动态对象ID
     */
    public function likeMomentsPost(string $objectId): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SNS_LIKE'],
            ['object_id' => $objectId]
        );
    }

    /**
     * 评论朋友圈动态
     *
     * @param string $objectId 朋友圈动态对象ID
     * @param string $comment 评论内容
     */
    public function commentOnMomentsPost(string $objectId, string $comment = '评论内容'): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SNS_COMMENT'],
            ['object_id' => $objectId, 'content' => $comment]
        );
    }

    /**
     * 发布朋友圈动态
     *
     * @param string $xmlContent 朋友圈内容的XML格式
     */
    public function publishMomentsPost(string $xmlContent): ?Response
    {
        return $this->sendRequest(
            self::MESSAGE_TYPES['SNS_PUBLISH'],
            ['object_desc' => $xmlContent]
        );
    }

    /**
     * 发布朋友圈视频动态
     *
     * @param string $title 视频标题
     * @param string $videoUrl 视频URL
     * @param string $thumbnailUrl 缩略图URL
     */
    public function publishVideoToMoments(
        string $title,
        string $videoUrl,
        string $thumbnailUrl = 'https://img9.doubanio.com/view/puppy_image/raw/public/1771365ca98ig9er706.jpg'
    ): ?Response {
        $videoXmlTemplate = '<TimelineObject><id>1234567890</id><username>0000</username><createTime>1661025740</createTime><contentDesc>' . $title . '</contentDesc><contentDescShowType>0</contentDescShowType><contentDescScene>0</contentDescScene><private>0</private><sightFolded>0</sightFolded><showFlag>0</showFlag><appInfo><id></id><version></version><appName></appName><installUrl></installUrl><fromUrl></fromUrl><isForceUpdate>0</isForceUpdate></appInfo><sourceUserName></sourceUserName><sourceNickName></sourceNickName><statisticsData></statisticsData><statExtStr></statExtStr><ContentObject><contentStyle>15</contentStyle><title>0000微信小视频</title><description>Sight</description><mediaList><media><id>13933693810826481954</id><type>6</type><title></title><description>0000视频发送</description><private>0</private><userData></userData><subType>0</subType><videoSize width="720" height="1280"></videoSize><url type="1" md5="" videomd5="">' . $videoUrl . '</url><thumb type="1">' . $thumbnailUrl . '</thumb><size width="720" height="1280" totalSize="000"></size><videoDuration>2.035011</videoDuration></media></mediaList><contentUrl>https://support.weixin.qq.com/cgi-bin/mmsupport-bin/readtemplate?t=page/common_page__upgrade&amp;v=1</contentUrl></ContentObject><actionInfo><appMsg><messageAction></messageAction></appMsg></actionInfo><location poiClassifyId="" poiName="" poiAddress="" poiClassifyType="0" city=""></location><publicUserName></publicUserName><streamvideo><streamvideourl></streamvideourl><streamvideothumburl></streamvideothumburl><streamvideoweburl></streamvideoweburl></streamvideo></TimelineObject>';

        return $this->publishMomentsPost($videoXmlTemplate);
    }

    /**
     * 发布朋友圈图片动态（支持九宫格）
     *
     * @param string $title 动态标题
     * @param array $imageUrls 图片URL数组
     */
    public function publishImagesToMoments(string $title, array $imageUrls): ?Response
    {
        $mediaList = '';
        foreach ($imageUrls as $imageUrl) {
            $mediaList .= '<media><id>13933920070604632377</id><type>2</type><title></title><description></description><private>0</private><userData></userData><subType>0</subType><videoSize width="0" height="0"></videoSize><url type="1" md5="2" videomd5="">' . $imageUrl . '</url><thumb type="1">' . $imageUrl . '</thumb><size width="1024.000000" height="943.000000" totalSize="16001"></size></media>';
        }

        $imagesXmlTemplate = '<TimelineObject><id><![CDATA[13933541731974386031]]></id><username>0000</username><createTime><![CDATA[1661007610]]></createTime><contentDescShowType>0</contentDescShowType><contentDescScene>0</contentDescScene><private><![CDATA[0]]></private><contentDesc><![CDATA[' . $title . ']]></contentDesc><contentattr><![CDATA[0]]></contentattr><sourceUserName></sourceUserName><sourceNickName></sourceNickName><statisticsData></statisticsData><weappInfo><appUserName></appUserName><pagePath></pagePath><version><![CDATA[0]]></version><debugMode><![CDATA[0]]></debugMode><shareActionId></shareActionId><isGame><![CDATA[0]]></isGame><messageExtraData></messageExtraData><subType><![CDATA[0]]></subType><preloadResources></preloadResources></weappInfo><canvasInfoXml></canvasInfoXml><ContentObject><contentStyle><![CDATA[1]]></contentStyle><contentSubStyle><![CDATA[0]]></contentSubStyle><title></title><description></description><contentUrl></contentUrl><mediaList>' . $mediaList . '</mediaList></ContentObject><actionInfo><appMsg><mediaTagName></mediaTagName><messageExt></messageExt><messageAction></messageAction></appMsg></actionInfo><appInfo><id></id></appInfo><location poiClassifyId="" poiName="" poiAddress="" poiClassifyType="0" city=""></location><publicUserName></publicUserName><streamvideo><streamvideourl></streamvideourl><streamvideothumburl></streamvideothumburl><streamvideoweburl></streamvideoweburl></streamvideo></TimelineObject>';

        return $this->publishMomentsPost($imagesXmlTemplate);
    }

    /**
     * 发布朋友圈链接动态
     *
     * @param string $title 链接标题
     * @param string $url 链接URL
     * @param string $comment 评论内容
     */
    public function publishLinkToMoments(string $title, string $url, string $comment = ''): ?Response
    {
        $linkXmlTemplate = '<TimelineObject><id>13933661134568034593</id><username>000</username><createTime>1661021844</createTime><contentDesc>' . $comment . '</contentDesc><contentDescShowType>0</contentDescShowType><contentDescScene>4</contentDescScene><private>0</private><sightFolded>0</sightFolded><showFlag>0</showFlag><appInfo><id></id><version></version><appName></appName><installUrl></installUrl><fromUrl></fromUrl><isForceUpdate>0</isForceUpdate></appInfo><sourceUserName></sourceUserName><sourceNickName></sourceNickName><statisticsData></statisticsData><statExtStr></statExtStr><ContentObject><contentStyle>3</contentStyle><title>' . $title . '</title><description></description><contentUrl>' . $url . '</contentUrl><mediaList></mediaList></ContentObject><actionInfo><appMsg><messageAction></messageAction></appMsg></actionInfo><location poiClassifyId="" poiName="" poiAddress="" poiClassifyType="0" city=""></location><publicUserName></publicUserName><streamvideo><streamvideourl></streamvideourl><streamvideothumburl></streamvideothumburl><streamvideoweburl></streamvideoweburl></streamvideo></TimelineObject>';

        return $this->publishMomentsPost($linkXmlTemplate);
    }

    /**
     * 发布朋友圈音乐动态
     *
     * @param string $title 音乐标题
     * @param string $url 音乐URL
     * @param string $description 音乐描述
     * @param string $comment 评论内容
     * @param string $thumbnailUrl 缩略图URL
     */
    public function publishMusicToMoments(
        string $title,
        string $url,
        string $description = '朋友圈音乐消息描述',
        string $comment = '这是描述',
        string $thumbnailUrl = 'http://mmsns.c2c.wechat.com/mmsns/vRn02nrlYphiaibib27nbILHxvsD6UjZvclzGREZFciaFCmDt9jdhbHu7tL2DiaGjhGh61ibDauiaQWsIU/150'
    ): ?Response {
        $musicXmlTemplate = '<TimelineObject><id><![CDATA[000]]></id><username><![CDATA[wxid_t36o5djpivk312]]></username><createTime><![CDATA[1661054193]]></createTime><contentDesc>' . $comment . '</contentDesc><contentDescShowType>0</contentDescShowType><contentDescScene>0</contentDescScene><private><![CDATA[0]]></private><contentDesc></contentDesc><contentattr><![CDATA[0]]></contentattr><sourceUserName></sourceUserName><sourceNickName></sourceNickName><statisticsData></statisticsData><weappInfo><appUserName></appUserName><pagePath></pagePath><version><![CDATA[0]]></version><debugMode><![CDATA[0]]></debugMode><shareActionId></shareActionId><isGame><![CDATA[0]]></isGame><messageExtraData></messageExtraData><subType><![CDATA[0]]></subType><preloadResources></preloadResources></weappInfo><canvasInfoXml></canvasInfoXml><ContentObject><contentStyle><![CDATA[4]]></contentStyle><contentSubStyle><![CDATA[0]]></contentSubStyle><title></title><description></description><contentUrl><![CDATA[' . $url . ']]></contentUrl><mediaList><media><id><![CDATA[00]]></id><type><![CDATA[3]]></type><title><![CDATA[' . $title . ']]></title><description><![CDATA[点击▶️收听  ' . $description . ']]></description><private><![CDATA[0]]></private><url type="0"><![CDATA[' . $url . ']]></url><thumb type="1"><![CDATA[' . $thumbnailUrl . ']]></thumb><videoDuration><![CDATA[0.0]]></videoDuration><lowBandUrl type="0"><![CDATA[' . $url . ']]></lowBandUrl><size totalSize="342.0" width="45.0" height="45.0"></size></media></mediaList></ContentObject><actionInfo><appMsg><mediaTagName></mediaTagName><messageExt></messageExt><messageAction></messageAction></appMsg></actionInfo><appInfo><id></id></appInfo><location poiClassifyId="" poiName="" poiAddress="" poiClassifyType="0" city=""></location><publicUserName></publicUserName><streamvideo><streamvideourl></streamvideourl><streamvideothumburl></streamvideothumburl><streamvideoweburl></streamvideoweburl></streamvideo></TimelineObject>';

        return $this->publishMomentsPost($musicXmlTemplate);
    }

    /**
     * 发布朋友圈QQ音乐动态
     *
     * @param string $title 音乐标题
     * @param string $url 音乐URL
     * @param string $description 音乐描述
     * @param string $comment 评论内容
     * @param string $thumbnailUrl 缩略图URL
     */
    public function publishQQMusicToMoments(
        string $title,
        string $url,
        string $description = '朋友圈QQ音乐消息描述',
        string $comment = '这是描述',
        string $thumbnailUrl = 'http://shmmsns.qpic.cn/mmsns/R7EkLqfbhZdGKk2iaqGLC9qx1Hpgghjrict51nrzjDhvI4Q0k6ctac1Wia0OlbEdTu6IHtBicRVThVw/150'
    ): ?Response {
        $qqMusicXmlTemplate = '<TimelineObject><id>111</id><username>000</username><createTime>1662997334</createTime><contentDesc>' . $comment . '</contentDesc><contentDescShowType>0</contentDescShowType><contentDescScene>4</contentDescScene><private>0</private><sightFolded>0</sightFolded><showFlag>0</showFlag><appInfo><id>wx5aa333606550dfd5</id><version>53</version><appName></appName><installUrl></installUrl><fromUrl></fromUrl><isForceUpdate>0</isForceUpdate><clickable>0</clickable></appInfo><sourceUserName></sourceUserName><sourceNickName></sourceNickName><statisticsData></statisticsData><statExtStr>GhQKEnd4NWFhMzMzNjA2NTUwZGZkNQ==</statExtStr><ContentObject><musicShareItem><mvSingerName>' . $description . '</mvSingerName><musicDuration>261877</musicDuration></musicShareItem><contentStyle>42</contentStyle><title></title><description></description><contentUrl>http://music.163.com/song/1938392288/?userid=1577032097</contentUrl><mediaList><media><id>3333</id><type>3</type><title>' . $title . '</title><description>444</description><private>0</private><userData></userData><subType>0</subType><videoSize width="0" height="0"></videoSize><url type="0" md5="" videomd5="">' . $url . '</url><lowBandUrl type="0">' . $url . '</lowBandUrl><thumb type="1">' . $thumbnailUrl . '</thumb></media></mediaList><musicShareItem><mvSingerName>吉拉朵5555</mvSingerName><musicDuration>261877</musicDuration></musicShareItem></ContentObject><actionInfo><scene>0</scene><type>0</type><url></url><appMsg><mediaTagName></mediaTagName><messageExt></messageExt><messageAction></messageAction><appid>wx5aa333606550dfd5</appid></appMsg><newWordingKey></newWordingKey><newtype>0</newtype><installedWording></installedWording><uninstalledWording></uninstalledWording></actionInfo><location poiClassifyId="" poiName="" poiAddress="" poiClassifyType="0" city=""></location><publicUserName></publicUserName><streamvideo><streamvideourl></streamvideourl><streamvideothumburl></streamvideothumburl><streamvideoweburl></streamvideoweburl></streamvideo></TimelineObject>';

        return $this->publishMomentsPost($qqMusicXmlTemplate);
    }

    // ========== 音乐消息发送方法 ==========

    /**
     * 发送音乐消息
     *
     * @param string $targetWxid 目标微信ID
     * @param string $url 音乐URL
     * @param string $title 音乐标题
     * @param string $description 音乐描述
     * @param string|null $coverUrl 封面图URL
     * @param string|null $lyrics 歌词
     */
    public function sendMusic(
        string $targetWxid,
        string $url,
        string $title = '',
        string $description = '',
        ?string $coverUrl = null,
        ?string $lyrics = null
    ): ?Response {
        // 特殊处理某个特定机器人账号
        $coverUrl = $coverUrl??'https://mmecoa.qpic.cn/sz_mmecoa_png/dTE2nNAecJYUksGb1XOwruv2rxedibHdN7j0cgcpw8DibwhS23UGjnu9QibULUSfyjtINNticX4saqZ8cYRJmUHFeQ/640?wx_fmt=png&amp;from=appmsg';

        $appInfo = $this->getRandomAppInfo();
        $musicXml = "<?xml version=\"1.0\"?>\n<msg><appmsg appid=\"{$appInfo['id']}\" sdkver=\"0\"><title>{$title}</title><des>{$description}</des><type>3</type><action>view</action><dataurl>{$url}</dataurl><thumburl>{$coverUrl}</thumburl><songlyric>{$lyrics}</songlyric><appattach><cdnthumbaeskey /><aeskey /></appattach><webviewshared><jsAppId><![CDATA[]]></jsAppId></webviewshared><mpsharetrace><hasfinderelement>0</hasfinderelement></mpsharetrace><secretmsg><isscrectmsg>0</isscrectmsg></secretmsg></appmsg><fromusername>{$this->botWxid}</fromusername><scene>0</scene><appinfo><version>29</version><appname>{$appInfo['name']}</appname></appinfo><commenturl></commenturl>\n</msg>\n";

        return $this->sendXMLMessage($musicXml, $targetWxid);
    }

    /**
     * 获取随机应用信息
     * 用于发送音乐等特殊消息时模拟不同的应用来源
     *
     * @return array 包含应用名称和ID的数组
     */
    private function getRandomAppInfo(): array
    {
        // 默认使用微信电脑版
        return ['name' => '微信电脑版', 'id' => 'wx6618f1cfc6c132f8'];

        // 可选的其他应用（已注释，需要时可取消注释）
        /*
        return Arr::random([
            ['name' => '订阅号助手', 'id' => 'wx50a3272e1669f0c0'],
            ['name' => 'QQ音乐', 'id' => 'wx5aa333606550dfd5'],
            ['name' => '网易云音乐', 'id' => 'wx8dd6ecd81906fd84'],
            ['name' => '摇一摇', 'id' => 'wx485a97c844086dc9'],
            ['name' => '微信电脑版', 'id' => 'wx6618f1cfc6c132f8'],
        ]);
        */
    }

    // ========== Getter方法 ==========

    /**
     * 获取客户端ID
     */
    public function getClientId(): int
    {
        return $this->clientId;
    }

    /**
     * 获取机器人微信ID
     */
    public function getBotWxid(): string
    {
        return $this->botWxid;
    }

    /**
     * 获取文件存储路径
     */
    public function getFileStoragePath(): string
    {
        return $this->fileStoragePath;
    }

    /**
     * 获取HTTP客户端实例
     */
    public function getHttpClient(): PendingRequest
    {
        return $this->httpClient;
    }
}
