<?php

declare(strict_types=1);

namespace Amid\Sms\Models;

use Amid\Sms\Templates\PlaceholderParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One logical message, such as `order-created`.
 *
 * ⚠️ It is NOT classified as pattern or text. The same logical message may be a
 * registered pattern at one gateway and free text at another, so how it is
 * delivered lives on the binding (SmsTemplateGateway) and never here.
 *
 * ⚠️ `is_sensitive` IS a property of the wording, though: a login-code template
 * is sensitive at every gateway, on every send, for everybody. A caller may force
 * a single send to be sensitive as well, but nothing can turn this off.
 */
class SmsTemplate extends Model
{
    protected $table = 'sms_templates';

    protected $fillable = ['key', 'name', 'body', 'is_sensitive'];

    protected $attributes = [
        'is_sensitive' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
        ];
    }

    public function gatewayBindings(): HasMany
    {
        return $this->hasMany(SmsTemplateGateway::class, 'sms_template_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class, 'sms_template_id');
    }

    /**
     * The variables this wording refers to, in reading order.
     *
     * Derived on read rather than stored: there is exactly one source of truth for
     * it — the body — and a stored copy is a copy that can disagree with the text
     * it claims to describe.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return PlaceholderParser::extract((string) $this->body);
    }
}
