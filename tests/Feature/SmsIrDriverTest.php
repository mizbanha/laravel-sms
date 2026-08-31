<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Facades\Sms;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The NAMED half of the parameter-mapping proof.
 *
 * The same logical template, the same logical variables, a provider that calls its
 * parameters something else entirely - and no calling code that knows about it.
 */
it('sends pattern parameters under the names this provider registered', function () {
    $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '100200',
        // The provider's own registered names, which have nothing to do with ours.
        parameterMap: [
            ['provider' => 'CUSTOMER', 'variable' => 'customer_name'],
            ['provider' => 'ORDER_NO', 'variable' => 'order_number'],
        ],
    );

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageId' => 55]])]);

    $message = Sms::to('09121234567')->template('order-created')->with([
        'customer_name' => 'Amid',
        'order_number' => 'CF-1204',
    ])->send();

    expect($message->status->value)->toBe('accepted')
        ->and($message->attempts()->first()->provider_message_id)->toBe('55');

    Http::assertSent(function (Request $request): bool {
        return $request['templateId'] === '100200'
            && $request['mobile'] === '09121234567'
            && $request['parameters'] === [
                ['name' => 'CUSTOMER', 'value' => 'Amid'],
                ['name' => 'ORDER_NO', 'value' => 'CF-1204'],
            ];
    });
});

it('carries one logical template through two providers that disagree about parameter names', function () {
    // The point of the whole design, in one test. One template, one set of logical
    // variables, two providers - one numbering its parameters and one naming them -
    // and the difference lives entirely in two binding rows.
    [$kavenegar] = $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: 'kavenegar-code',
        parameterMap: [
            ['provider' => 'token', 'variable' => 'customer_name'],
            ['provider' => 'token2', 'variable' => 'order_number'],
        ],
        priority: 10,
        key: 'kavenegar',
    );

    $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Pattern,
        patternCode: '100200',
        parameterMap: [
            ['provider' => 'CUSTOMER', 'variable' => 'customer_name'],
            ['provider' => 'ORDER_NO', 'variable' => 'order_number'],
        ],
        priority: 20,
        key: 'smsir',
    );

    Http::fake([
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 2]]),
    ]);

    $variables = ['customer_name' => 'Amid', 'order_number' => 'CF-1204'];

    // Priority 10 wins.
    $first = Sms::to('09121234567')->template('order-created')->with($variables)->send();
    expect($first->attempts()->first()->gateway_key)->toBe('kavenegar');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'kavenegar') && $r['token'] === 'Amid');

    // Disable it and the same call, unchanged, goes out through the other provider
    // in that provider's own vocabulary.
    $kavenegar->forceFill(['is_enabled' => false])->save();
    app()->forgetInstance(\Amid\Sms\Gateways\GatewayRegistry::class);

    $second = Sms::to('09121234567')->template('order-created')->with($variables)->send();

    expect($second->attempts()->first()->gateway_key)->toBe('smsir');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sms.ir')
        && $r['parameters'] === [
            ['name' => 'CUSTOMER', 'value' => 'Amid'],
            ['name' => 'ORDER_NO', 'value' => 'CF-1204'],
        ]);
});

it('falls back to our own variable names when a binding has no mapping', function () {
    // The sane default for a provider whose registered names were copied from our
    // wording: a mapping is only needed where the provider actually disagrees.
    $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '100200',
        parameterMap: null,
    );

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageId' => 7]])]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(fn (Request $r): bool => $r['parameters'] === [
        ['name' => 'customer_name', 'value' => 'Amid'],
        ['name' => 'order_number', 'value' => 'CF-1204'],
    ]);
});

it('sends rendered text when the binding is in text mode', function () {
    $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Text,
        body: 'Hi {customer_name}, your order is ready.',
    );

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [9]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->body)->toBe('Hi Amid, your order is ready.');

    Http::assertSent(fn (Request $r): bool => $r['messageText'] === 'Hi Amid, your order is ready.'
        && $r['mobiles'] === ['09121234567']);
});
