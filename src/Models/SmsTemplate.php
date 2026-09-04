<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Models;

use Mizbanha\Sms\Enums\RoutingStrategy;
use Mizbanha\Sms\Exceptions\InvalidRoutingConfiguration;
use Mizbanha\Sms\Support\TableNames;
use Mizbanha\Sms\Templates\PlaceholderParser;
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
 *
 * ⚠️ `routing_strategy` is a property of the logical message too, for a different
 * reason: a one-time code and an order notification want different policies about
 * where to start, and one global setting cannot express both. It decides SELECTION
 * only - which gateway is offered the message first, and in what order the rest
 * follow - and never failover, which is decided from what a provider actually
 * says.
 */
class SmsTemplate extends Model
{

    protected $fillable = ['key', 'name', 'body', 'is_sensitive', 'routing_strategy'];

    protected $attributes = [
        'is_sensitive' => false,
        // The behaviour the package had before routing strategies existed, so a
        // template nobody has thought about routes exactly as it always did.
        'routing_strategy' => RoutingStrategy::Priority->value,
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'routing_strategy' => RoutingStrategy::class,
        ];
    }

    /**
     * ⚠️ Resolved on every query rather than declared in `$table`, so a configured
     * name reaches relations, eager loads and queued jobs. See `SmsGateway` for the
     * full reasoning, and `Mizbanha\Sms\Support\TableNames` for the map.
     */
    public function getTable(): string
    {
        return $this->table ?? TableNames::templates();
    }

    /**
     * ⚠️ A named error rather than a raw ValueError, exactly as with a gateway's
     * country policy: the enum cast would refuse an unknown value on its own, with
     * a message about enum backing values that means nothing to whoever typed it.
     * This says which three words are allowed. Case and surrounding space are
     * forgiven; a misspelling is not, because the alternative is a template quietly
     * routed by a policy nobody chose.
     */
    public function setRoutingStrategyAttribute(mixed $value): void
    {
        $strategy = $value instanceof RoutingStrategy
            ? $value
            : RoutingStrategy::tryFrom(strtolower(trim((string) $value)));

        $this->attributes['routing_strategy'] = ($strategy
            ?? throw InvalidRoutingConfiguration::unknownStrategy((string) $value))->value;
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
