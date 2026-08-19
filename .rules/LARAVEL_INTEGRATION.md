# The Laravel realization

Load this alongside `.rules/INTEGRATION_CONTRACT.md` when touching the transport, the payload
mapping, the settings surface, the webhook entry point, or the service provider.

`INTEGRATION_CONTRACT.md` says what every mailkube framework integration does. This file says how
those rules land in Laravel, and records the two places this package deviates.

## What belongs where

| File | Owns |
|---|---|
| `src/MailkubeServiceProvider.php` | registration: the driver, the container binding, the optional route, config merge/publish |
| `src/Config.php` | **the one config module**: Laravel settings → SDK constructor arguments, and the User-Agent suffix |
| `src/Payload.php` | **the one payload module**: a Symfony `Email` + `Envelope` → SDK send arguments |
| `src/Transport/MailkubeTransport.php` | the outbound entry point, and error translation |
| `src/Http/WebhookController.php` | the inbound entry point |
| `src/Events/WebhookReceived.php` | the framework-native notification |

Nothing else may build a client or map a message. If a new entry point needs either, it calls these.

## The extension point is `AbstractTransport`, not `AbstractHttpTransport`

`AbstractHttpTransport` exists for transports that speak HTTP themselves: it holds an HTTP client
and expects the subclass to build a request. Using it here would put a second copy of the wire
format in this repository, which is the "one HTTP path" clause exactly. `AbstractTransport` gives
the parts that are genuinely shared (Symfony's mailer events, envelope handling) and leaves
`doSend()` to deliver however it likes.

## The transport is synchronous, and that is inherited

`doSend()` is a synchronous hook: the mail stack calls it and expects delivery to have been
attempted when it returns. The SDK is synchronous too, so there is nothing to reconcile. Queueing
belongs to Laravel, through `Mail::queue`, and an async entry point would be a **new class beside
this one**, never a change to it. Never start an event loop underneath `doSend()`.

## The two-source recipient split

This is the one mapping that is silently wrong if you take a shortcut, so it has three tests:

- **`from` comes from the `Envelope`.** `Mail::alwaysFrom` and Symfony's `EnvelopeListener` rewrite
  the envelope and leave the message's `From` header alone. Reading the message ignores the global.
- **`to`, `cc` and `reply_to` come from the `Email`.**
- **`bcc` is the envelope's recipients minus the visible ones.** It is absent from the message by
  definition, and the envelope holds every recipient with no way to tell which was which.

Taking recipients wholesale from the envelope publishes every blind copy in `to`. That is a
disclosure bug, not a crash, and no test that only counts recipients will catch it.

Note the comparison has to reduce one side: envelope recipients are bare addresses while the visible
list is rendered with display names, so a naive string comparison files
`"A Customer" <customer@example.test>` under `bcc` as well.

## The webhook route is off by default

`mailkube.webhooks.path` is null until an application sets it. A package that
mounts an unauthenticated POST route merely because it was installed is a surprise, and the surprise
is a security one. Registering the route from the provider is otherwise completely idiomatic in
Laravel, which is why this differs from the Rails integration, where consumers write the route.

The route defaults to the `api` middleware group, which has no session and therefore no CSRF
verification. A machine-to-machine POST cannot present a CSRF token, so moving it into `web`
requires a CSRF exception or every delivery is rejected with a 419.

**Verification runs on the raw bytes.** `$request->getContent()` is the body exactly as received.
Anything that decodes and re-encodes first changes what gets verified: a JSON round trip is enough,
because key order and whitespace are not preserved. Laravel's own `TrimStrings` and
`ConvertEmptyStringsToNull` mutate the parsed input bag rather than the content, so they are safe;
a middleware calling `$request->replace()` on the content, or a proxy that reformats JSON, is not.

## One Laravel event, not one per webhook type

`WebhookReceived` carries the SDK's typed event and listeners narrow with `instanceof`. A Laravel
event class per webhook type would duplicate the SDK's catalogue in a second place that has to be
extended on every platform release, and a receiver deployed before that release would silently stop
seeing the new type. As it stands, an event the SDK does not model still arrives, as its
unknown-event arm with the payload intact.

## Deviations from the shared contract

Both are deliberate, and both are the kind of thing that gets "fixed" by someone who has not read
this file.

1. **Coverage is line only, not line and branch.** pcov reports covered-vs-total statements and has
   no reliable branch metric. Recorded in `.rules/SOLID_DRY_KISS.md` too.
2. **No `examples/` directory.** Every entry point here needs a host application before it can run,
   so a runnable script would need one scaffolded first, and would rot the moment it drifted. The
   wiring lives in `README.md` and the tests are what keep it honest. `ci.yml` says so where the
   example-compilation job would have been, rather than leaving a silent gap.

## Tests

Testbench supplies a minimal container, and the **real SDK** runs over a stubbed PSR-18 client. Both
halves matter: faking the SDK would only prove the tests agree with themselves, and would keep
passing after the SDK renamed an argument this package passes by name.

There is no database and none is needed, because the package persists nothing. Never reach for
`RefreshDatabase`; if a change appears to need it, that change is arguing with the contract's
no-persistent-state clause.

Webhook fixtures are signed with the SDK's own `Webhooks::sign()`, never with an HMAC written in the
test. A fixture built from a second implementation only proves the test agrees with itself, and
would keep passing if the SDK changed the scheme.
