<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WechatSendRequest extends FormRequest
{
    /**
     * 确定用户是否有权限发出此请求
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 获取适用于请求的验证规则
     */
    public function rules(): array
    {
        $rules = [
            'type' => 'required|string|in:text,at,link,card,image,music,postLink,postImages,postVideo,postQQMusic,postMusic',
            'to' => 'required|string',
        ];

        // 根据消息类型添加相应的验证规则
        switch ($this->input('type')) {
            case 'text':
                $rules['data.content'] = 'required|string|max:10000';
                break;
                
            case 'at':
                $rules['data.content'] = 'required|string|max:10000';
                $rules['data.at'] = 'required|array';
                $rules['data.at.*'] = 'string';
                break;
                
            case 'link':
                $rules['data.url'] = 'required|url|max:2000';
                $rules['data.title'] = 'nullable|string|max:200';
                $rules['data.description'] = 'nullable|string|max:500';
                $rules['data.image'] = 'nullable|url|max:2000';
                break;
                
            case 'card':
                $rules['data.wxid'] = 'required|string|max:100';
                break;
                
            case 'image':
                $rules['data.url'] = 'required|url|max:2000';
                break;
                
            case 'music':
                $rules['data.url'] = 'required|url|max:2000';
                $rules['data.title'] = 'required|string|max:200';
                $rules['data.description'] = 'nullable|string|max:500';
                $rules['data.coverUrl'] = 'nullable|url|max:2000';
                $rules['data.lyrics'] = 'nullable|string|max:1000';
                break;

            case 'postLink':
                $rules['data.title'] = 'required|string|max:200';
                $rules['data.url'] = 'required|url|max:2000';
                $rules['data.comment'] = 'nullable|string|max:1000';
                break;

            case 'postImages':
                $rules['data.title'] = 'required|string|max:200';
                $rules['data.urls'] = 'required|array|max:9';
                $rules['data.urls.*'] = 'required|url|max:2000';
                break;

            case 'postVideo':
                $rules['data.title'] = 'required|string|max:200';
                $rules['data.url'] = 'required|url|max:2000';
                $rules['data.thumbnailUrl'] = 'nullable|url|max:2000';
                break;

            case 'postMusic':
                $rules['data.title'] = 'required|string|max:200';
                $rules['data.url'] = 'required|url|max:2000';
                $rules['data.description'] = 'required|string|max:500';
                $rules['data.comment'] = 'nullable|string|max:1000';
                $rules['data.thumbImgUrl'] = 'nullable|url|max:2000';
                break;

            case 'postQQMusic':
                $rules['data.title'] = 'required|string|max:200';
                $rules['data.url'] = 'required|url|max:2000';
                $rules['data.musicUrl'] = 'required|url|max:2000';
                $rules['data.appInfo'] = 'nullable|string|max:500';
                break;
        }

        // 如果有附加消息，验证附加消息
        if ($this->has('addition')) {
            $rules['addition.type'] = 'required|string|in:text,link,card,image,music';
            
            switch ($this->input('addition.type')) {
                case 'text':
                    $rules['addition.data.content'] = 'required|string|max:10000';
                    break;
                    
                case 'link':
                    $rules['addition.data.url'] = 'required|url|max:2000';
                    $rules['addition.data.title'] = 'nullable|string|max:200';
                    $rules['addition.data.description'] = 'nullable|string|max:500';
                    $rules['addition.data.image'] = 'nullable|url|max:2000';
                    break;
                    
                case 'card':
                    $rules['addition.data.wxid'] = 'required|string|max:100';
                    break;
                    
                case 'image':
                    $rules['addition.data.url'] = 'required|url|max:2000';
                    break;
                    
                case 'music':
                    $rules['addition.data.url'] = 'required|url|max:2000';
                    $rules['addition.data.title'] = 'required|string|max:200';
                    $rules['addition.data.description'] = 'nullable|string|max:500';
                    $rules['addition.data.coverUrl'] = 'nullable|url|max:2000';
                    $rules['addition.data.lyrics'] = 'nullable|string|max:1000';
                    break;
            }
        }

        return $rules;
    }

    /**
     * 获取自定义验证错误消息
     */
    public function messages(): array
    {
        return [
            'type.required' => '消息类型是必需的',
            'type.in' => '不支持的消息类型',
            'to.required' => '接收方是必需的',
            'data.content.required' => '消息内容是必需的',
            'data.content.max' => '消息内容不能超过10000个字符',
            'data.url.required' => 'URL是必需的',
            'data.url.url' => 'URL格式无效',
            'data.wxid.required' => '联系人微信ID是必需的',
            'data.at.required' => '@列表是必需的',
            'data.at.array' => '@列表必须是数组格式',
        ];
    }

    /**
     * 获取自定义属性名称
     */
    public function attributes(): array
    {
        return [
            'type' => '消息类型',
            'to' => '接收方',
            'data.content' => '消息内容',
            'data.url' => 'URL',
            'data.title' => '标题',
            'data.description' => '描述',
            'data.image' => '图片URL',
            'data.wxid' => '微信ID',
            'data.at' => '@列表',
        ];
    }
}