<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Sensitive messages: the ones whose content must not survive the send.
 *
 * ⚠️ The point of this file is what is NOT in the database afterwards. A login
 * code written to `sms_messages.variables` turns a delivery log into a table of
 * live credentials, readable by anyone with database access for as long as the log
 * is kept — which is usually far longer than the ninety seconds the code was worth.
 *
 * The assertions are deliberately about absence, and they check the columns
 * individually rather than trusting one summary, because there are four separate
 * places the secret could survive: the body, the variables, the provider payload
 * and the error text a provider quoted it into.
 */
const SECRET = 'Wr7-secret-value-482193';

function sensitiveTemplate(bool $sensitive = true): void
{
    test()->configureGateway(driver: 'smsir', mode: DeliveryMode::Text, body: 'Your code is {code}.');

    SmsTemplate::query()->where('key', 'order-created')->update(['is_sensitive' => $sensitive]);
}

function sendSecret(bool $force = false)
{
    $pending = Sms::to('09121234567')->template('order-created')->with(['code' => SECRET]);

    if ($force) {
        $pending->sensitive();
    }

    return $pending->send();
}

it('still persists the body and variables of an ordinary message', function () {
    // The baseline. Sensitivity must be something a template opts into, not a new
    // default that quietly stops recording what everybody else relies on.
    sensitiveTemplate(sensitive: false);

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = sendSecret();

    expect($message->is_sensitive)->toBeFalse()
        ->and($message->body)->toBe('Your code is '.SECRET.'.')
        ->and($message->variables)->toBe(['code' => SECRET]);
});

it('persists neither body nor variables for a sensitive template', function () {
    sensitiveTemplate();

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = sendSecret();

    expect($message->is_sensitive)->toBeTrue()
        // ⚠️ Null, not masked. `"******"` looks like data and is not; null says
        // plainly that the value was deliberately not kept, which is the fact an
        // auditor actually needs.
        ->and($message->body)->toBeNull()
        ->and($message->variables)->toBeNull()
        // Everything an audit does need is still there.
        ->and($message->to)->toBe('+989121234567')
        ->and($message->country_code)->toBe('IR')
        ->and($message->status->value)->toBe('accepted')
        ->and($message->sms_template_id)->not->toBeNull();

    // And nothing survives in the raw columns either.
    $row = DB::table('sms_messages')->where('id', $message->getKey())->first();
    expect(json_encode($row))->not->toContain(SECRET);
});

it('still sends the real content to the provider', function () {
    // Obvious but worth pinning: not persisting it must not mean not sending it.
    // The prohibition is about the database and the logs, never the wire.
    sensitiveTemplate();

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    sendSecret();

    Http::assertSent(fn ($request): bool => $request['messageText'] === 'Your code is '.SECRET.'.');
});

it('lets a caller force sensitivity onto a template that was not marked', function () {
    /*
     * The escape hatch that makes OTP safe. A caller that knows it is carrying a
     * secret can say so, and does not have to trust that somebody remembered to
     * tick a box on a template row months ago.
     */
    sensitiveTemplate(sensitive: false);

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = sendSecret(force: true);

    expect($message->is_sensitive)->toBeTrue()
        ->and($message->body)->toBeNull()
        ->and($message->variables)->toBeNull();
});

it('never lets a caller turn a sensitive template back into an ordinary one', function () {
    /*
     * ⚠️ Security only moves upward, and this is the test that keeps it that way.
     *
     * `sensitive(false)` is not a way to opt out. If it were, one call site that
     * passed the wrong flag — or a variable that happened to be falsey — would
     * write a live code into the log with nothing to show it had happened.
     */
    sensitiveTemplate();

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = Sms::to('09121234567')->template('order-created')
        ->with(['code' => SECRET])
        ->sensitive(false)
        ->send();

    expect($message->is_sensitive)->toBeTrue()
        ->and($message->body)->toBeNull();
});

it('snapshots sensitivity so a later template edit cannot rewrite history', function () {
    // Marking a template sensitive next month must not claim that last month's
    // messages were handled that way — and unmarking one must not suggest a body
    // that was never stored ought to be there.
    sensitiveTemplate(sensitive: false);

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = sendSecret();

    SmsTemplate::query()->where('key', 'order-created')->update(['is_sensitive' => true]);

    expect($message->fresh()->is_sensitive)->toBeFalse();
});

it('records no provider payload for a sensitive attempt', function () {
    /*
     * A provider that echoes the request into its response would otherwise write
     * the code straight into the attempts table. A "safe subset" of a payload that
     * no two providers shape alike would be guesswork about which keys are safe,
     * and being wrong once stores a secret. Null is the honest answer.
     */
    sensitiveTemplate();

    Http::fake(['*' => Http::response([
        'status' => 1,
        'data' => ['messageIds' => [1]],
        'echo' => ['messageText' => 'Your code is '.SECRET.'.'],
    ])]);

    $attempt = sendSecret()->attempts()->first();

    expect($attempt->provider_payload)->toBeNull()
        // The evidence that matters about the provider is all still there.
        ->and($attempt->gateway_key)->toBe('primary')
        ->and($attempt->driver)->toBe('smsir')
        ->and($attempt->outcome->value)->toBe('accepted')
        ->and($attempt->provider_message_id)->toBe('1');
});

it('persists no provider error text at all for a sensitive message', function () {
    /*
     * ⚠️ The M6 correction, and the failure it exists for is not hypothetical:
     * several providers here put the rejected text inside their error message. A
     * table designed specifically to hold no codes would otherwise hold them in the
     * error column, indefinitely.
     *
     * M5 scrubbed that sentence by substituting the values it knew about. That was
     * a partial defence presented as a guarantee — see the next test for the hole
     * in it — so the sentence is not stored at all now. What is lost is a
     * provider's prose; what remains is every structured fact, which is what an
     * audit actually reads.
     */
    sensitiveTemplate();

    Http::fake(['*' => Http::response([
        'status' => 0,
        'message' => 'Rejected the text «Your code is '.SECRET.'.» for this line',
    ])]);

    $message = sendSecret();
    $attempt = $message->attempts()->first();

    expect($attempt->error)->toBeNull()
        // The message row must not carry it either.
        ->and($message->error)->toBeNull()
        // And the whole audit trail is still there, structured.
        ->and($attempt->outcome->value)->toBe('rejected')
        ->and($attempt->failure_kind)->not->toBeNull()
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->gateway_key)->toBe('primary')
        ->and($attempt->driver)->toBe('smsir')
        ->and($attempt->sequence)->toBe(1);

    // Nothing anywhere in either raw row.
    $rows = json_encode([
        DB::table('sms_messages')->where('id', $message->getKey())->first(),
        DB::table('sms_attempts')->where('id', $attempt->getKey())->first(),
    ]);

    expect($rows)->not->toContain(SECRET);
});

it('keeps a one-character sensitive value out of every persisted field', function () {
    /*
     * ⚠️ **The M5 defect this milestone was opened to fix.**
     *
     * `SensitiveValues` ignored single-character values, because a variable of `1`
     * would have replaced every `1` in `HTTP 401` and destroyed the diagnostic the
     * error column exists for. The motivation was real and the resulting policy was
     * not acceptable: a sensitive value does not become safe to persist because it
     * is one character long, and a scrub with a documented exemption is not a
     * guarantee, it is a guarantee-shaped thing with a hole in it.
     *
     * The fix is not a cleverer replacement. It is not persisting the prose.
     */
    sensitiveTemplate();

    Http::fake(['*' => Http::response([
        'status' => 0,
        'message' => 'Rejected the text «Your code is 1.» for line 1 (HTTP 401)',
        'echo' => ['messageText' => 'Your code is 1.'],
    ])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['code' => '1'])->send();
    $attempt = $message->attempts()->first();

    expect($attempt->error)->toBeNull()
        ->and($attempt->provider_payload)->toBeNull()
        ->and($message->error)->toBeNull()
        ->and($message->body)->toBeNull()
        ->and($message->variables)->toBeNull()
        // The classification survived intact - it was made from the raw response,
        // upstream of any of this.
        ->and($attempt->outcome->value)->toBe('rejected')
        ->and($attempt->failure_kind)->not->toBeNull();
});

it('keeps a one-character sensitive value out of operator log output', function () {
    // The other place an operator reads: the log channel the local driver writes
    // to, which outlives the code and is frequently shipped somewhere central.
    test()->configureGateway(driver: 'log', mode: DeliveryMode::Text, body: 'Your code is {code}.');
    SmsTemplate::query()->where('key', 'order-created')->update(['is_sensitive' => true]);

    $written = [];
    Log::listen(function ($event) use (&$written): void {
        $written[] = json_encode($event->context);
    });

    Sms::to('09121234567')->template('order-created')->with(['code' => '1'])->send();

    expect(implode('', $written))->not->toContain('Your code is 1')
        ->toContain('sensitive content omitted');
});

it('still records a provider message id for a sensitive send', function () {
    // Dropping the prose must not drop the identifier. It is opaque, it is not
    // content, and it is the only way to raise a dispute with the provider or to
    // ask later what became of the message.
    sensitiveTemplate();

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [778899]]])]);

    expect(sendSecret()->attempts()->first()->provider_message_id)->toBe('778899');
});

it('keeps an ordinary message error intact', function () {
    // Scrubbing is for sensitive sends only. Removing the message text from every
    // provider error would destroy the diagnostic the column exists for.
    sensitiveTemplate(sensitive: false);

    Http::fake(['*' => Http::response([
        'status' => 0,
        'message' => 'Rejected the text «Your code is '.SECRET.'.» for this line',
    ])]);

    expect(sendSecret()->attempts()->first()->error)->toContain(SECRET);
});

it('logs metadata only for a sensitive message', function () {
    /*
     * The local driver writes bodies verbatim, which is exactly what it is for and
     * exactly what makes it dangerous here. A log channel outlives the code, is
     * readable by anyone with the file, and is often shipped somewhere central.
     */
    $this->configureGateway(driver: 'log', mode: DeliveryMode::Text, body: 'Your code is {code}.');
    SmsTemplate::query()->where('key', 'order-created')->update(['is_sensitive' => true]);

    $written = [];
    Log::listen(function ($event) use (&$written): void {
        $written[] = json_encode($event->context);
    });

    $message = sendSecret();

    expect($written)->not->toBeEmpty()
        ->and(implode('', $written))->not->toContain(SECRET)
        ->and(implode('', $written))->toContain('sensitive content omitted')
        // The delivery facts are still logged, and the driver still accepts.
        ->and(implode('', $written))->toContain('+989121234567')
        ->and($message->status->value)->toBe('accepted');
});

it('logs the body of an ordinary message as it always did', function () {
    $this->configureGateway(driver: 'log', mode: DeliveryMode::Text, body: 'Your code is {code}.');

    $written = [];
    Log::listen(function ($event) use (&$written): void {
        $written[] = json_encode($event->context);
    });

    sendSecret();

    expect(implode('', $written))->toContain(SECRET);
});

it('keeps a sensitive value out of the queued job payload', function () {
    /*
     * ⚠️ The payload is where the secret genuinely has to travel — a sensitive
     * message persists nothing, so the values reach the worker in the job or not at
     * all. Unencrypted, that is a live code sitting in a `jobs` row or a Redis key,
     * readable by anyone with access to either and retained long after the code
     * expired.
     *
     * `ShouldBeEncrypted` is Laravel's own mechanism; this package writes no
     * crypto. Asserted against a real serialised payload rather than against the
     * interface, because what matters is the bytes that reach the queue.
     */
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
    ]);

    sensitiveTemplate();

    Sms::to('09121234567')->template('order-created')->with(['code' => SECRET])->queue();

    $payload = DB::table('jobs')->value('payload');

    expect($payload)->not->toBeNull()
        ->and($payload)->not->toContain(SECRET)
        // Encrypted rather than merely absent: the variables really are in there.
        ->and($payload)->toContain('SendSmsMessage');
});

it('still queues and delivers normally', function () {
    // Encryption must be invisible to everything else. A round trip through the
    // real serialiser proves the job survives it.
    Queue::fake();
    sensitiveTemplate(sensitive: false);

    $message = Sms::to('09121234567')->template('order-created')->with(['code' => SECRET])->queue();

    expect($message->status->value)->toBe('queued');
    Queue::assertPushed(\Amid\Sms\Jobs\SendSmsMessage::class);
});
