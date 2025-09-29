<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use EasyWeChat\OfficialAccount\Application;
use App\Services\WeChat\CustomerServiceMessage;

class EasyWeChatController extends Controller
{
    /**
     * 处理微信公众号的请求消息.
     *
     * @return string
     */
    public function serve($appId = 'gh_aa9c2e621082')
    {
    
        $server = $this->getApp()->getServer();
        
        // 检查是否是微信验证请求
        if ($echostr = request()->get('echostr')) {
            Log::info('WeChat verification request received', ['echostr' => $echostr]);
            return response($echostr);
        }
        
        // 处理关注事件
        $server->with(function($message, \Closure $next) {
            Log::debug('Processing message in event handler', [
                'msg_type' => $message->MsgType ?? 'unknown',
                'event' => $message->Event ?? 'none',
            ]);
            
            if (isset($message->MsgType) && $message->MsgType === 'event' && 
                isset($message->Event) && $message->Event === 'subscribe') {
                return '感谢您关注!';
            }
            return $next($message);
        });

        // 处理关键词
        $server->with(new EasyWeChatKeywordHandler());
        
        // 默认回复
        $server->with(function($message, \Closure $next) {
            Log::info('Default reply handler reached', [
                'msg_type' => $message->MsgType ?? 'unknown',
                'content' => $message->Content ?? 'no_content',
            ]);
            return '感谢你使用';
        });

        // 让 EasyWeChat 自动处理消息解析和响应
        $response = $server->serve();
        
        // 尝试获取实际的响应内容用于调试
        $bodyContent = '';
        if (method_exists($response, 'getBody')) {
            $body = $response->getBody();
            if (method_exists($body, 'getContents')) {
                $bodyContent = $body->getContents();
                // 重置流指针，因为Laravel可能需要再次读取
                if (method_exists($body, 'rewind')) {
                    $body->rewind();
                }
            }
        }
        
        return $response;
    }

    public function getApp($ghId = 'gh_c2138e687da3'){
        //@see https://easywechat.com/6.x/official-account/
        // TODO get configs from DB
        $configs['gh_c2138e687da3'] = [
            'app_id' => config('easywechat.official_account.default.app_id'),
            'secret' => config('easywechat.official_account.default.secret'),
            'token' => config('easywechat.official_account.default.token'),
            // 明文模式请勿填写 EncodingAESKey
            'aes_key' => config('easywechat.official_account.default.aes_key',''),
        ];
        $config = $configs[$ghId];
        return new Application($config);
    }

    /**
     * 获取客服消息服务实例
     */
    public function getCustomerService($ghId = 'gh_c2138e687da3'): CustomerServiceMessage
    {
        $app = $this->getApp($ghId);
        return new CustomerServiceMessage($app);
    }
}