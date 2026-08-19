<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Mailkube\Laravel\Events\WebhookReceived;
use Mailkube\Webhooks;
use PHPUnit\Framework\Attributes\Test;

/**
 * The inbound webhook endpoint: verification, dispatch, and the two ways it refuses.
 */
final class WebhookTest extends TestCase
{
    private const PATH = 'webhooks/mailkube';

    private const SECRET = 'whsec_test';

    /**
     * Turn the endpoint on for this test class.
     *
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('mailkube.webhooks.path', self::PATH);
        $config->set('mailkube.webhooks.secret', self::SECRET);
    }

    #[Test]
    public function aValidDeliveryIsAcknowledgedAndDispatched(): void
    {
        Event::fake([WebhookReceived::class]);

        $body = $this->body();

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($body), $body)
            ->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function theDispatchedEventCarriesTheTypedSdkEvent(): void
    {
        $received = null;
        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$received): void {
            $received = $event;
        });

        $body = $this->body();
        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($body), $body)->assertOk();

        self::assertInstanceOf(WebhookReceived::class, $received);
        self::assertSame('email.delivered', $received->event->type);
    }

    #[Test]
    public function aTamperedBodyIsRejected(): void
    {
        $body = $this->body();
        $headers = $this->signedHeaders($body);

        // Signed one body, delivered another: the exact shape of a forged or corrupted delivery.
        $this->call('POST', self::PATH, [], [], [], $headers, $this->body(emailId: 'em_tampered'))
            ->assertStatus(400);
    }

    #[Test]
    public function aDeliveryWithNoSignatureIsRejected(): void
    {
        $this->call('POST', self::PATH, [], [], [], [], $this->body())->assertStatus(400);
    }

    #[Test]
    public function anUnconfiguredSecretIsAServerErrorNotAClientError(): void
    {
        $this->config()->set('mailkube.webhooks.secret', null);

        $body = $this->body();

        // 500, not 4xx. The route exists because the application asked for it, so this is the
        // receiver misconfigured, and a 4xx would tell the platform to stop retrying a delivery
        // that was perfectly good.
        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($body), $body)
            ->assertStatus(500);
    }

    #[Test]
    public function verificationRunsAgainstTheRawBytes(): void
    {
        // Signed over bytes whose JSON re-encoding would differ: extra whitespace and a non-ASCII
        // character. If anything in the request path decoded and re-encoded the body before
        // verification, the signature would no longer match and this would fail.
        $body = '{"type": "email.delivered",  "data": {"email_id": "em_é"}}';

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($body), $body)
            ->assertOk();
    }

    #[Test]
    public function theRouteIsRegisteredUnderAPredictableName(): void
    {
        // The counterpart lives in ProviderTest, where the endpoint is left at its default: a route
        // assertion has to be made in an application booted with the configuration under test,
        // because the provider registers routes once, at boot.
        self::assertNotNull(
            $this->container()->make('router')->getRoutes()->getByName('mailkube.webhooks'),
        );
    }

    /**
     * Build a webhook body.
     */
    private function body(string $emailId = 'em_1'): string
    {
        return json_encode([
            'type' => 'email.delivered',
            'data' => ['email_id' => $emailId, 'delivery' => ['recipient' => 'customer@example.test']],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Sign a body with the SDK's own signer, in the header shape the framework hands to PHP.
     *
     * Using `Webhooks::sign()` rather than an HMAC written here is deliberate: a fixture built from
     * a second implementation only proves this test agrees with itself, and would keep passing if
     * the SDK changed the signing scheme.
     *
     * @return array<string, string>
     */
    private function signedHeaders(string $body, string $id = 'wh_1'): array
    {
        $timestamp = gmdate('c');

        return [
            'HTTP_X_WEBHOOK_ID' => $id,
            'HTTP_X_WEBHOOK_TS' => $timestamp,
            'HTTP_X_WEBHOOK_SIG' => Webhooks::sign($id, $timestamp, $body, self::SECRET),
        ];
    }
}
