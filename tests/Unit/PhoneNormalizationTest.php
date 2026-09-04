<?php

declare(strict_types=1);

use Mizbanha\Sms\Contracts\PhoneNormalizer;

/**
 * Normalisation is the package's first line of defence: everything downstream
 * assumes the stored number is canonical, and a gateway paid per message is a poor
 * place to discover that it is not.
 */
it('reduces every way of writing one Iranian number to the same E.164 value', function (string $input) {
    $number = app(PhoneNormalizer::class)->normalize($input);

    expect($number)->not->toBeNull()
        ->and($number->e164)->toBe('+989121234567');
})->with([
    'national with trunk prefix' => '09121234567',
    'international with plus' => '+989121234567',
    'international without plus' => '989121234567',
    'international with double zero' => '00989121234567',
    'spaced' => '0912 123 4567',
    'hyphenated' => '0912-123-4567',
]);

it('uses the configured default region for national input', function () {
    // A bare national number means nothing without a region. The same digits are a
    // different country's number under a different default, which is exactly why
    // the region is configuration rather than a constant.
    config()->set('laravel-sms.phone.default_region', 'GB');
    app()->forgetInstance(PhoneNormalizer::class);

    $british = app(PhoneNormalizer::class)->normalize('07400123456');

    expect($british)->not->toBeNull()
        ->and($british->e164)->toBe('+447400123456')
        ->and($british->region)->toBe('GB');
});

it('reads a number with an explicit country code regardless of the default region', function () {
    config()->set('laravel-sms.phone.default_region', 'GB');
    app()->forgetInstance(PhoneNormalizer::class);

    $number = app(PhoneNormalizer::class)->normalize('+989121234567');

    expect($number->e164)->toBe('+989121234567')
        ->and($number->region)->toBe('IR');
});

it('accepts Persian and Arabic-Indic digits', function (string $input) {
    // A number pasted from an Iranian phone's contacts arrives in Persian digits.
    // Without folding it is either refused or stored in a form that will never
    // match the Latin spelling of the same phone.
    expect(app(PhoneNormalizer::class)->normalize($input)?->e164)->toBe('+989121234567');
})->with([
    'persian' => '۰۹۱۲۱۲۳۴۵۶۷',
    'arabic-indic' => '٠٩١٢١٢٣٤٥٦٧',
    'mixed with latin' => '۰۹۱۲123۴۵۶۷',
]);

it('keeps the national form beside the canonical one', function () {
    // Iranian gateways want 09121234567 and nothing else, so the national form is
    // computed once here rather than by a parser inside each driver.
    $number = app(PhoneNormalizer::class)->normalize('+989121234567');

    expect($number->national)->toBe('09121234567')
        ->and($number->e164)->toBe('+989121234567');
});

it('refuses input that cannot be a sendable number', function (?string $input) {
    expect(app(PhoneNormalizer::class)->normalize($input))->toBeNull();
})->with([
    'too short' => '0912123',
    'not a number' => 'not a phone',
    'empty' => '',
    'null' => null,
    'digits that are not a valid Iranian number' => '00000000000',
]);

it('accepts a valid non-mobile number by default', function () {
    // Core is international. A parsing library classifies a number, but that
    // classification is not a universal statement about whether the number can
    // receive an SMS - it varies by country and carrier - so refusing on line type
    // alone would reject valid destinations out of the box.
    expect(app(PhoneNormalizer::class)->normalize('02188776655')?->e164)->toBe('+982188776655');
});

it('refuses a non-mobile number only when the application opts in', function () {
    config()->set('laravel-sms.phone.require_mobile', true);
    app()->forgetInstance(PhoneNormalizer::class);

    expect(app(PhoneNormalizer::class)->normalize('02188776655'))->toBeNull()
        // The opt-in narrows line type only; it does not touch validity checking.
        ->and(app(PhoneNormalizer::class)->normalize('09121234567')?->e164)->toBe('+989121234567');
});

it('still refuses structurally invalid numbers with the mobile requirement off', function () {
    // The default must not be mistaken for "accept anything". E.164 validity is
    // checked either way.
    expect(config()->get('laravel-sms.phone.require_mobile'))->toBeFalse();

    expect(app(PhoneNormalizer::class)->normalize('0912123'))->toBeNull()
        ->and(app(PhoneNormalizer::class)->normalize('not a phone'))->toBeNull();
});
