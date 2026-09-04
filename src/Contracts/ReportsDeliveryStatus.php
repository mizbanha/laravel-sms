<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Contracts;

use Mizbanha\Sms\Exceptions\DeliveryLookupFailed;
use Mizbanha\Sms\Phone\PhoneNumber;
use Mizbanha\Sms\Results\DeliveryResult;

/**
 * A driver that can be asked what actually happened to a message it accepted.
 *
 * ⚠️ **Separate from `Driver` on purpose.** Adding a `deliveryStatus()` method to
 * the main contract would force four drivers to implement a method whose only
 * behaviour is to say "not supported" — four fake implementations, each of which
 * has to be read and dismissed by everybody who ever opens those files, and each
 * of which is a place where somebody eventually returns something plausible-looking
 * instead. A driver that cannot report delivery simply does not implement this,
 * and `instanceof` is then a true answer rather than a self-declaration.
 *
 * `Capability::DeliveryReport` exists alongside it and must agree with it: a driver
 * advertising that capability without implementing this interface is lying to the
 * router.
 *
 * ⚠️ Nothing provider-specific appears in this signature. The two arguments are
 * the identifier the provider itself gave us and the recipient in the package's own
 * canonical form — which is what a per-recipient report needs in order to pick the
 * right row out of a batch.
 */
interface ReportsDeliveryStatus
{
    /**
     * Ask the provider about one accepted message.
     *
     * ⚠️ **Throws rather than inventing an answer.** A report endpoint that times
     * out, rejects our token or is unavailable has told us nothing about the
     * message, and returning `unknown` in that situation would overwrite a
     * perfectly good `pending` with a verdict about the *lookup* rather than about
     * the delivery. The caller catches this and leaves the snapshot exactly as it
     * was.
     *
     * @param  string  $providerMessageId  the id this provider returned when it
     *                                     accepted the message, byte for byte
     * @param  PhoneNumber  $recipient  the destination, canonical
     *
     * @throws DeliveryLookupFailed when the provider could not be asked, or
     *                              answered something that is not a report
     */
    public function deliveryStatus(string $providerMessageId, PhoneNumber $recipient): DeliveryResult;
}
