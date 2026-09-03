# amidesfahani/laravel-sms

A provider-neutral SMS package for Laravel. Gateways, templates and per-gateway parameter mapping live in
the database and are editable at runtime; providers are code.

**Status: M9.** One templated message, routed to the gateways that serve its destination country by one
of three selection strategies, failed over across them, recorded as a message plus one attempt per
handover, synchronously or through the queue, with a structured result. Drivers: Kavenegar, SMS.ir, IPPanel and
Melipayamak (Iranian), Twilio (international), plus `log`, which writes to a log channel and contacts
nobody. Sensitive messages and package-owned OTP are built, delivery status can be refreshed from Twilio
and IPPanel, and a gateway that stops answering is skipped for a cooldown instead of costing every message
a timeout. Webhooks and any administration UI are not built.

⚠️ **No driver has ever made a real request to its provider.** Every one is written from official
documentation and verified against faked responses. Treat a first production send as the real test.

## Requirements

PHP 8.3+, Laravel 13+. An `APP_KEY` is required — gateway credentials are encrypted at rest.

The cache store used for the processing lock must support atomic locks: `database`, `redis`, `memcached`,
`dynamodb`. The `array` store works within one process only, and the `file` store within one machine only;
a store with no lock support at all is refused rather than silently permitted to allow duplicate sends.

## Install

```bash
composer require amidesfahani/laravel-sms
php artisan vendor:publish --tag=laravel-sms-config
php artisan migrate
```

That is the whole installation. The service provider is discovered automatically, and **the five package
migrations are loaded from the package** — `php artisan migrate` runs them where they are, and they stay
in step with the package when it is upgraded. Publishing them is offered
(`--tag=laravel-sms-migrations`) for the one case that needs it: a project that must edit the schema, which then
owns the copies. Do one or the other, not both.

The config file is the opposite way round: publish it, because it is yours to edit. Everything unset in
your copy falls back to the package's defaults.

⚠️ **The file is `config/laravel-sms.php`, and the key is `config('laravel-sms.*')` — deliberately not
`config/sms.php`.** `sms` is too generic a name for a package to take, and an application that already has
an SMS subsystem has almost certainly taken it. Laravel merges a package's configuration into the
application's shallowly, with the application's file on top, so two files under one key do not coexist:
for every top-level key both define one wins outright and the other disappears, with no error and with
each side still reading what it believes are its own settings. Keeping the generic name free means
**your** `config/sms.php` and this package can sit side by side with no bridge, no merge and no precedence
rule between them. There is no fallback to `sms.*` and no way to choose the namespace from the
environment.

Environment variables are unchanged and still read `SMS_*`. They were never what collided, and the
package config key and the environment are separate contracts.

⚠️ **Publish the config before you migrate, in that order.** It is the only chance to change the table
names, and after the migration has run they are a schema you own rather than a setting you configure —
see [Table names](#table-names).

Then switch it on, per environment:

```dotenv
SMS_ENABLED=true
```

**It is off by default, and that is deliberate.** Gateways live in the database, so a production database
restored onto a staging machine arrives complete with working credentials, enabled gateways and real
people's real numbers. Sending has to be opted into by an environment file, never inherited from a dump.
With it off, every message is still recorded, as `suppressed`, and nothing reaches a phone.

## Table names

This package creates five tables. By default they are called:

```text
sms_gateways   sms_templates   sms_template_gateways   sms_messages   sms_attempts
```

**If your application already owns a table with one of those names, map this package somewhere else
before you run its migrations.** `Schema::create()` refuses a name that already exists, so the first
migration fails outright rather than merging into your data — which is the safe failure, but it is still
a failure. In your published `config/laravel-sms.php`:

```php
'tables' => [
    'gateways'          => 'sms_core_gateways',
    'templates'         => 'sms_core_templates',
    'template_gateways' => 'sms_core_template_gateways',
    'messages'          => 'sms_core_messages',
    'attempts'          => 'sms_core_attempts',
],
```

Everything follows: the migrations, the models, every relation, the foreign keys, the index names and the
one raw join in the router. Nothing else in your application changes, and no management layer built on
this package needs to know — it reads the tables through these models.

**Set them before the first migration.** ⚠️ **This is a schema mapping, not a rename.** Changing a name
on an installation that has already migrated moves nothing: this package simply starts looking for a
table that is not there, and your rows stay where they were. If you need to move an installed schema,
that is a migration your application writes, and it is yours to write — this package will not attempt it
for you, because a silent automatic table rename is not something a package should ever do to a
production database.

A few rules, all of which throw rather than falling back:

- a name may not be empty, blank, or padded with whitespace;
- no two of the five may share a name;
- no dots, quotes, backticks, backslashes or semicolons — a dot in particular is read by Laravel as a
  schema separator and would silently split the name in two;
- at most 30 characters. Not because table names may not be longer, but because Laravel builds **index**
  names out of them and MySQL allows only 64 for those too; the longest index here adds 34.

⚠️ A configured name is never quietly replaced by a default. An application configures these precisely
because it already owns `sms_messages`, so falling back on a typo would point this package at your data
and write to it.

## Configure a gateway

```php
use Amid\Sms\Models\SmsGateway;

SmsGateway::create([
    'key' => 'kavenegar-main',
    'label' => 'Kavenegar',
    'driver' => 'kavenegar',          // a key of config('laravel-sms.drivers')
    'sender' => '30001234',
    'priority' => 10,                 // lower goes first
])->forceFill([
    'credentials' => ['api_key' => '...'],   // encrypted at rest
    'is_enabled' => true,                    // gateways are created disabled
])->save();
```

### Where a gateway sends

A gateway declares which countries it is meant to serve. This is runtime configuration on the gateway
row — **no driver knows it exists**, and the same driver can serve one account permitted to message
thirty countries and another permitted to message one.

```php
use Amid\Sms\Enums\CountryPolicy;

// Everywhere. The default; a gateway that ignores this behaves as it always did.
$gateway->country_policy = CountryPolicy::All;

// Only these countries.
$gateway->country_policy = CountryPolicy::Allow;
$gateway->countries = ['IR'];

// Everywhere except these. Twilio, for instance, documents that it does not
// deliver to Iran, Syria or Cuba.
$gateway->country_policy = CountryPolicy::Deny;
$gateway->countries = ['IR', 'SY', 'CU'];
```

Codes are ISO 3166-1 alpha-2, normalised on write (trimmed, uppercased, de-duplicated) and **validated**
— `IRAN`, `123` and `UK` are all refused. The United Kingdom is `GB`; accepting `UK` silently would give
you a gateway that never sends and no error to explain why.

The destination's country is derived once, from the normalised number, and stored on the message as
`country_code`. A gateway that does not serve it is **never contacted**: no request, no attempt row, no
provider failure, no failover budget spent. That is routing, not failure.

⚠️ **Coverage is not permission.** It says what an account is *for*; it cannot say what the provider will
accept. A Twilio gateway configured for the UAE is routed to correctly and may still be refused with
error 21408 because that account's Geo Permissions are off — which is a real attempt, a real error, and
ordinary failover.

Some valid numbers belong to no country — satellite and international-network ranges. The package does
not invent one for them: `country_code` is null, `all` still serves them, `allow` does not, and `deny`
does.

## Define a message

A template is a **logical** message. It is not "a pattern" or "a text" — how it is carried is a property of
the template/gateway pairing, so the same message can be a registered pattern at one provider and free text
at another.

```php
use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;

$template = SmsTemplate::create([
    'key' => 'order-created',
    'name' => 'Order created',
    'body' => 'Hi {customer_name}, order {order_number} for {total} is placed.',
]);

SmsTemplateGateway::create([
    'sms_template_id' => $template->id,
    'sms_gateway_id' => $kavenegar->id,
    'mode' => DeliveryMode::Pattern,
    'pattern_code' => 'order-created',     // what THIS provider calls it
    // The parameters IN ORDER. Kavenegar numbers rather than names them, so the
    // position of an entry is what decides which token a value becomes.
    'parameter_map' => [
        ['provider' => 'token', 'variable' => 'customer_name'],
        ['provider' => 'token2', 'variable' => 'order_number'],
        ['provider' => 'token3', 'variable' => 'total'],
    ],
]);

SmsTemplateGateway::create([
    'sms_template_id' => $template->id,
    'sms_gateway_id' => $smsir->id,
    'mode' => DeliveryMode::Pattern,
    'pattern_code' => '100200',
    // The same message at a provider that names its parameters instead.
    'parameter_map' => [
        ['provider' => 'CUSTOMER', 'variable' => 'customer_name'],
        ['provider' => 'ORDER_NO', 'variable' => 'order_number'],
        ['provider' => 'AMOUNT', 'variable' => 'total'],
    ],
]);
```

Leave `parameter_map` null and the template's own variable names are used, in body order.

### The mapping is an ordered list, not an object

`parameter_map` is a JSON **array**, and the array order is the parameter order. That is not a
stylistic preference: a JSON object has no ordering contract, and MySQL normalises the key order of
one when it stores it — sorted by key length, then bytewise. At a provider that numbers its
parameters, that order is the difference between a customer's name and the amount they owe, so it is
stored as something that is ordered by definition.

`provider` may be omitted or null for a provider that only counts its parameters:

```php
'parameter_map' => [
    ['variable' => 'customer_name'],
    ['variable' => 'order_number'],
],
```

At a provider that does name its parameters, an entry with no `provider` falls back to your own
variable name. A map that is stored as an object, has a duplicate `provider`, or has a malformed
entry is refused as a gateway configuration failure rather than guessed at — nothing is sent, and
the message can still go out through a gateway whose mapping is intact.

## Sensitive messages

A template can be marked as carrying something that must not be kept:

```php
SmsTemplate::create([
    'key' => 'login-otp',
    'name' => 'Login code',
    'body' => 'Your login code is {code}.',
    'is_sensitive' => true,
]);
```

A caller can also force it for one send, which **raises** sensitivity and can never lower it:

```php
Sms::to($phone)->template('login-otp')->with([...])->sensitive()->send();
```

For a sensitive message the package records that it was sent, to whom, through which gateway and with
what result — and deliberately does not record what it said:

| | Ordinary | Sensitive |
|---|---|---|
| `sms_messages.body` | rendered text | **null** |
| `sms_messages.variables` | the values | **null** |
| `sms_attempts.provider_payload` | the response | **null** |
| `sms_attempts.error` | the provider's words | **null** |
| `sms_attempts.delivery_error` | the delivery reason | **null** |
| `LogDriver` output | the body | metadata plus `[sensitive content omitted]` |

Null rather than masked: `"******"` looks like data, and the fact worth recording is that the value was
deliberately omitted.

⚠️ **No free-form provider text is persisted for a sensitive message at all** — not the refusal, not the
delivery reason. Several providers quote the request back inside an error ("the text «...» was rejected"),
and a scrub that removes the values it happens to know about is a partial defence presented as a
guarantee: it cannot remove a one-character value without destroying every diagnostic that contains that
character, which is exactly the exemption that made the earlier version unsafe. The audit trail is the
structured facts — outcome, failure kind, both policy flags, gateway, driver, sequence, provider message
id — and all of them are kept.

⚠️ **The consequence is intended: a sensitive message cannot be re-sent from history.** There is nothing
to rebuild it from. An expired code should be re-requested, not replayed. Immediate failover and queue
retry of the same job are unaffected — the values are still in flight.

Queued jobs are encrypted (Laravel's `ShouldBeEncrypted`), so a queued code is never sitting in clear
text in a `jobs` row or a Redis key. This applies to every send job, not only sensitive ones.

## One-time codes

The package generates and verifies the code; a gateway only delivers it. That is what lets **one code
fail over between providers** — a provider-generated code exists only inside that provider.

```php
use Amid\Sms\Facades\Otp;
use Amid\Sms\Otp\OtpStatus;

$result = Otp::send($phone, 'login-otp');

match ($result->status) {
    OtpStatus::Sent       => 'ask for the code',
    OtpStatus::Cooldown   => "wait {$result->retryAfter}s",
    OtpStatus::Unknown    => 'it may have arrived; ask for the code',
    OtpStatus::Failed     => 'try again',
    OtpStatus::Suppressed => 'sending is off in this environment',
};

// The third argument is the PURPOSE, which defaults to the template key on send.
if (Otp::verify($phone, $typed, 'login-otp')) {
    // your application decides what that means
}
```

⚠️ The purpose is required on `verify()`. It is the only thing that says which challenge is being
answered, and a signature that let you omit it would quietly reject every correct code.

An OTP template is an ordinary template: bound to gateways the ordinary way, routed by capability and
country, failed over by the ordinary rules. There is no OTP gateway, no OTP driver and no
`laravel-sms.otp_driver`.

The code arrives as the logical variable `code`, so each gateway's `parameter_map` translates it into
whatever that provider calls it. A caller supplying `code` itself is rejected.

**Purposes.** One number can hold several challenges at once:

```php
Otp::send($phone, 'login-otp', purpose: 'login');
Otp::send($phone, 'confirm-otp', purpose: 'withdrawal');
```

The purpose defaults to the template key.

**What it guarantees.** The code is stored hashed, never in plaintext. Cache keys are a SHA-256 of the
canonical number and the purpose, so the store is not an enumerable list of who is being messaged. A code
is single use. A wrong guess costs an attempt and does **not** extend the expiry; spending the attempt
budget destroys the challenge, so the correct code fails afterwards too. A resend inside the cooldown
issues nothing and leaves the existing code valid; after it, the new code replaces the old one
immediately.

⚠️ **`Otp::send()` never returns the code**, and there is no accessor for it. A result carrying it would
put it in every stack trace and exception report. Bind your own `OtpCodeGenerator` in tests if you need
to know it.

⚠️ **Every OTP send is sensitive**, whether or not the template says so — OTP safety must not depend on
somebody having ticked a box.

Defaults (`config/laravel-sms.php`): 6 digits, 180s expiry, 90s resend cooldown, 5 attempts. `Otp::send()` is
synchronous: a code is worth ninety seconds, and immediate multi-gateway failover already provides the
availability a queue would have been for.

The package supplies the challenge and nothing else — no routes, no controllers, no middleware, and no
notion of a user. When to challenge somebody is your application's decision.

## Send

```php
use Amid\Sms\Facades\Sms;

Sms::to('09121234567')
    ->template('order-created')
    ->with([
        'customer_name' => 'Amid',
        'order_number' => 'CF-1204',
        'total' => '1,850,000',
    ])
    ->about($order)   // optional context, recorded and never interpreted
    ->queue();        // or ->send() to deliver during this request
```

Variables are **logical names and plain values**. Never a model, never a path like `order.customer.name` —
the package has no way to resolve one and no business knowing what your models are called. Deciding *when*
to send is your application's job; this package only carries the message.

### Pinning a send to one gateway

Ordinary sending picks a gateway for you, and every mechanism in this package is built to move a message
*away* from one that is failing. `viaGateway()` is for the opposite question — **does this one gateway
work** — which is what somebody needs after typing in a new API key:

```php
Sms::to('09121234567')
    ->template('connectivity-check')
    ->with(['customer_name' => 'Amid'])
    ->viaGateway('kavenegar-main')   // a gateway key, or an SmsGateway
    ->send();
```

A pinned send is **an ordinary send with a narrower candidate list**, not a mode and not a bypass.
Everything still applies, decided by the same code: the master switch, phone normalisation, the country
the gateway serves, the gateway's and binding's enabled state, the capability the binding's mode requires,
the circuit breaker, parameter mapping, the sensitive-message policy, and the `SmsMessage` and
`SmsAttempt` rows that record what happened.

- ⚠️ **It never fails over.** At most one gateway is a candidate, so there is nothing to move on to. If the
  pinned gateway is ineligible, unusable, circuit-open or simply refuses, that *is* the answer — quietly
  proving a different gateway would answer a question nobody asked.
- ⚠️ **It never advances a routing cursor.** Round-robin and weighted round-robin state is not read and not
  written, so a test send cannot change which gateway the next real message goes to.
- ⚠️ **It never bypasses an open circuit.** Reset the circuit first, deliberately, if that is what you mean.
- ⚠️ **It requires a binding.** The gateway must be bound to the template being sent; pinning does not
  invent configuration. There is deliberately no template-less "send arbitrary text through this gateway"
  API — a message with no logical template could not be mapped, recorded or explained.
- ⚠️ **Synchronous only.** `->viaGateway(...)->queue()` throws rather than silently sending unpinned: the
  caller of a pinned send is waiting for an answer, and a queued one answers nobody.

## What throws and what is recorded

A **caller mistake throws**, before anything is written or sent: no template, an unknown template key, an
unusable phone number, a variable the wording needs that you did not supply.

**Everything from the gateway onward is recorded, never thrown**: no enabled gateway, a provider refusal, a
timeout. Sending is almost always a side effect of something more important, and an exception there would
roll back the order that the message was merely announcing. Read the outcome off the message and its
attempts.

## Results

Every driver returns a `SendResult`, and nothing above a driver reads an HTTP status or an exception message
to decide what happens next:

| Field | Meaning |
|---|---|
| `outcome` | `accepted`, `rejected` or `uncertain` |
| `failureKind` | provider-neutral classification of a failure |
| `retryableOnSameGateway` | trying this same gateway again could plausibly work |
| `safeToFailover` | known not-sent, so another gateway may carry it without duplicating |
| `providerMessageId` | the provider's own id, for delivery lookups and disputes |
| `error` | the reason, truncated, with every configured credential stripped out |

`uncertain` is the case that matters. A timeout, or a 5xx, means the request arrived and may have been
processed — so the message settles as `unknown` and is **never** automatically re-sent. Assuming otherwise
is how one order confirmation becomes two.

## Routing strategies

Which gateway does a message **start** at? That is a separate question from what happens once that
gateway has answered, and the two are configured separately.

| Strategy | What it does |
|---|---|
| `priority` | the configured order, every time. **The default**, and the behaviour of every earlier version |
| `round_robin` | each new message starts one gateway further along, wrapping at the end |
| `weighted_round_robin` | the same, over unequal shares |

**Strategy is a property of the logical message**, on the template row — not a global setting, because
different messages want different policies:

```php
use Amid\Sms\Enums\RoutingStrategy;
use Amid\Sms\Models\SmsTemplate;

// A login code should start at the most reliable line, every single time.
SmsTemplate::where('key', 'login-otp')->first()
    ->update(['routing_strategy' => RoutingStrategy::Priority]);

// Order notifications are ordinary traffic worth spreading over the accounts
// you are paying for either way.
SmsTemplate::where('key', 'order-created')->first()
    ->update(['routing_strategy' => RoutingStrategy::WeightedRoundRobin]);
```

**Weights are a property of the template/gateway binding**, for the same reason: how much of *this*
message a gateway carries is a fact about the pairing, not about the gateway.

```php
$template->gatewayBindings()->where('sms_gateway_id', $kavenegar->id)->update(['weight' => 5]);
$template->gatewayBindings()->where('sms_gateway_id', $smsir->id)->update(['weight' => 3]);
$template->gatewayBindings()->where('sms_gateway_id', $ippanel->id)->update(['weight' => 2]);
```

⚠️ **Weights are ratios, not percentages.** `5, 3, 2` gives half, a third and a fifth of the primary
selections — and so does `50, 30, 20`. Nothing has to add up to a hundred, so adding a fourth gateway
never means editing the other three. The default is `1`, an equal share; the range is 1 to 1000.

⚠️ **Weighted round-robin is deterministic, not random.** `5, 3, 2` produces a repeating cycle of ten:

```text
A A A A A B B B C C   A A A A A B B B C C   …
```

Over any complete cycle the counts are exactly five, three and two. A weighted *random* draw would give
that ratio only in the long run, and "eventually, on average" is not something you can hold a provider
contract to or reproduce from a bug report.

### Routing is not failover

A strategy decides an **order**. Everything below in *Failover* still applies unchanged — most
importantly, an uncertain result still stops a message permanently and never moves it to another gateway.
Rotating a candidate list is never a reason to hand one person's message to a second provider.

After the primary is chosen, the rest of the chain is deterministic:

- `round_robin` **rotates**: with A, B, C and B leading, the chain is B → C → A.
- `weighted_round_robin` does **not** rotate: the primary leads, then the rest in configured priority
  order. Weights govern primary selection; who you fail over *to* is what priority is for.

### What gets a share, and what does not

Only gateways that could actually carry the message take part: enabled gateway, enabled and complete
binding, compatible capability, and configured for the destination's country. A gateway excluded by any
of those has **no turn at all** — the cycle is simply shorter — so it never costs a message.

⚠️ The **circuit breaker** narrows it further. A gateway whose circuit is open takes no share, because a
share allocated to a gateway this application already knows it will not call is a share of the traffic
that silently goes nowhere. A **half-open** gateway does take part: it is owed one recovery probe, and the
ordinary rotation is how it gets one. When the usable set changes, the cycle simply starts fresh —
fairness is measured among the gateways that can receive traffic right now.

A routing skip is never an attempt: no `sms_attempts` row, no sequence number, no provider error.

### Distribution needs a shared cache

⚠️ **Round-robin state lives in the cache, and the store has to be shared by every process that sends.**
A counter in the process would distribute nothing: four queue workers are four processes, four counters
starting at zero, and four copies of the same first gateway. The cursor is read, incremented and written
inside a Laravel cache lock, so two workers hitting it in the same millisecond get different slots.

Use `database`, `redis`, `memcached` or `dynamodb` (`laravel-sms.routing.store`, defaulting to the processing-lock
store). `array` is per-process and `file` is per-machine — with either, a second worker or a second server
distributes its own cycle rather than joining yours. On a store with no lock support at all, the package
**logs an error and routes by configured priority** rather than pretending: a process-local counter that
called itself round-robin would look exactly like working distribution and would not be any.

`priority` needs none of this and never touches the cache.

### Queued messages keep the gateway they were given

A job Laravel released and ran again is the *same* logical message, so it is not re-distributed: the
gateway it was first pointed at is recorded on `sms_messages.routing_gateway_id` and leads the chain on
every later run. Without that, ten unrelated messages moving the shared cursor could move this one to a
different gateway between two runs of the same job.

That records an **intent**, not evidence — the attempts are the evidence of what was actually contacted —
and it does not freeze the candidate list: a gateway you enable between two runs still joins the chain and
can still rescue the message.

A message suppressed by the master switch contacted nobody, so it advances nothing.

## Failover

A message is offered to each eligible gateway in the planned order until one takes it. Every handover is one
`sms_attempt` row, in sequence, so the history of a message survives its outcome.

The chain stops at the first of these:

| Result | What happens |
|---|---|
| accepted | message is `accepted`; earlier failed attempts stay in the history |
| **uncertain** | message is `unknown` and the chain stops **permanently** |
| rejected, not safe to fail over | message is `failed`, no further gateway is tried |
| rejected, safe to fail over | the next eligible gateway is tried |

**`uncertain` never fails over, and that is the point of the whole design.** A gateway that timed out may
already have the message; handing it to a second gateway is how one person receives the same SMS twice.

`safeToFailover` is only ever true where structured provider evidence shows the failure belongs to *that
account* — rejected credentials, a rate limit, a pattern not registered there. An unexplained refusal is
**not** failed over, because it might equally be a refusal every gateway would repeat. That makes failover
deliberately conservative: it fires on evidence, not on hope.

## Gateway circuit breaker

Failover already makes one message survive a dead gateway. It does not stop the *next* message waiting
fifteen seconds to discover the same thing. So after a few transport failures in a row, a gateway is
skipped for a cooldown:

```
closed  ──3 qualifying failures in 60s──▶  open  ──60s──▶  half_open  ──one probe──▶  closed
                                                                                  └──▶  open
```

⚠️ **It answers exactly one question**: should this application temporarily avoid calling this gateway,
because recent *transport* evidence is bad? Only two failure kinds count — `Network` and
`ProviderUnavailable`. An invalid recipient, a message the provider will not carry, an unregistered
pattern or a rejected credential is **neutral**: none of them says the gateway cannot be reached, and none
of them improves in sixty seconds. A delivery report never affects it either — a switched-off handset is
not a transport fault.

⚠️ **Skipping is routing, not failure.** An open gateway is not called, records no `sms_attempt`, produces
no provider error and consumes no sequence number: the next gateway becomes attempt 1.

⚠️ **It can never rescue the message that tripped it.** Health is recorded *after* an attempt, and an
uncertain result still stops that message as `unknown` with no failover — the provider may already have
it. The evidence is for the next message.

⚠️ **It is local evidence about one account**, not knowledge that a provider is down. A rate-limited
account looks identical from here.

State lives in the cache, keyed by the gateway's id and the second its row was last saved — so correcting
a gateway's credentials produces a fresh circuit and nobody has to find a reset button before the fix
takes effect. Nothing secret or personal goes into a key.

When every eligible gateway is open: a synchronous send fails immediately with a package-authored reason
and no request; a queued send is left unsettled so the existing job retry brings it back, and only settles
failed once its attempts are spent.

For a management layer:

```php
$breaker = app(Amid\Sms\Health\CircuitBreaker::class);

$breaker->status($gateway);   // state (closed|open|half_open), failures, openUntil
$breaker->reset($gateway);    // clears the observation - and nothing else
```

`reset()` does not enable a disabled gateway, does not touch priority, credentials or country policy, and
sends nothing. Configure it under `laravel-sms.circuit_breaker`; set `enabled` to false to switch it off entirely.

## Message states

`queued` → `sending` → `accepted` | `failed` | `unknown`, plus `suppressed` when the master switch is off.

Anything other than `queued` and `sending` is **settled**, and a settled message is never delivered again —
that is what makes a re-run job, a killed worker or a redeployment safe.

## Delivery status

⚠️ **A different question from the send outcome, and it must not be confused with it.** `accepted` means
the provider took the request. Whether a handset ever received it is answered later, by a different
endpoint, and sometimes with the opposite verdict.

```php
Sms::refreshDelivery($message);   // or an SmsAttempt, when you know which handover

$message->delivery_status;        // null | pending | sent | delivered | failed | unknown
$message->delivery_confirmed_at;  // when WE learned it arrived - not the handset's clock
```

⚠️ `delivery_confirmed_at` is the moment this package obtained a delivered verdict, which with polling
can be well after the phone actually received the message. It is deliberately not called `delivered_at`:
no provider here publishes a trustworthy carrier delivery timestamp, and a column with that name would be
displayed as one. `delivery_checked_at` on the attempt is the last time the provider was asked.

| | |
|---|---|
| `null` | not tracked — the driver that carried it cannot report delivery |
| `pending` | accepted, no terminal result yet |
| `sent` | the carrier has it; **the handset is not confirmed** |
| `delivered` | positive confirmation |
| `failed` | the carrier confirmed non-delivery |
| `unknown` | a status came back that cannot be mapped truthfully |

Supported by `twilio` (polling the Message resource by SID) and `ippanel` (the recipient-level report by
outbox id). Both declare `Capability::DeliveryReport` and implement
`Amid\Sms\Contracts\ReportsDeliveryStatus`; every other driver leaves delivery null and needs no method
saying so.

Rules worth knowing before you build on it:

- **Explicit only.** Nothing polls, nothing is scheduled, and reading `delivery_status` contacts nobody.
  Which messages are worth asking about, and how often, is a decision with real cost attached and it
  belongs above this package.
- **A lookup can never change a send.** A report API that times out or rejects your token has told you
  nothing about the message: the refresh returns null and not one column changes. It never triggers
  failover or a resend.
- **Terminal verdicts are monotonic.** `delivered` is never downgraded by a stale answer, and a confirmed
  failure never returns to pending.
- **Only the accepted attempt speaks for the message.** Refused failover attempts are never polled and
  never touch the summary.
- **No raw report is ever persisted.** These endpoints return the original message text, the recipient,
  account and billing detail; only a neutral status, the provider's own status token, a structured error
  code and a short reason survive — and for a sensitive message, not even the reason.
- Webhooks (Twilio's `StatusCallback`) are **not** implemented. Push can be added later without changing
  any of the above.

## Phone numbers

Destinations are stored canonically in E.164 (`+989121234567`). `laravel-sms.phone.default_region` is used only for
input that carries no country code of its own.

`laravel-sms.phone.require_mobile` is **off by default**. A parsing library can classify a number, but that
classification is not a universal statement about whether the number can receive an SMS — it varies by
country and carrier — so Core does not reject valid international destinations on line type alone. Turn it
on if your application genuinely wants mobile-only destinations. E.164 validity is checked either way.

## Twilio

The international driver. Text only.

```php
SmsGateway::create([
    'key' => 'twilio',
    'label' => 'Twilio',
    'driver' => 'twilio',
    'sender' => '+15551234567',        // -> From, in E.164
    'priority' => 50,
])->forceFill([
    'credentials' => ['account_sid' => 'AC...', 'auth_token' => '...'],
    'is_enabled' => true,
])->save();
```

Optionally a Messaging Service instead of a sender. Twilio treats these as alternatives, so when this
is set it is sent **instead of** `From`, never alongside:

```php
$gateway->options = ['messaging_service_sid' => 'MG...'];
```

⚠️ **Twilio does not deliver to Iran.** Its own documentation for error 21408 says not to retry
expecting Geo Permissions to fix it. So this gateway is *additive*: it carries the international
destinations the Iranian providers cannot, and an Iranian destination refused here fails over to a
gateway that can carry it. Put both in the same chain and the router sorts it out.

⚠️ **`queued` means accepted, not delivered.** Twilio has taken responsibility for processing the
message; the handset has not been reached. The message becomes `accepted` and the Message SID is stored
for a later delivery lookup.

### Opt-out never fails over

Twilio error `21610` means the recipient replied **STOP**. That refusal is recorded with
`safeToFailover = false`, so the chain stops even when healthy gateways remain.

This is deliberate and it is not a technical limitation. Failover exists so a provider outage does not
lose a message; using it to reach somebody who asked not to be reached would turn a reliability
mechanism into a way of ignoring them, automatically and at scale. If you are building on this package,
do not work around it.

## Melipayamak

Two things about this provider are worth knowing before you configure it.

**There are two Melipayamak APIs.** This driver speaks the one every official SDK targets —
`rest.payamak-panel.com`, with a `username` and `password` in the request body. An account
provisioned only for the newer console API key will not work with it.

**Pattern values are joined into one delimited string.** The provider has no named parameters here:
the approved body has numbered placeholders and the values are matched by position. The separator
defaults to `;` and is configurable per gateway:

```php
$gateway->options = ['parameter_separator' => ','];
```

A value that contains the configured separator is **refused before the request is made**, because
sending it would split one value into two and deliver a plausible, billed, wrong message that
nothing downstream could detect. The value is never escaped or altered — no escaping mechanism is
documented, so any would be a guess. The refusal is safe to fail over, so a gateway that passes
parameters as discrete fields can carry the same message unchanged.

## IPPanel and IPPanel-derived providers

The `ippanel` driver targets the current Edge API (`https://edge.ippanel.com/v1`), and supports text and
pattern sending. Its `url` option overrides the base URL:

```php
$gateway->forceFill([
    'credentials' => ['api_key' => '...'],   // sent as a bare Authorization header
    'options' => ['url' => 'https://api.another-host.example/v1'],
])->save();
```

That override is the **only** white-label support offered. A gateway described as "built on IPPanel" is not
necessarily API-compatible with it; this driver serves another gateway only when that gateway genuinely
exposes the same documented contract at a different address. A provider that has diverged needs its own
driver, not a branch inside this one.

## Drivers

What this package's drivers actually implement — not what the providers offer.

| Driver | Text | Pattern | Delivery report | International |
|---|:--:|:--:|:--:|---|
| `log` | ✅ | ✅ | — | local only; contacts nobody |
| `kavenegar` | ✅ | ✅ | — | Iranian accounts |
| `smsir` | ✅ | ✅ | — | Iranian accounts |
| `ippanel` | ✅ | ✅ | ✅ | Iranian accounts; also serves API-compatible hosts via `options.url` |
| `melipayamak` | ✅ | ✅ | — | Iranian accounts |
| `twilio` | ✅ | — | ✅ | international. ⚠️ **cannot deliver to Iran** |

⚠️ **A blank is a statement about this package, not about the provider.** Several of these providers
publish more than is implemented here, and the difference is deliberate:

- **IPPanel** documents voice OTP, scheduled sending, peer-to-peer, keyword, postal-code and country
  sends, phonebooks and remote pattern management. None of it is exposed.
- **Twilio's** Content API (`ContentSid` / `ContentVariables`) is its template system and is **not**
  implemented, so `twilio` has no pattern capability: whether SMS content sends require a Messaging
  Service is not settled by the current documentation, and Test Credentials cannot exercise one.
- **Melipayamak** publishes a delivery lookup (`GetDeliveries2`) that is deliberately not implemented —
  this provider's documentation is method-specific and easy to misread, and two report implementations
  were enough to prove the abstraction.
- **Provider-owned OTP** exists at several of these providers and is deliberately unused: a code that
  exists only inside one provider cannot fail over to another.

⚠️ **Capability can also depend on the account.** A Twilio account with Geo Permissions disabled for a
region refuses messages there at runtime; an Iranian line may be approved for service patterns but not for
free text. The driver says what the integration supports; the account decides the rest.

## Security

- **Gateway credentials are encrypted at rest**, hidden from model serialisation, redacted from dumps,
  redacted out of provider error text and stored payloads, and named — never quoted — when one is missing.
- ⚠️ **A future admin UI must never return a stored secret to a form**, and must treat a blank credential
  input as "leave unchanged". This package deliberately provides no way to read one back.
- **A sensitive message persists no body and no variables**, no provider payload, and **no free-form
  provider text at all** — providers quote the request back inside refusals. The structured facts remain.
- ⚠️ **The consequence is intended: a sensitive message cannot be re-sent from history.** An expired code
  should be re-requested, not replayed.
- **Queued jobs are encrypted** (`ShouldBeEncrypted`), so a code in flight is never in clear text in a
  `jobs` row or a Redis key.
- **One-time codes are stored hashed**, never in plaintext, under a cache key that contains a hash of the
  number rather than the number. No API ever returns the code.
- ⚠️ **Twilio's opt-out (21610) never fails over.** Using failover to reach somebody who replied STOP
  would turn a reliability mechanism into consent bypass. The decision is made twice in the code so a
  later edit cannot quietly re-enable it.

⚠️ **This package does not promise exactly-once delivery, and cannot.** It prevents *avoidable* duplicates:
every gateway is called at most once per run, an uncertain result stops the message permanently as
`unknown` rather than being re-sent, and a lock plus a settled-state check makes a re-run job a no-op.

What remains is one unavoidable window, stated plainly: **if the process dies after a provider has
accepted the request but before the attempt row is written**, the message stays unsettled and a later run
may hand it to a gateway again — one person, two messages. Closing that would need a two-phase protocol
with the provider, and none of these providers offers one. The design keeps the window as small as the
write that follows the HTTP call, and makes every *knowable* ambiguity terminal rather than optimistic.

## Operational limitations

- **The cache store must support atomic locks** — `database`, `redis`, `memcached` or `dynamodb`, whose
  locks every sending process shares. ⚠️ `array` locks within one process and `file` within one machine,
  so on more than one application server neither coordinates anything: the send lock stops protecting
  against duplicate sends across servers, and round-robin distributes per server rather than globally.
  On a store with no lock support at all, the send lock throws rather than permit duplicate sends, and
  the circuit breaker and round-robin each say so in the log and switch themselves off — messages still
  send, by configured priority.
- **Delivery refresh is explicit.** `Sms::refreshDelivery()` is the whole mechanism. Core schedules
  nothing, polls nothing and has no webhook route; deciding which messages are worth asking about, and how
  often, is a policy question with real cost and provider rate limits attached, and it belongs above this
  package.
- **`delivery_confirmed_at` is when this package obtained a delivered verdict**, not when the handset
  received the message. No provider here publishes a trustworthy carrier timestamp.
- **Country coverage is administrator configuration**, not global knowledge about providers. The package
  does not know which countries an account may message; somebody tells it.
- **The circuit breaker is local evidence about one account.** A rate-limited account looks exactly like a
  provider outage from here. Nothing in this package claims a provider is down.
- **The master switch defaults to off**, so a restored production database cannot start sending.
- ⚠️ **Twilio does not replace the Iranian drivers for Iranian destinations** — its own documentation says
  not to retry traffic to Iran expecting Geo Permissions to restore delivery. It carries what they cannot.

## Adding a provider

Implement `Amid\Sms\Contracts\Driver`, declare its capabilities, and register the class in
`config('laravel-sms.drivers')`. If the provider can report delivery, also implement
`Amid\Sms\Contracts\ReportsDeliveryStatus` and declare `Capability::DeliveryReport` — both together or
neither, so the capability stays a fact rather than a claim. A driver must not throw for provider behaviour; it translates its provider's
vocabulary into a `SendResult`. Provider quirks — parameter limits, forbidden characters, an id buried in an
odd place — belong inside the driver, never in calling code.

### Asking what a driver can do

```php
use Amid\Sms\Gateways\GatewayRegistry;

app(GatewayRegistry::class)->registered();               // ['log', 'kavenegar', …]
app(GatewayRegistry::class)->capabilitiesFor('twilio');  // [Capability::Text, Capability::DeliveryReport]
```

`capabilitiesFor()` answers by driver **name**, before any gateway names it — which is what a management
layer needs to know before offering `pattern` as a delivery mode or a "refresh delivery" action. It builds
the driver with an empty configuration and asks it: **nothing is sent, nothing is contacted, and no
credential is read.** ⚠️ Capabilities are therefore treated as a property of the class; a driver whose
capabilities genuinely varied by account should be asked through `driverFor()` instead. An unregistered
name throws `GatewayNotConfigured` rather than returning an empty list — a driver that cannot be found is
not a driver that can do nothing.

⚠️ **Driver instances are never cached.** `driverFor()` builds a fresh one from the gateway as it stands
now, so a gateway re-credentialed a moment ago is the gateway that sends. Nothing has to be invalidated.

## Managing credentials (for a future admin layer)

Credentials are encrypted at rest and hidden from every serialisation of the gateway model. Any management
UI built on this must **never return stored secret values to a form**, and must treat a blank credential
input as "leave unchanged". Authorization is the management layer's responsibility; this package has no
opinion about permissions.

## Testing

```bash
composer install
./vendor/bin/pest
```

Tests run against an in-memory SQLite database owned by the test run. No application database is touched
and no real request is ever made.

There is one exception, and it does not run by default. `tests/Integration` holds a Twilio **Test
Credentials** harness that calls the real Twilio REST API — which sends no SMS, contacts no carrier,
charges nothing and touches no account state. It is a separate test suite, excluded from the command
above, and it skips itself unless you supply the credentials:

```bash
SMS_TWILIO_TEST_ACCOUNT_SID=AC... SMS_TWILIO_TEST_AUTH_TOKEN=...   ./vendor/bin/pest --testsuite=Integration
```

⚠️ Use the **test** pair from the Twilio Console, shown beside the live pair. Live credentials there
would send real messages to real phones and bill you for them.
