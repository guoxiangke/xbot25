<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WechatAddFriendRequest extends FormRequest
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
        return [
            'telephone' => 'required|string|regex:/^1[3-9]\d{9}$/',
            'message' => 'nullable|string|max:200',
        ];
    }

    /**
     * 获取自定义验证错误消息
     */
    public function messages(): array
    {
        return [
            'telephone.required' => '手机号是必需的',
            'telephone.regex' => '手机号格式无效',
            'message.max' => '验证消息不能超过200个字符',
        ];
    }

    /**
     * 获取自定义属性名称
     */
    public function attributes(): array
    {
        return [
            'telephone' => '手机号',
            'message' => '验证消息',
        ];
    }
}