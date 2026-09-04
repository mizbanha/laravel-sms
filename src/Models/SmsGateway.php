<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Models;

use Mizbanha\Sms\Contracts\PhoneNormalizer;
use Mizbanha\Sms\Enums\CountryPolicy;
use Mizbanha\Sms\Exceptions\InvalidCountryCoverage;
use Mizbanha\Sms\Gateways\GatewayConfig;
use Mizbanha\Sms\Support\TableNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configured gateway. The runtime source of truth for where messages go.
 *
 * ⚠️ **credentials is encrypted at rest and hidden from every serialisation.**
 * `$hidden` covers toArray/toJson, which is what a log line, a queue payload and a
 * dumped model all go through; the cast covers the column. A management layer that
 * wants to edit credentials has to ask for them deliberately, and must never send
 * the stored values back to a form — see the package README.
 *
 * @property array<string, string>|null $credentials
 * @property list<string>|null $countries
 */
class SmsGateway extends Model
{

    protected $fillable = [
        'key', 'label', 'driver', 'sender', 'is_enabled', 'priority', 'options',
        'country_policy', 'countries',
    ];

    /**
     * ⚠️ Never remove `credentials` from this list.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected $attributes = [
        'is_enabled' => false,
        'priority' => 100,
        // Serve everywhere unless told otherwise. A gateway that has never been
        // given a country list should behave exactly as it did before this feature
        // existed.
        'country_policy' => 'all',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'countries' => 'array',
            'country_policy' => CountryPolicy::class,
            'is_enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * ⚠️ Resolved through `getTable()` rather than declared in `$table`, and every
     * model in this package does the same.
     *
     * A `protected $table = '…'` is read once when the class is loaded, so it
     * cannot answer to configuration; `getTable()` is asked on every query, which
     * is what makes a configured name reach relations, eager loads, joins and
     * anything Eloquent builds on its own. It also keeps the name out of the
     * serialized model on a queue — a job encoded under one table map and run under
     * another would otherwise write to whichever table it remembered.
     *
     * `$this->table` is still honoured when something has set it explicitly, so
     * `setTable()` and a host subclass both behave exactly as Eloquent documents.
     */
    public function getTable(): string
    {
        return $this->table ?? TableNames::gateways();
    }

    public function templateBindings(): HasMany
    {
        return $this->hasMany(SmsTemplateGateway::class, 'sms_gateway_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Whether this gateway is meant to serve this destination country at all.
     *
     * ⚠️ Routing, not failure. A gateway that answers false here is never called,
     * records no attempt, and is not a provider failure of any kind - it was simply
     * not for this destination. That distinction matters: an attempt row is
     * evidence about a provider, and filling the log with "Twilio refused an
     * Iranian number" for a gateway nobody expected to carry Iranian numbers would
     * make the evidence worse, not better.
     *
     * Separate from what a provider says at runtime. A Twilio gateway configured
     * for the UAE is eligible here and may still be refused by Twilio with 21408
     * because that account's Geo Permissions are off - which is a real attempt, a
     * real error, and fails over normally. Configured geography and account
     * permission are two different facts and neither substitutes for the other.
     *
     * @param  string|null  $region  ISO 3166-1 alpha-2, or null for a destination
     *                               with no country
     */
    public function serves(?string $region): bool
    {
        return $this->country_policy->covers($region, $this->countries ?? []);
    }

    /**
     * ⚠️ A named error rather than a raw ValueError.
     *
     * The enum cast would reject an unknown value on its own, with a message about
     * enum backing values that means nothing to whoever typed it into a form. This
     * says which three words are allowed. Case is forgiven; anything else is not.
     *
     * @param  mixed  $value
     */
    public function setCountryPolicyAttribute($value): void
    {
        $policy = $value instanceof CountryPolicy
            ? $value
            : CountryPolicy::tryFrom(strtolower(trim((string) $value)));

        $this->attributes['country_policy'] = ($policy
            ?? throw InvalidCountryCoverage::unknownPolicy((string) $value))->value;
    }

    /**
     * ⚠️ Validated on the way IN, deliberately.
     *
     * A country list is small, rarely edited configuration, and the moment somebody
     * types it is the only moment there is anybody around to be told they got it
     * wrong. Accept `UK` silently and it becomes a gateway that never routes
     * anything, which looks exactly like a gateway with no traffic.
     *
     * Normalised as well as validated: trimmed, uppercased, de-duplicated and
     * re-indexed, so `[' ir ', 'IR']` is stored once as `["IR"]` and the router
     * compares like with like.
     *
     * @param  mixed  $value
     * @return list<string>|null
     */
    public function setCountriesAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['countries'] = null;

            return;
        }

        $codes = [];

        foreach ((array) $value as $code) {
            if (! is_string($code) && ! is_int($code)) {
                throw InvalidCountryCoverage::malformed(get_debug_type($code));
            }

            $code = strtoupper(trim((string) $code));

            // Shape first: it catches `IRAN`, `iran` and `123` without asking the
            // numbering data anything.
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                throw InvalidCountryCoverage::malformed($code);
            }

            // Then whether it is a region a destination could actually be
            // classified as. This is the check that catches `UK`.
            if (! app(PhoneNormalizer::class)->supportsRegion($code)) {
                throw InvalidCountryCoverage::unknownRegion($code);
            }

            $codes[$code] = true;
        }

        $this->attributes['countries'] = json_encode(array_keys($codes));
    }

    /**
     * The settings a driver is built with.
     *
     * Built here so a driver never touches the model, and so the credentials pass
     * through exactly one object that knows they are secret.
     */
    public function config(): GatewayConfig
    {
        return new GatewayConfig(
            key: (string) $this->key,
            sender: $this->sender === null ? null : (string) $this->sender,
            credentials: $this->credentials ?? [],
            options: $this->options ?? [],
        );
    }
}
