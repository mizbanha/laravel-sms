<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Models;

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Exceptions\InvalidRoutingConfiguration;
use Mizbanha\Sms\Support\TableNames;
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
 * Mizbanha\Sms\Templates\ParameterMapper.
 *
 * @property list<array{provider?: string|null, variable: string}>|null $parameter_map
 */
class SmsTemplateGateway extends Model
{

    protected $fillable = [
        'sms_template_id',
        'sms_gateway_id',
        'mode',
        'pattern_code',
        'parameter_map',
        'is_enabled',
        'weight',
    ];

    protected $attributes = [
        'mode' => DeliveryMode::Text->value,
        'is_enabled' => true,
        'weight' => self::DEFAULT_WEIGHT,
    ];

    protected function casts(): array
    {
        return [
            'mode' => DeliveryMode::class,
            'parameter_map' => 'array',
            'is_enabled' => 'boolean',
            'weight' => 'integer',
        ];
    }

    /**
     * ⚠️ Resolved on every query rather than declared in `$table`, so a configured
     * name reaches relations, eager loads and queued jobs. See `SmsGateway` for the
     * full reasoning, and `Mizbanha\Sms\Support\TableNames` for the map.
     */
    public function getTable(): string
    {
        return $this->table ?? TableNames::templateGateways();
    }

    /**
     * An equal share. A binding nobody has weighted takes the same traffic as
     * every other, which is what round-robin would have done anyway.
     */
    public const DEFAULT_WEIGHT = 1;

    /**
     * ⚠️ An upper bound, not because anything here would break above it, but
     * because a weight is a RATIO and nothing is expressed at 60000 that is not
     * expressed at 6. What a five-digit weight actually indicates is somebody
     * typing a phone number, a message count or a budget into the wrong field, and
     * the resulting cycle - tens of thousands of consecutive messages down one
     * gateway before the next is offered anything - looks exactly like routing
     * being broken.
     */
    public const MAXIMUM_WEIGHT = 1000;

    /**
     * ⚠️ Validated on the way IN, like every other piece of routing configuration.
     *
     * Zero is the case worth refusing loudly. It is a binding an administrator has
     * created, enabled and expects to see traffic on, and it would receive none -
     * a gateway that looks configured, looks healthy, and is never called. A whole
     * binding set of zeroes is worse still: a total of nothing to divide by.
     */
    public function setWeightAttribute(mixed $value): void
    {
        if (! is_numeric($value) || (float) $value !== floor((float) $value)) {
            throw InvalidRoutingConfiguration::weight($value, self::MAXIMUM_WEIGHT);
        }

        $weight = (int) $value;

        if ($weight < 1 || $weight > self::MAXIMUM_WEIGHT) {
            throw InvalidRoutingConfiguration::weight($value, self::MAXIMUM_WEIGHT);
        }

        $this->attributes['weight'] = $weight;
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
