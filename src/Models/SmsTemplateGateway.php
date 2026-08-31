<?php

declare(strict_types=1);

namespace Amid\Sms\Models;

use Amid\Sms\Enums\DeliveryMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one logical template is carried by one gateway.
 *
 * The row that makes decision "templates are logical messages" work: mode, the
 * code this provider knows the pattern by, and the names and ORDER this provider
 * wants the parameters in. All of it is a property of the pairing, not of either
 * side.
 *
 * ⚠️ `parameter_map` is an ordered JSON ARRAY, never an object:
 *
 *     [{"provider": "token", "variable": "customer_name"}, ...]
 *
 * A JSON object's key order is not preserved by MySQL, and at a positional
 * provider that order is the difference between a name and an amount. See
 * Amid\Sms\Templates\ParameterMapper.
 *
 * @property list<array{provider?: string|null, variable: string}>|null $parameter_map
 */
class SmsTemplateGateway extends Model
{
    protected $table = 'sms_template_gateways';

    protected $fillable = [
        'sms_template_id',
        'sms_gateway_id',
        'mode',
        'pattern_code',
        'parameter_map',
        'is_enabled',
    ];

    protected $attributes = [
        'mode' => DeliveryMode::Text->value,
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'mode' => DeliveryMode::class,
            'parameter_map' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(SmsGateway::class, 'sms_gateway_id');
    }

    /**
     * Whether this binding is complete enough to be used.
     *
     * ⚠️ A pattern binding with no code is unusable, and the router drops it
     * rather than attempting it. A pattern is chosen precisely because free text
     * would not arrive; silently sending the text instead would look like success
     * and reach a fraction of the recipients.
     */
    public function isUsable(): bool
    {
        return $this->mode !== DeliveryMode::Pattern
            || (is_string($this->pattern_code) && trim($this->pattern_code) !== '');
    }
}
