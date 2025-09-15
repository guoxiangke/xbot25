<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\Analytics\FriendSourceAnalyzer;

class FriendSourceAnalyzerTest extends TestCase
{
    /**
     * 测试群聊添加好友来源识别
     */
    public function test_group_friend_request()
    {
        // 基于真实日志数据构造的群聊好友请求
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_7oj95gkn5st322" encryptusername="v3_020b3826fd03010000000000a96522968b0a52000000501ea9a3dba12f95f6b60a0536a1adb616d162c2793388acbac90b3f3523c1e8eaef6eb3878dc38d1f7c6bce673f8492fb5c618e3fc96347e281989879b0bd568e8ea2e880ea612856fd76d4@stranger" fromnickname="yr" content="我是群聊&quot;好牧人听众群④ *禁言*&quot;的yr" fullpy="yr" shortpy="YR" imagestatus="3" scene="14" country="CN" province="" city="" sign="yr" percard="1" sex="2" alias="yr13307762872" weibo="" albumflag="0" albumstyle="0" albumbgimgid="" snsflag="257" snsbgimgid="http://mmsns.qpic.cn/mmsns/guwpBgA9LY24zDNW2iaB7Tk6OlicehicvNPb9IlicDto7VJdq5CFlPXUPIbqcPZVCCR91gmlXyet9VU/0" snsbgobjectid="12271627810696540297" mhash="e496af79096cc789fa9406e7e2eda897" mfullhash="e496af79096cc789fa9406e7e2eda897" bigheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/tSVKibDj1M1GdFT3AYW1gBcyZMticq0Or6vDU74sOicgVmjhamS3FpBIg78pXxnxjX7d2goicgxFa5ekrsWjuqBKbu7VSuFGa9ibiayc7WYDx0QXU/0" smallheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/tSVKibDj1M1GdFT3AYW1gBcyZMticq0Or6vDU74sOicgVmjhamS3FpBIg78pXxnxjX7d2goicgxFa5ekrsWjuqBKbu7VSuFGa9ibiayc7WYDx0QXU/96" ticket="v4_000b708f0b040000010000000000548dd459ad3671466d2182f883681000000050ded0b020927e3c97896a09d47e6e9eabb1ae8bb0589dc65875b9029f725497202601da3802533254dbb40f916dcb634ca5059906ba1a77b6d921f75f829f84b7cb51cd4dca87a3752e9c0d131ff353e86015b65419fe8ad07e643c2118688eaa6963a43e4f58de6f046e68cb5e03e8d2d6d60b543cd72a@stranger" opcode="2" googlecontact="" qrticket="" chatroomusername="50000744747@chatroom" sourceusername="" sourcenickname="" sharecardusername="" sharecardnickname="" cardversion="" extflag="0"><brandlist count="0" ver="862550129"></brandlist></msg>'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('group', $result['source_type']);
        $this->assertEquals('群聊添加', $result['source_desc']);
        $this->assertEquals('14', $result['details']['scene']);
        $this->assertEquals('我是群聊&quot;好牧人听众群④ *禁言*&quot;的yr', $result['details']['content']);
        $this->assertEquals('yr', $result['details']['from_nickname']);
        $this->assertEquals('50000744747@chatroom', $result['details']['chatroom_username']);
    }

    /**
     * 测试另一个群聊添加案例
     */
    public function test_group_friend_request_case2()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_v9jafd5anh4k22" encryptusername="v3_020b3826fd030100000000004a1d1877b5c17e000000501ea9a3dba12f95f6b60a0536a1adb6e7bb0a88c105e6783a3f80d1351df300f71b294bb087b2d2af2b4264b779fde1b1f4d75460fec964d4f6afb98257ac7e13acf2036e8c7010c32a1f1258@stranger" fromnickname="Jason Niu" content="我是群聊&quot;导航褥子铺&quot;的牛先生" fullpy="JasonNiu" shortpy="JASONNIU" imagestatus="3" scene="14" country="CN" province="Beijing" city="Changping" sign="" percard="1" sex="1" alias="Jason_tx_niu" weibo="" albumflag="0" albumstyle="0" albumbgimgid="" snsflag="17" snsbgimgid="http://mmsns.qpic.cn/mmsns/jJtbwFuzNwDicM7ns52VJdLHroZvzYfpCTLat9ic57FWXwPflnTyP8k9e95lkpCaQlia8piaHNssuOA/0" snsbgobjectid="12327905098742640982" mhash="a007b17d41ef3f0f53e9d9a501438412" mfullhash="a007b17d41ef3f0f53e9d9a501438412" bigheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/2bOzBM7fYqgYruCq6v0iaRbCicB65gxGic5zsVNTs28LXqAVCIgGeTt6Ahy3tIfMqmj5IRW0YxmNQiaLpBxLa97pyaWW2DYa46GRiblX3Ax5vCicq3ypmge29ZRfUF5XVTAJcm/0" smallheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/2bOzBM7fYqgYruCq6v0iaRbCicB65gxGic5zsVNTs28LXqAVCIgGeTt6Ahy3tIfMqmj5IRW0YxmNQiaLpBxLa97pyaWW2DYa46GRiblX3Ax5vCicq3ypmge29ZRfUF5XVTAJcm/132" ticket="v4_000b708f0b0400000100000000006f669cf7dd2ed481605e9c1584681000000050ded0b020927e3c97896a09d47e6e9e1bf82790751e5315e85360080a53a5dd4c8a4be428751fa3a5f58ae7465dfaaaf13b2720871779a2ceeb6bb70003bdbc47ae7e862678a1dff616a5a56b838f23fbaace036e869a86310d79b2c8762433fa43aa2504273032d06137901fa990da0bb83b28c1162bfb6c@stranger" opcode="2" googlecontact="" qrticket="" chatroomusername="20314937575@chatroom" sourceusername="" sourcenickname="" sharecardusername="" sharecardnickname="" cardversion="" extflag="0"><brandlist count="0" ver="756535505"></brandlist></msg>'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('group', $result['source_type']);
        $this->assertEquals('群聊添加', $result['source_desc']);
        $this->assertEquals('14', $result['details']['scene']);
        $this->assertEquals('我是群聊&quot;导航褥子铺&quot;的牛先生', $result['details']['content']);
        $this->assertEquals('Jason Niu', $result['details']['from_nickname']);
        $this->assertEquals('20314937575@chatroom', $result['details']['chatroom_username']);
    }

    /**
     * 测试一般搜索（scene=30）- 基于真实日志数据
     */
    public function test_general_search_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_o35naknw2rl221" encryptusername="v3_020b3826fd030100000000005e0a0585989930000000501ea9a3dba12f95f6b60a0536a1adb66a83583645504638660b683f953e93a36e0a58086806abf1a60bc58eaa64d40fd5c16e948f202bfce0b6d621d09c79ea0a912678d5765eac44150e0d39@stranger" fromnickname="张莹静" content="我是张莹静" fullpy="zhangyingjing" shortpy="ZYJ" imagestatus="3" scene="30" country="CN" province="Xinjiang" city="Urumqi" sign="💞时间与智慧👑 智慧与敬畏🙏 越自律𡌌越自由🌈👊" percard="1" sex="2" alias="" weibo="" albumflag="0" albumstyle="0" albumbgimgid="" snsflag="145" snsbgimgid="http://mmsns.qpic.cn/mmsns/PiajxSqBRaEJyhHlwCslEDeZMhVYdqSrYRhxKWUdPibQQUHNic7F08j4YX9JfBrV4sL/0" snsbgobjectid="13693635958164586722" mhash="9b0bde0b9227a5433454e6a9e67e1b4a" mfullhash="9b0bde0b9227a5433454e6a9e67e1b4a" bigheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/VuNvvaLyib8Zz0bTbO3FJ3TuiatUHc8HylGzbAsKI2hrktdsAf7ZGML3UGxnZcedWZ9Z2n65gGyJARiaPNpZY9W3Peq270PhhM1qmy8OLibl31knLVHT5tFkHwYIreXM9pOu/0" smallheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/VuNvvaLyib8Zz0bTbO3FJ3TuiatUHc8HylGzbAsKI2hrktdsAf7ZGML3UGxnZcedWZ9Z2n65gGyJARiaPNpZY9W3Peq270PhhM1qmy8OLibl31knLVHT5tFkHwYIreXM9pOu/96" ticket="v4_000b708f0b040000010000000000ac6df6558fa86600e046261384681000000050ded0b020927e3c97896a09d47e6e9ee5730fa87b0bf421663379bb419338d0630a5e782df1075071cda7f1af92980c0b36d9bace796ac3a0d51b48e38d2aa6f63fd3bdcda1c814e86ce7388e4785baa08963784d9616e5729befc1e9eb539a97c93fddc4508d1fba44a76c8acc3e7d643e5eff34c3713d7b@stranger" opcode="2" googlecontact="" qrticket="" chatroomusername="" sourceusername="" sourcenickname="" sharecardusername="" sharecardnickname="" cardversion="" extflag="0"><brandlist count="0" ver="794426111"></brandlist></msg>'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('search_general', $result['source_type']);
        $this->assertEquals('一般搜索', $result['source_desc']);
        $this->assertEquals('30', $result['details']['scene']);
        $this->assertEquals('我是张莹静', $result['details']['content']);
        $this->assertEquals('张莹静', $result['details']['from_nickname']);
        $this->assertEquals('', $result['details']['chatroom_username']);
    }

    /**
     * 测试scene=30但可能是手机号搜索的案例
     */
    public function test_phone_search_guessing()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_test123" content="我是晨光机械 老赵" fromnickname="晨光机械 老赵" scene="30" country="CN" province="Hebei" city="Handan" sign="人为善，福虽未至，祸已远离。但行好事，莫问前程。天必佑之！" percard="1" sex="1" alias="syhg_2009" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('search_general', $result['source_type']); // 没有明显的手机号特征，识别为一般搜索
        $this->assertEquals('一般搜索', $result['source_desc']);
        $this->assertEquals('30', $result['details']['scene']);
    }

    /**
     * 测试手机号搜索（scene=1）
     */
    public function test_phone_search_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_test456" content="我是王小明" fromnickname="王小明" scene="1" country="CN" province="Beijing" city="Beijing" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('search_phone', $result['source_type']);
        $this->assertEquals('搜索手机号', $result['source_desc']);
        $this->assertEquals('1', $result['details']['scene']);
        $this->assertEquals('我是王小明', $result['details']['content']);
        $this->assertEquals('王小明', $result['details']['from_nickname']);
    }

    /**
     * 测试微信号搜索（scene=2）
     */
    public function test_wechat_id_search_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_test789" content="我是李小华" fromnickname="李小华" scene="2" country="CN" province="Shanghai" city="Shanghai" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('search_wechat', $result['source_type']);
        $this->assertEquals('搜索微信号', $result['source_desc']);
        $this->assertEquals('2', $result['details']['scene']);
        $this->assertEquals('我是李小华', $result['details']['content']);
        $this->assertEquals('李小华', $result['details']['from_nickname']);
    }

    /**
     * 测试扫一扫（scene=8）
     */
    public function test_qr_code_scan_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_scan123" content="我是二维码扫描用户" fromnickname="扫码用户" scene="8" country="CN" province="Guangdong" city="Shenzhen" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('qr_code', $result['source_type']);
        $this->assertEquals('扫一扫', $result['source_desc']);
        $this->assertEquals('8', $result['details']['scene']);
        $this->assertEquals('我是二维码扫描用户', $result['details']['content']);
        $this->assertEquals('扫码用户', $result['details']['from_nickname']);
    }

    /**
     * 测试名片分享（scene=13）
     */
    public function test_contact_card_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_card123" content="我是名片分享用户" fromnickname="名片用户" scene="13" country="CN" province="Zhejiang" city="Hangzhou" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('contact_card', $result['source_type']);
        $this->assertEquals('名片分享', $result['source_desc']);
        $this->assertEquals('13', $result['details']['scene']);
        $this->assertEquals('我是名片分享用户', $result['details']['content']);
        $this->assertEquals('名片用户', $result['details']['from_nickname']);
    }

    /**
     * 测试附近的人（scene=25）
     */
    public function test_nearby_people_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_nearby123" content="我是附近的人" fromnickname="附近用户" scene="25" country="CN" province="Jiangsu" city="Nanjing" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('nearby', $result['source_type']);
        $this->assertEquals('附近的人', $result['source_desc']);
        $this->assertEquals('25', $result['details']['scene']);
        $this->assertEquals('我是附近的人', $result['details']['content']);
        $this->assertEquals('附近用户', $result['details']['from_nickname']);
    }

    /**
     * 测试摇一摇（scene=17）
     */
    public function test_shake_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_shake123" content="我是摇一摇用户" fromnickname="摇摇用户" scene="17" country="CN" province="Sichuan" city="Chengdu" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('shake', $result['source_type']);
        $this->assertEquals('摇一摇', $result['source_desc']);
        $this->assertEquals('17', $result['details']['scene']);
        $this->assertEquals('我是摇一摇用户', $result['details']['content']);
        $this->assertEquals('摇摇用户', $result['details']['from_nickname']);
    }

    /**
     * 测试未知场景
     */
    public function test_unknown_scene_friend_request()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_unknown123" content="我是未知场景用户" fromnickname="未知用户" scene="99" country="CN" province="Hubei" city="Wuhan" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('unknown', $result['source_type']);
        $this->assertEquals('未知来源', $result['source_desc']);
        $this->assertEquals('99', $result['details']['scene']);
        $this->assertEquals('我是未知场景用户', $result['details']['content']);
        $this->assertEquals('未知用户', $result['details']['from_nickname']);
    }

    /**
     * 测试content内容推测搜索类型（包含数字可能是手机号）
     */
    public function test_guess_phone_search_by_content()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_phone123" content="我是138手机用户" fromnickname="手机用户" scene="30" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('search_phone', $result['source_type']);
        $this->assertEquals('搜索手机号', $result['source_desc']);
        $this->assertEquals('30', $result['details']['scene']);
    }

    /**
     * 测试content内容推测搜索类型（包含英文字母可能是微信号）
     */
    public function test_guess_wechat_search_by_content()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_wechat123" content="我是abc123user" fromnickname="微信用户" scene="30" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('search_wechat', $result['source_type']);
        $this->assertEquals('搜索微信号', $result['source_desc']);
        $this->assertEquals('30', $result['details']['scene']);
    }

    /**
     * 测试空或无效数据的处理
     */
    public function test_empty_or_invalid_data()
    {
        // 测试空数据
        $result = FriendSourceAnalyzer::analyze([]);
        $this->assertEquals('unknown', $result['source_type']);
        $this->assertEquals('未知来源', $result['source_desc']);

        // 测试无效raw_msg
        $result = FriendSourceAnalyzer::analyze(['raw_msg' => '']);
        $this->assertEquals('unknown', $result['source_type']);
        $this->assertEquals('未知来源', $result['source_desc']);

        // 测试损坏的XML
        $result = FriendSourceAnalyzer::analyze(['raw_msg' => '<invalid xml']);
        $this->assertEquals('unknown', $result['source_type']);
        $this->assertEquals('未知来源', $result['source_desc']);
    }

    /**
     * 测试群聊边界情况 - 有chatroomusername但content不符合群聊格式
     */
    public function test_group_edge_case_with_chatroom_but_wrong_content()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_edge123" content="普通消息" fromnickname="用户" scene="14" chatroomusername="12345@chatroom" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        // 由于content不符合"我是群聊...的"格式，不应该识别为群聊添加
        $this->assertNotEquals('group', $result['source_type']);
    }

    /**
     * 测试真实的复杂群聊case - 来自日志的真实数据
     */
    public function test_real_complex_group_case()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_j03qz2rj8xgf22" encryptusername="v3_020b3826fd03010000000000c1260309d1f8e5000000501ea9a3dba12f95f6b60a0536a1adb6e7bb0a88c105e6783a3f80d13526e442886f0de70b29e7d74edaf33a2b2e6e455dbc24099a2d47d8807132e9d64206f29f1e5cff5e7572e1fe32cb1374@stranger" fromnickname="谢水英" content="我是群聊&quot;Trumpet禁言6&quot;的谢水英" fullpy="xieshuiying" shortpy="XSY" imagestatus="3" scene="14" country="UM" province="" city="" sign="Death is not the loss of love ,but out of time" percard="1" sex="1" alias="yangzerui0428" weibo="" albumflag="0" albumstyle="0" albumbgimgid="" snsflag="257" snsbgimgid="http://shmmsns.qpic.cn/mmsns/fc9RKs5UydKIVrDpEibjKrGtWWjjOIibQYQIicgWKuUuWybPoOicnSAsic1oJLJdTHCuk6jFyESP5w8A/0" snsbgobjectid="13877408401815122247" mhash="d89ba41b8bf635279e5d9a9cacb5c904" mfullhash="d89ba41b8bf635279e5d9a9cacb5c904" bigheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/xSratjXMe9O0rfN2QecJDO2mrOvgk6wmCVSod4g06s6U7QIpgWwia05YDqsI4DdbodsWSDzAulzAXFEsGic3ZXpF9WjjByR2vAGHd5AYkvQMk/0" smallheadimgurl="http://wx.qlogo.cn/mmhead/ver_1/xSratjXMe9O0rfN2QecJDO2mrOvgk6wmCVSod4g06s6U7QIpgWwia05YDqsI4DdbodsWSDzAulzAXFEsGic3ZXpF9WjjByR2vAGHd5AYkvQMk/96" ticket="v4_000b708f0b040000010000000000c261aade863771cba899161db8681000000050ded0b020927e3c97896a09d47e6e9edc11421f1ce89694d91f242d292794ec1b406c3ed155f03870cd9c7d5610c4cc9298c0bd84c310e050914b56c5b9a34dc61f6f92dde511bf3c840711ad84ca9153372f881de1bb946d781eebeff6f7bc82622617d8b7d2eba0891341959aa32edbe5bde6f4d95d1171@stranger" opcode="2" googlecontact="" qrticket="" chatroomusername="48607342942@chatroom" sourceusername="" sourcenickname="" sharecardusername="" sharecardnickname="" cardversion="" extflag="0"><brandlist count="0" ver="756559209"></brandlist></msg>'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        $this->assertEquals('group', $result['source_type']);
        $this->assertEquals('群聊添加', $result['source_desc']);
        $this->assertEquals('14', $result['details']['scene']);
        $this->assertEquals('我是群聊&quot;Trumpet禁言6&quot;的谢水英', $result['details']['content']);
        $this->assertEquals('谢水英', $result['details']['from_nickname']);
        $this->assertEquals('48607342942@chatroom', $result['details']['chatroom_username']);
    }

    /**
     * 测试获取所有支持的来源类型
     */
    public function test_get_supported_source_types()
    {
        $types = FriendSourceAnalyzer::getSupportedSourceTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('group', $types);
        $this->assertArrayHasKey('search_phone', $types);
        $this->assertArrayHasKey('search_wechat', $types);
        $this->assertArrayHasKey('search_general', $types);
        $this->assertArrayHasKey('qr_code', $types);
        $this->assertArrayHasKey('contact_card', $types);
        $this->assertArrayHasKey('nearby', $types);
        $this->assertArrayHasKey('shake', $types);
        $this->assertArrayHasKey('unknown', $types);

        $this->assertEquals('群聊添加', $types['group']);
        $this->assertEquals('搜索手机号', $types['search_phone']);
        $this->assertEquals('搜索微信号', $types['search_wechat']);
        $this->assertEquals('一般搜索', $types['search_general']);
        $this->assertEquals('扫一扫', $types['qr_code']);
        $this->assertEquals('名片分享', $types['contact_card']);
        $this->assertEquals('附近的人', $types['nearby']);
        $this->assertEquals('摇一摇', $types['shake']);
        $this->assertEquals('未知来源', $types['unknown']);
    }

    /**
     * 测试结果数据结构的完整性
     */
    public function test_result_structure_completeness()
    {
        $messageData = [
            'raw_msg' => '<msg fromusername="wxid_test" content="我是测试用户" fromnickname="测试" scene="30" country="CN" province="Beijing" city="Beijing" fullpy="ceshi" shortpy="CS" />'
        ];

        $result = FriendSourceAnalyzer::analyze($messageData);

        // 检查主要结构
        $this->assertArrayHasKey('source_type', $result);
        $this->assertArrayHasKey('source_desc', $result);
        $this->assertArrayHasKey('details', $result);

        // 检查details结构
        $details = $result['details'];
        $this->assertArrayHasKey('scene', $details);
        $this->assertArrayHasKey('content', $details);
        $this->assertArrayHasKey('from_nickname', $details);
        $this->assertArrayHasKey('chatroom_username', $details);
        $this->assertArrayHasKey('parsed_fields', $details);

        // 检查parsed_fields中的字段
        $parsedFields = $details['parsed_fields'];
        $this->assertArrayHasKey('scene', $parsedFields);
        $this->assertArrayHasKey('content', $parsedFields);
        $this->assertArrayHasKey('fromnickname', $parsedFields);
        $this->assertArrayHasKey('country', $parsedFields);
        $this->assertArrayHasKey('province', $parsedFields);
        $this->assertArrayHasKey('city', $parsedFields);
        $this->assertArrayHasKey('fullpy', $parsedFields);
        $this->assertArrayHasKey('shortpy', $parsedFields);

        // 验证数据值
        $this->assertEquals('30', $parsedFields['scene']);
        $this->assertEquals('我是测试用户', $parsedFields['content']);
        $this->assertEquals('测试', $parsedFields['fromnickname']);
        $this->assertEquals('CN', $parsedFields['country']);
        $this->assertEquals('Beijing', $parsedFields['province']);
        $this->assertEquals('Beijing', $parsedFields['city']);
        $this->assertEquals('ceshi', $parsedFields['fullpy']);
        $this->assertEquals('CS', $parsedFields['shortpy']);
    }
}