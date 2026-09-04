<?php

/*
|--------------------------------------------------------------------------
| Laravel SMS
|--------------------------------------------------------------------------
|
| Read as `config('laravel-sms.*')`, from `config/laravel-sms.php`.
|
| ⚠️ **Deliberately not `config/sms.php`.** `sms` is too generic a name for a
| package to claim, and an application that already has an SMS subsystem of its
| own has almost certainly claimed it first. Laravel merges a package's config
| into the application's shallowly, with the application's file on top, so two
| files under one key do not coexist — they overwrite each other per top-level
| key, silently, and each side goes on reading what it thinks is its own.
|
| That is not hypothetical: the first application to install this package had
| owned `config/sms.php` since its twelfth stage, with a `drivers` array of a
| completely different shape. Nothing threw. The gateway registry simply found
| entries it could not read.
|
| So this file is named for its package, and an application keeps `config/sms.php`
| for whatever it already had there. There is no fallback to `sms.*`, no way to
| select the namespace from the environment, and nothing to configure about it.
|
| Environment variable names are unchanged and still read `SMS_*`. They are a
| separate contract, they were never the thing that collided, and renaming them
| would break every deployment for no benefit.
|
*/

use Amid\Sms\Drivers\IpPanelDriver;
use Amid\Sms\Drivers\IranPayamakDriver;
use Amid\Sms\Drivers\KavenegarDriver;
use Amid\Sms\Drivers\LogDriver;
use Amid\Sms\Drivers\MelipayamakDriver;
use Amid\Sms\Drivers\SmsIrDriver;
use Amid\Sms\Drivers\TwilioDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | With this off, everything up to and including the message record still runs
    | and each message is stored as suppressed. Nothing reaches a phone.
    |
    | It defaults to FALSE, and that default is the point of it. Gateways live in
    | the database now, which means a production database restored onto a staging
    | machine arrives complete with working credentials, enabled gateways and real
    | customers' real numbers. Sending has to be something an environment opts
    | into, in its own environment file, rather than something it inherits from a
    | database dump.
    |
    | Set SMS_ENABLED=true in production. Leave it alone everywhere else.
    |
    */

    'enabled' => env('SMS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | The five tables this package owns. Leave them alone and they keep the names
    | they have always had; every one of these defaults is the original name.
    |
    | ⚠️ **Set these BEFORE running this package's migrations for the first time.**
    | This is a schema mapping, decided once at installation. Changing a name
    | afterwards renames nothing: the package simply starts looking for a table
    | that is not there, and the rows stay where they were. Moving an installed
    | schema is a migration the application writes for itself.
    |
    | You need this if the application already owns a table called `sms_messages`
    | or `sms_templates` — `Schema::create()` refuses a name that exists, so the
    | first migration fails outright rather than merging into somebody else's data.
    | Prefix the whole set and the collision goes away:
    |
    |     'gateways'          => 'sms_core_gateways',
    |     'templates'         => 'sms_core_templates',
    |     'template_gateways' => 'sms_core_template_gateways',
    |     'messages'          => 'sms_core_messages',
    |     'attempts'          => 'sms_core_attempts',
    |
    | ⚠️ Deliberately not read from env(). Every other setting in this file is a
    | per-deployment choice; a table name is not. Two environments disagreeing
    | about the master switch is configuration, two environments disagreeing about
    | which table holds the messages is two different schemas — and the second one
    | is found out by a migration that ran against the wrong table in production.
    |
    | ⚠️ A name must be non-empty, unpadded, unique within this list, free of dots
    | and quotes, and at most 30 characters. The length is not about table names:
    | Laravel builds INDEX names out of them, MySQL allows 64 for those too, and
    | the longest index here adds 34. An invalid name throws rather than falling
    | back — see Amid\Sms\Exceptions\InvalidTableConfiguration.
    |
    */

    'tables' => [
        'gateways' => 'sms_gateways',
        'templates' => 'sms_templates',
        'template_gateways' => 'sms_template_gateways',
        'messages' => 'sms_messages',
        'attempts' => 'sms_attempts',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered drivers
    |--------------------------------------------------------------------------
    |
    | Driver name => driver class. A gateway row in the database names one of
    | these; it does not name a class.
    |
    | This is the line between what an operator may change and what a deployment
    | changes. Enabling a gateway, reordering two of them and replacing an expired
    | key are runtime facts and live in the database. A new PROVIDER is a class
    | implementing the Driver contract, and it arrives here.
    |
    | Add an entry to support a driver of your own. Nothing else needs to know.
    |
    */

    'drivers' => [
        'log' => LogDriver::class,
        'kavenegar' => KavenegarDriver::class,
        'smsir' => SmsIrDriver::class,
        'melipayamak' => MelipayamakDriver::class,
        /*
         * IPPanel, and any gateway that genuinely exposes the same documented API
         * at another address — point one at its own host with the gateway's `url`
         * option. "Built on IPPanel" is not the same as "speaks this API"; a
         * provider that has diverged needs its own driver.
         */
        'ippanel' => IpPanelDriver::class,
        /*
         * IranPayamak, against its published OpenAPI at docs.iranpayamak.com. Text
         * and pattern; no delivery report, because the report endpoint's response
         * schema in that specification is a copy-paste of the phonebook one and the
         * per-recipient fields are therefore undocumented.
         */
        'iranpayamak' => IranPayamakDriver::class,
        /*
         * The international one. Twilio does NOT deliver to Iran - its own
         * documentation for error 21408 says so - so this is the gateway that
         * carries destinations the others cannot, in the same chain as them.
         */
        'twilio' => TwilioDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Its own named queue by default, so that a run of ten thousand messages
    | cannot delay an import or an export sitting behind it.
    |
    */

    'queue' => [
        'connection' => env('SMS_QUEUE_CONNECTION'),
        'queue' => env('SMS_QUEUE', 'sms'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | How many times the send job may try again ON THE SAME GATEWAY, and how long
    | it waits between attempts.
    |
    | These bound retries the driver has already declared safe. A result that is
    | not retryable is not retried however many tries remain, and an uncertain
    | result is never retried at all.
    |
    */

    'retry' => [
        'tries' => env('SMS_TRIES', 3),
        'backoff' => [10, 60, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing lock
    |--------------------------------------------------------------------------
    |
    | The lock that makes one message one delivery when two workers hold the same
    | job at once.
    |
    | Null uses the default cache store. It must be a store that supports atomic
    | locks - database, redis, memcached and dynamodb all do, and are the ones to
    | use, because their locks are shared by every process that sends.
    |
    | ⚠️ Corrected in M9, from evidence rather than from memory. This package
    | previously said the `file` store could not lock at all and would throw. It
    | can: Laravel's file store takes a real exclusive lock on a lock file, so it is
    | atomic WITHIN ONE MACHINE. The remaining limitation is different and narrower
    | - two application servers with their own filesystems do not see each other's
    | locks - and `array` is per-process, which is enough for a test suite and
    | nothing else. A store with no lock support at all is refused.
    |
    | The duration is a ceiling on one delivery attempt, not a timeout: it only has
    | to outlive the HTTP call below.
    |
    */

    'lock' => [
        'store' => env('SMS_LOCK_STORE'),
        'seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing state
    |--------------------------------------------------------------------------
    |
    | Where the round-robin cursor lives.
    |
    | ⚠️ There is no strategy setting here, and that is deliberate. A routing
    | strategy is a property of a logical message - a login code should start at
    | the most reliable line every time, while order notifications are ordinary
    | traffic worth spreading - so it lives on the template row, where an operator
    | can change it at runtime. A config key would be a second source of truth for
    | the same question, and the two would eventually disagree.
    |
    | Weights live on the template/gateway binding, for the same reason: how much
    | of THIS message a gateway carries is a fact about the pairing.
    |
    | `store` is null by default, meaning the processing-lock store above. It must
    | support atomic locks, and for this feature it must be one whose locks every
    | sending process shares - database, redis, memcached or dynamodb. ⚠️ `array`
    | locks within one process and `file` within one machine, so with either of
    | them a second worker or a second server distributes its own cycle rather than
    | joining yours: the messages still go out, but the shares are per process or
    | per machine rather than global.
    |
    | ⚠️ On a store that cannot lock, round-robin does NOT quietly fall back to a
    | counter in the process. Four workers with four counters all start at zero and
    | all choose the same gateway, which looks exactly like working distribution and
    | is not. The package logs an error naming this setting and routes by configured
    | priority - a real strategy that needs no shared state - until it is fixed.
    |
    | `state_ttl` only has to outlive the quiet hours of a low-volume template. A
    | cursor that expires costs one restarted cycle and nothing else.
    |
    */

    'routing' => [
        'store' => env('SMS_ROUTING_STORE'),
        'state_ttl' => env('SMS_ROUTING_STATE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway circuit breaker
    |--------------------------------------------------------------------------
    |
    | Stops every message paying the same timeout on a gateway that has just failed
    | to answer the last several.
    |
    | Failover already makes one message survive a dead gateway. It does not stop
    | the NEXT message waiting fifteen seconds to discover the same thing, and the
    | one after that. This is the small amount of ephemeral memory that fixes it.
    |
    | ⚠️ It answers one question - "should this application temporarily avoid
    | calling this gateway" - from transport evidence only. A refused recipient, a
    | rejected credential or an unregistered pattern never opens it: none of them
    | says the gateway cannot be reached, and none of them improves in sixty
    | seconds. Only network failures and provider-unavailable answers count.
    |
    | ⚠️ It is LOCAL evidence about one account. A rate-limited account looks
    | exactly like a provider outage from here, so nothing in this package claims to
    | know that a provider is down - only that this gateway is not answering us.
    |
    | State lives in the cache and nowhere else: it is an observation, not
    | configuration, and it must not survive a change to the gateway it describes.
    | Correcting a gateway's credentials produces a fresh circuit automatically, so
    | nobody has to find a "reset health" button before a fix takes effect.
    |
    | `store` is null by default, meaning the processing-lock store above. It must
    | support atomic operations - see the note there about which stores do, and
    | about the `file` store, whose locks are real but local to one machine. On a
    | store with no lock support at all, the breaker logs an error and switches
    | itself off rather than running a version of itself whose one-probe guarantee
    | is not a guarantee. Messages still send.
    |
    */

    'circuit_breaker' => [
        'enabled' => env('SMS_CIRCUIT_BREAKER', true),
        'store' => env('SMS_CIRCUIT_STORE'),

        // Qualifying failures, and the window they have to happen inside. The
        // window is what stops one outage a week from opening a circuit on the
        // third one, having proved nothing.
        'failure_threshold' => env('SMS_CIRCUIT_THRESHOLD', 3),
        'failure_window' => env('SMS_CIRCUIT_WINDOW', 60),

        // How long the gateway is skipped before it is owed one careful probe.
        'cooldown' => env('SMS_CIRCUIT_COOLDOWN', 60),

        /*
         * How long one half-open probe may be outstanding before another message
         * is allowed to try.
         *
         * ⚠️ Floored at http.timeout + http.connect_timeout below, whatever is
         * configured here. A probe reservation that expires while its own request
         * is still in flight admits a second probe and produces exactly the pile-up
         * on a recovering provider that the half-open state exists to prevent.
         */
        'probe_ttl' => env('SMS_CIRCUIT_PROBE_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Applied to every driver, because the two settings that decide whether a slow
    | provider stalls a worker or merely fails one message are the same argument at
    | every gateway.
    |
    | Note there is no retry setting here. Retrying inside a driver would turn one
    | logical attempt into several invisible ones, and the second could be the
    | duplicate nobody can explain.
    |
    */

    'http' => [
        'timeout' => env('SMS_TIMEOUT', 15),
        'connect_timeout' => env('SMS_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone numbers
    |--------------------------------------------------------------------------
    |
    | Destinations are stored canonically, in E.164 with its leading plus, so that
    | every way of writing one number collapses to one value.
    |
    | The default region is used ONLY for input that carries no country code of its
    | own: 09121234567 is read as Iranian, +44... is read as British regardless.
    |
    | require_mobile refuses any number the parsing library does not classify as
    | mobile.
    |
    | ⚠️ Off by default, deliberately. A library can classify a number, but its
    | classification is not a universal statement about whether that number can
    | receive an SMS: the relationship between line type and SMS capability varies
    | by country and by carrier, and fixed-line SMS is real in several markets.
    | Refusing on line type alone would make this package reject valid
    | international destinations out of the box, which is the wrong default for
    | something meant to be provider- and country-neutral.
    |
    | An application that genuinely wants mobile-only destinations turns it on and
    | accepts the trade. Ordinary E.164 validity checking is unaffected either way.
    |
    */

    'phone' => [
        'default_region' => env('SMS_DEFAULT_REGION', 'IR'),
        'require_mobile' => env('SMS_REQUIRE_MOBILE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time codes
    |--------------------------------------------------------------------------
    |
    | This package generates and verifies OTP codes itself; a gateway only
    | delivers one. That is what lets a single code fail over between providers -
    | a provider-generated code exists only at that provider, so it cannot.
    |
    | The challenge lives in the cache, hashed. `store` is null by default, which
    | means the processing-lock store above, which in turn falls back to the
    | application's default cache. It must support atomic locks: verification and
    | resend both take one, so that a code cannot be consumed twice or issued
    | twice by two simultaneous requests.
    |
    | The defaults are the ones two independent applications in this workspace
    | arrived at separately, which is the strongest evidence available for them.
    |
    | ⚠️ Every one of these is a security parameter. They are validated at use:
    | a length of 1 is ten possible codes, and an expiry or attempt limit of 0
    | fails open.
    |
    */

    'otp' => [
        'store' => env('SMS_OTP_STORE'),
        'length' => env('SMS_OTP_LENGTH', 6),
        // How long a code is good for.
        'expires' => env('SMS_OTP_EXPIRES', 180),
        // How long before another may be requested. Reported to callers as this
        // fixed number rather than the true age of the challenge, so a countdown
        // cannot be used to discover which numbers have codes outstanding.
        'resend_after' => env('SMS_OTP_RESEND_AFTER', 90),
        // Wrong guesses before the challenge is destroyed. A wrong guess never
        // extends the expiry.
        'max_attempts' => env('SMS_OTP_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The channel LogDriver writes to. Worth giving it its own channel with a short
    | retention: that driver writes message bodies verbatim, which is what makes it
    | useful locally and what makes it unsuitable for the general application log.
    |
    */

    'log' => [
        'channel' => env('SMS_LOG_CHANNEL'),
    ],

];
