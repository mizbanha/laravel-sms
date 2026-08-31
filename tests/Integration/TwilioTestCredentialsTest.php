<?php

declare(strict_types=1);

namespace Amid\Sms\Tests\Integration;

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Amid\Sms\Tests\TestCase;

/**
 * ⚠️ **The only test in this package that talks to a real provider, and it does
 * not run.**
 *
 * Every driver here is written from documentation. That is the largest standing
 * assumption in the package, and Twilio is the one provider that offers a way to
 * close it without sending anything to anybody: Test Credentials call the real
 * REST API, are validated by the real service, and produce real documented error
 * codes — while sending no SMS, contacting no carrier, charging nothing and
 * touching no account state.
 *
 * This file is that verification, written and waiting.
 *
 * ⚠️ **It is skipped unless two environment variables are present**, so it can
 * never run by accident in the ordinary suite, in CI, or on somebody's machine who
 * did not ask for it:
 *
 *     SMS_TWILIO_TEST_ACCOUNT_SID   the TEST Account SID, which begins AC
 *     SMS_TWILIO_TEST_AUTH_TOKEN    the TEST Auth Token
 *
 * ⚠️ **TEST credentials only. Never live ones.** They are a separate pair, shown
 * in the Twilio Console beside the live pair. Live credentials in these variables
 * would send real messages to real phones and bill for them. Nothing is committed:
 * the values live in the environment of whoever runs this, and the gateway row
 * built from them exists only inside the transaction of a single test.
 *
 * ⚠️ This is a **test harness**, not a second configuration source. The package's
 * credentials live in the database, encrypted, and that does not change — these
 * variables exist solely to inject a secret into a temporary row.
 *
 * What it proves and what it does not: the outbound API contract — URL, auth,
 * encoding, the acceptance shape, and this driver's reading of nine documented
 * error codes. It proves nothing about carrier delivery, because Test Credentials
 * deliver nothing.
 *
 * Documented limitations, all of which shape what is asserted below:
 *   - no SMS is sent and no carrier is contacted;
 *   - account state is not modified;
 *   - live account resources are unreachable — a real purchased number cannot be
 *     used as the sender here, only the magic ones;
 *   - **`MessagingServiceSid` is not supported**, so the Messaging Service path
 *     cannot be verified this way at all;
 *   - status callbacks are not fired, so nothing about delivery reporting can be
 *     verified either;
 *   - any other resource answers 403.
 */
final class TwilioTestCredentialsTest extends TestCase
{
    /** The documented magic sender that passes validation. */
    private const FROM_VALID = '+15005550006';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::sid() === null || self::token() === null) {
            $this->markTestSkipped(
                'Twilio Test Credentials not supplied. Set SMS_TWILIO_TEST_ACCOUNT_SID and '
                .'SMS_TWILIO_TEST_AUTH_TOKEN to run this. Use the TEST pair from the Twilio Console, never '
                .'the live one.'
            );
        }
    }

    private static function sid(): ?string
    {
        $value = getenv('SMS_TWILIO_TEST_ACCOUNT_SID');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function token(): ?string
    {
        $value = getenv('SMS_TWILIO_TEST_AUTH_TOKEN');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A gateway built from the environment, for one test, in a transaction that is
     * rolled back. Nothing is written to any real database and nothing is committed.
     */
    private function gateway(string $from = self::FROM_VALID): void
    {
        $gateway = new SmsGateway;
        $gateway->forceFill([
            'key' => 'twilio-test-credentials',
            'label' => 'Twilio (test credentials)',
            'driver' => 'twilio',
            'sender' => $from,
            'credentials' => ['account_sid' => self::sid(), 'auth_token' => self::token()],
            'is_enabled' => true,
            'priority' => 10,
        ])->save();

        $template = SmsTemplate::query()->create([
            'key' => 'order-created',
            'name' => 'Order created',
            'body' => 'Hello {customer_name}',
        ]);

        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => DeliveryMode::Text,
            'is_enabled' => true,
        ]);
    }

    private function send(string $to)
    {
        return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
    }

    public function test_the_real_api_accepts_a_message_and_returns_a_message_sid(): void
    {
        $this->gateway();

        $attempt = $this->send('+15005550010')->attempts()->first();

        self::assertSame(SendOutcome::Accepted, $attempt->outcome, (string) $attempt->error);
        // Twilio's own identifier, from Twilio, stored as it arrived.
        self::assertMatchesRegularExpression('/^SM[0-9a-f]{32}$/', (string) $attempt->provider_message_id);
    }

    /**
     * The magic numbers, and the classification this package gives each.
     *
     * ⚠️ This is the table that matters. Every row is a claim this driver currently
     * makes on documentation alone; running this turns each one into something the
     * real service confirmed.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: FailureKind, 4: bool}>
     */
    public static function magicNumbers(): array
    {
        return [
            // [From, To, expected code, expected kind, expected safeToFailover]
            'invalid From' => ['+15005550001', '+15005550010', 21212, FailureKind::GatewayConfiguration, true],
            'From not SMS-capable' => ['+15005550007', '+15005550010', 21606, FailureKind::GatewayConfiguration, true],
            'sender queue full' => ['+15005550008', '+15005550010', 21611, FailureKind::ProviderUnavailable, true],
            'From not owned' => ['+15005550100', '+15005550010', 21606, FailureKind::GatewayConfiguration, true],

            'invalid To' => [self::FROM_VALID, '+15005550001', 21211, FailureKind::InvalidRecipient, false],
            'unroutable To' => [self::FROM_VALID, '+15005550002', 21612, FailureKind::GatewayRejected, true],
            'no international permission' => [self::FROM_VALID, '+15005550003', 21408, FailureKind::GatewayConfiguration, true],
            // ⚠️ Consent. Must never be failable over.
            'opted-out To' => [self::FROM_VALID, '+15005550004', 21610, FailureKind::InvalidRecipient, false],
            'To cannot receive SMS' => [self::FROM_VALID, '+15005550009', 21614, FailureKind::InvalidRecipient, false],
        ];
    }

    /**
     * @dataProvider magicNumbers
     */
    public function test_documented_error_codes_are_classified_as_this_driver_claims(
        string $from,
        string $to,
        int $code,
        FailureKind $kind,
        bool $failover,
    ): void {
        $this->gateway($from);

        $attempt = $this->send($to)->attempts()->first();

        self::assertSame(SendOutcome::Rejected, $attempt->outcome);
        self::assertNull($attempt->provider_message_id);
        self::assertSame($code, (int) data_get($attempt->provider_payload, 'code'));
        self::assertSame($kind, $attempt->failure_kind);
        self::assertSame($failover, (bool) $attempt->safe_to_failover);
    }

    public function test_credentials_never_reach_the_stored_attempt(): void
    {
        // Against the real service, with real (test) secrets, which is the only
        // place this can genuinely be proven.
        $this->gateway('+15005550001');

        $attempt = $this->send('+15005550010')->attempts()->first();

        self::assertStringNotContainsString((string) self::token(), (string) $attempt->error);
        self::assertStringNotContainsString((string) self::token(), json_encode($attempt->provider_payload));
        self::assertStringNotContainsString((string) self::sid(), (string) $attempt->error);
    }
}
