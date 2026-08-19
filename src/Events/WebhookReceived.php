<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mailkube\Event\WebhookEvent;

/**
 * Dispatched once per verified webhook delivery.
 *
 * **One** event class, carrying the SDK's typed event, rather than one Laravel event per webhook
 * type. A per-type mirror would duplicate the SDK's catalogue in a second place that has to be
 * extended every time the platform adds an event, and a receiver deployed before that release would
 * silently stop seeing the new type. Listeners narrow with `instanceof` instead:
 *
 * ```php
 * public function handle(WebhookReceived $received): void
 * {
 *     if ($received->event instanceof EmailBouncedEvent) {
 *         // $received->event->data->emailId
 *     }
 * }
 * ```
 *
 * An event type this release of the SDK does not model still arrives, as its unknown-event arm with
 * the payload intact, so nothing is dropped on the floor while you wait for an SDK upgrade.
 */
final class WebhookReceived
{
    use Dispatchable;

    /**
     * @param WebhookEvent $event The verified, parsed delivery.
     */
    public function __construct(public readonly WebhookEvent $event)
    {
    }
}
