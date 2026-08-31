<?php

declare(strict_types=1);

namespace Amid\Sms\Sending;

use Amid\Sms\Contracts\PhoneNormalizer;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Exceptions\InvalidRecipient;
use Amid\Sms\Exceptions\TemplateNotFound;
use Amid\Sms\Jobs\SendSmsMessage;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Templates\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;

/**
 * One message being assembled. What Sms::to() returns.
 *
 *     Sms::to('09121234567')
 *        ->template('order-created')
 *        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
 *        ->queue();
 *
 * Fresh per call and never shared: it holds the state of a single send, and a
 * shared fluent object leaks a recipient from one call site into the next - the
 * kind of bug only ever noticed by the person who received someone else's message.
 *
 * ⚠️ **Where the line between throwing and recording falls, and why.**
 *
 * A caller mistake throws, because it is a bug in the calling code and there is
 * nothing yet to record it against: no recipient, an unusable number, an unknown
 * template, a variable the wording needs that nobody supplied. All four are
 * detected before anything is written or sent, so nothing is left half-done.
 *
 * Everything from the gateway onward is RECORDED, never thrown: no enabled
 * gateway, a provider that refused, a request that timed out. Those are events in
 * the world, and sending is almost always a side effect of something more
 * important - an order being placed, a payment clearing. An exception there would
 * roll back the thing that actually mattered for the sake of the message announcing
 * it.
 */
final class PendingMessage
{
    private ?string $recipient = null;

    private ?SmsTemplate $template = null;

    /** @var array<string, string|int|float|null> */
    private array $variables = [];

    private ?Model $reference = null;

    /**
     * Whether the CALLER is forcing this send to be sensitive.
     *
     * ⚠️ One-way. It can raise the sensitivity of a send whose template was not
     * marked, and nothing can lower one whose template was. See sensitive().
     */
    private bool $forceSensitive = false;

    public function __construct(
        private readonly PhoneNormalizer $normalizer,
        private readonly TemplateRenderer $renderer,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function to(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * The logical message to send, by key.
     */
    public function template(string $key): self
    {
        $this->template = SmsTemplate::query()->where('key', $key)->first()
            ?? throw TemplateNotFound::forKey($key);

        return $this;
    }

    /**
     * The logical variables, by name.
     *
     * ⚠️ Logical names and plain values only - customer_name, order_number. Never
     * a model, never a path like order.customer.name. The package has no way to
     * resolve one and no business knowing what an application's models are called.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    public function with(array $variables): self
    {
        $this->variables = [...$this->variables, ...$variables];

        return $this;
    }

    /**
     * Treat this send as sensitive whatever the template says.
     *
     * ⚠️ **Security only moves upward.** This raises; nothing lowers. A template
     * marked sensitive is sensitive at every call site, and a caller that knows it
     * is carrying a secret can say so even if nobody remembered to tick the box on
     * the template — which is exactly what the OTP service does, so that OTP
     * safety never depends on an administrator's configuration.
     *
     * The consequence is deliberate: the message row will hold no body and no
     * variables, the attempt will hold no provider payload, and the message can
     * never be re-sent from history.
     */
    public function sensitive(bool $sensitive = true): self
    {
        $this->forceSensitive = $this->forceSensitive || $sensitive;

        return $this;
    }

    /**
     * Optional context: what this message is about.
     *
     * Recorded and never read by the package. It exists so an application can ask
     * "what did we send about this order" without the package learning what an
     * order is.
     */
    public function about(?Model $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Record the message and hand it to the queue.
     */
    public function queue(): SmsMessage
    {
        $message = $this->record();

        if ($message->isSettled()) {
            // Suppressed by the master switch. Nothing to hand over.
            return $message;
        }

        SendSmsMessage::dispatch($message->getKey(), $this->variables)
            ->onQueue((string) config('sms.queue.queue', 'sms'))
            ->onConnection(config('sms.queue.connection'));

        return $message;
    }

    /**
     * Record the message and send it during this request.
     *
     * ⚠️ Blocks for as long as the gateway takes, and has nowhere to retry from.
     * For a console command or a test, not for a request somebody is waiting on.
     */
    public function send(): SmsMessage
    {
        $message = $this->record();

        if ($message->isSettled()) {
            return $message;
        }

        $this->dispatcher->attempt($message, $this->variables, mayRetry: false);

        return $message->refresh();
    }

    /**
     * Validate everything the caller controls, then write the row.
     *
     * ⚠️ The message is persisted BEFORE any provider is contacted, always. If the
     * process dies mid-send there is a record that it was attempted; the other
     * order round would lose the message entirely and nobody would know one had
     * been meant.
     */
    private function record(): SmsMessage
    {
        $to = $this->normalizer->normalize($this->recipient)
            ?? throw InvalidRecipient::for($this->recipient);

        $template = $this->template ?? throw new \Amid\Sms\Exceptions\SmsException('An SMS needs a template.');

        // Rendered before anything is written, so a wording the caller cannot fill
        // in fails without leaving a row behind.
        $body = $this->renderer->render(
            (string) $template->body,
            $this->variables,
            sprintf('Template [%s]', (string) $template->key),
        );

        $message = new SmsMessage;

        $sensitive = (bool) $template->is_sensitive || $this->forceSensitive;

        $message->forceFill([
            'sms_template_id' => $template->getKey(),
            'to' => $to->e164,
            'is_sensitive' => $sensitive,
            // Derived once, here, and read by the router from now on. Null is a
            // real answer for a valid non-geographic number.
            'country_code' => $to->region,
            'status' => $this->enabled() ? MessageStatus::Queued : MessageStatus::Suppressed,
            /*
             * ⚠️ Both null for a sensitive message, and null rather than masked.
             *
             * The row still records that this person was messaged, when, through
             * which gateway and with what result — everything an audit needs except
             * the one thing that would turn the log into a list of live codes.
             * Masking would look like data; null says the value was deliberately
             * not kept.
             */
            'body' => $sensitive ? null : $body,
            'variables' => $sensitive || $this->variables === [] ? null : $this->variables,
            'reference_type' => $this->reference?->getMorphClass(),
            'reference_id' => $this->reference?->getKey(),
        ])->save();

        return $message;
    }

    /**
     * ⚠️ Off unless switched on. See config/sms.php - a suppressed message is
     * recorded in full and reaches no phone, which is what makes a restored
     * production database safe to work against.
     */
    private function enabled(): bool
    {
        return (bool) config('sms.enabled', false);
    }
}
