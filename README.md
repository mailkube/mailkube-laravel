# mailkube-laravel

[![CI](https://github.com/mailkube/mailkube-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/mailkube/mailkube-laravel/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/mailkube/mailkube-laravel)](https://packagist.org/packages/mailkube/mailkube-laravel)
[![PHP](https://img.shields.io/packagist/php-v/mailkube/mailkube-laravel)](composer.json)
[![License: Apache 2.0](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![Code of Conduct](https://img.shields.io/badge/Contributor%20Covenant-2.1-purple.svg)](CODE_OF_CONDUCT.md)

Laravel mail transport for mailkube.

Send mail through mailkube using Laravel's own `Mail` facade, and receive webhooks as Laravel
events. This package is a thin adapter over
[`mailkube/mailkube-php`](https://github.com/mailkube/mailkube-php): the API, retries, errors and
signature verification all live there.

## Requirements

PHP 8.3+ and Laravel 12 or 13.

Laravel 11 is not supported. It is past security support, and the advisory covering
`>=11.0.0,<12.0.0` has no patched 11.x release, so Composer's default audit policy declines to
install it at all.

## Install

```bash
composer require mailkube/mailkube-laravel
```

The service provider is discovered automatically. Add the mailer to `config/mail.php`:

```php
'mailers' => [
    'mailkube' => [
        'transport' => 'mailkube',
    ],
],
```

and point `MAIL_MAILER` at it:

```dotenv
MAIL_MAILER=mailkube
MAILKUBE_API_KEY=mk_live_...
```

That is the whole setup. Everything else on this page is optional.

## Sending

Nothing about your application changes. Mailables, `Mail::to(...)`, queued mail, attachments,
`Mail::fake()` in tests: all of it works as it already did.

```php
use Illuminate\Support\Facades\Mail;

Mail::to('customer@example.com')->send(new OrderShipped($order));
```

### What is mapped

The sender, recipients (including blind copies), subject, both bodies, attachments and your custom
headers. Anything Laravel's mail API can express, this transport passes through.

Send-time features the SDK offers but Laravel's mail API has no slot for — tags, topics, templates,
scheduling, idempotency keys — are reached by using the SDK directly:

```php
app(\Mailkube\Client::class)->emails->send(
    from: 'Acme <hello@yourdomain.com>',
    to: 'customer@example.com',
    subject: 'Hello world',
    html: '<p>It works!</p>',
    tags: [new \Mailkube\Model\Tag('campaign', 'spring')],
);
```

The client is bound in the container and already carries your configuration, so there is nothing to
wire up.

> **Inline images degrade to ordinary attachments.** The API's attachment model carries a filename,
> content and content type, with no content id, so a `cid:` reference has nothing to resolve
> against. This is a capability of the platform rather than of this package.

## Configuration

Reading order for every setting: the per-mailer array in `config/mail.php`, then
`config/mailkube.php`, then the SDK's own environment fallback.

| Setting | `config/mail.php` key | Environment | Default |
|---|---|---|---|
| API key | `api_key` | `MAILKUBE_API_KEY` | required |
| Base URL | `base_url` | `MAILKUBE_BASE_URL` | the SDK's |
| Timeout | `timeout` | `MAILKUBE_TIMEOUT` | the SDK's |
| Log channel | | `MAILKUBE_LOG_CHANNEL` | none (silent) |
| Webhook path | | `MAILKUBE_WEBHOOK_PATH` | none (no route) |
| Webhook secret | | `MAILKUBE_WEBHOOK_SECRET` | required for webhooks |
| Webhook tolerance | | `MAILKUBE_WEBHOOK_TOLERANCE` | the SDK's |

An unset setting is **omitted** rather than passed along as null, so the SDK's own resolution still
applies.

Name a channel from `config/logging.php` and the SDK writes its request and response metadata
through Laravel's logging stack at debug level:

```dotenv
MAILKUBE_LOG_CHANNEL=stack
```

Bodies are never logged, and the `Authorization` and `Idempotency-Key` headers are redacted by the
SDK.

Publish the config file only if you want to edit it in place:

```bash
php artisan vendor:publish --tag=mailkube-config
```

### Two accounts, two mailers

Give a mailer its own credentials and it gets its own client:

```php
'mailers' => [
    'mailkube' => ['transport' => 'mailkube'],
    'marketing' => [
        'transport' => 'mailkube',
        'api_key' => env('MARKETING_API_KEY'),
    ],
],
```

```php
Mail::mailer('marketing')->to($user)->send(new Newsletter());
```

## Errors

A failed send raises `Symfony\Component\Mailer\Exception\TransportException` with the SDK's own
exception as its previous. That is Laravel's and Symfony's error type rather than this package's on
purpose: failover transports, queued-mail failure handling and `Mail::assertNothingSent()` all key
off it.

```php
use Symfony\Component\Mailer\Exception\TransportException;

try {
    Mail::to($user)->send(new OrderShipped($order));
} catch (TransportException $e) {
    report($e->getPrevious());   // the SDK exception, with the API's error name and request id
}
```

Retry policy is deliberately not this package's business: the SDK documents which errors are safe to
retry, and Laravel's queue is what retries them.

## Webhooks

Off by default. A package should not mount an unauthenticated POST route just for being installed,
so you opt in by naming a path:

```dotenv
MAILKUBE_WEBHOOK_PATH=webhooks/mailkube
MAILKUBE_WEBHOOK_SECRET=whsec_...
```

Deliveries are verified against the **raw** request body and dispatched as one Laravel event:

```php
use Mailkube\Laravel\Events\WebhookReceived;
use Mailkube\Event\EmailBouncedEvent;
use Mailkube\Event\EmailDeliveredEvent;

class HandleMailkubeWebhook
{
    public function handle(WebhookReceived $received): void
    {
        $event = $received->event;

        if ($event instanceof EmailDeliveredEvent) {
            Log::info('delivered', ['id' => $event->data->emailId]);
        } elseif ($event instanceof EmailBouncedEvent) {
            Suppression::add($event->data->bounce->recipient);
        }
    }
}
```

One event class rather than one per webhook type, so an event this SDK release does not model still
reaches your listener with its payload intact instead of being dropped.

Listeners run inside the request unless you queue them, and the endpoint answers as soon as they
return. Queue anything slow, or delivery latency becomes retries.

**The route defaults to the `api` middleware group**, which has no CSRF verification. A
machine-to-machine POST cannot present a CSRF token, so if you move it into `web` you must add the
path to your CSRF exceptions or every delivery is rejected with a 419. Likewise, any middleware that
decodes and re-encodes the request body breaks the signature: it no longer covers the bytes that
were signed.

Point it at your own controller instead, if you prefer:

```php
Route::post('/hooks/mail', \Mailkube\Laravel\Http\WebhookController::class)
    ->middleware('api');
```

## Extending this package

Before adding a setting, a mapped field, or another entry point, read
[`.rules/INTEGRATION_CONTRACT.md`](.rules/INTEGRATION_CONTRACT.md) (what every mailkube framework
integration does identically) and [`.rules/LARAVEL_INTEGRATION.md`](.rules/LARAVEL_INTEGRATION.md)
(how those rules land here). Both carry a checklist.

The short version: the capability has to exist in the SDK first, inputs are mapped in
`src/Payload.php` and never at a call site, and configuration goes through `src/Config.php`.

This package ships no `examples/` directory, deliberately: every entry point it exposes needs a host
application before it can run. This README is the wiring documentation, and the test suite is what
keeps it honest.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup and the quality gates every change
must pass. Security issues: see [SECURITY.md](SECURITY.md).

## License

[Apache-2.0](LICENSE) © 2026 Mail Tactic Corporation.
