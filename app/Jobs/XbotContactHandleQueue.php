<?php

namespace App\Jobs;

use App\Models\WechatBot;
use App\Services\Chatwoot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class XbotContactHandleQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $wechatBot;
    public $contact;
    public $label;
    protected Chatwoot $chatwoot;

    /**
     * Create a new job instance.
     *
     * @param WechatBot $wechatBot
     * @param array $contacts
     * @param Chatwoot $chatwoot
     */
    public function __construct(WechatBot $wechatBot, array $contact, $label)
    {
        $this->wechatBot = $wechatBot;
        $this->contact = $contact;
        $this->label = $label;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->chatwoot = new Chatwoot($this->wechatBot);
        $contact = $this->contact;
        // 检查联系人是否已存在
        $existingContact = $this->chatwoot->searchContact($contact['wxid']);
        if ($existingContact) {
            // 更新现有联系人
            $this->updateChatwootContact($existingContact, $contact);
            $savedContact = $existingContact;
        } else {
            // 创建新联系人
            $savedContact = $this->chatwoot->saveContact($contact);
        }
        
        // 检查联系人是否保存成功
        if ($savedContact && isset($savedContact['id'])) {
            // 设置联系人标签
            $this->chatwoot->setLabel($savedContact['id'], $this->label);
        } else {
            Log::error('Failed to save contact, skipping label assignment', [
                'wxid' => $contact['wxid'],
                'label' => $this->label,
                'saved_contact' => $savedContact
            ]);
        }
    }

    /**
     * 更新Chatwoot联系人信息
     */
    protected function updateChatwootContact(array $existingContact, array $newContact): void
    {
        $contactId = $existingContact['id'];

        // 检查是否需要更新名称
        $currentName = $existingContact['name'] ?? '';
        $newName = $newContact['remark'] ?? $newContact['nickname'] ?? $newContact['wxid'];

        if ($currentName !== $newName) {
            $this->chatwoot->updateContactName($contactId, $newName);
        }

        // 检查是否需要更新头像
        $currentAvatar = $existingContact['avatar_url'] ?? $existingContact['custom_attributes']['avatar_url'] ?? '';
        $newAvatar = $newContact['avatar'] ?? '';

        if ($currentAvatar !== $newAvatar && !empty($newAvatar)) {
            $this->chatwoot->updateContactAvatarById($contactId, $newAvatar);
        }
    }

}
