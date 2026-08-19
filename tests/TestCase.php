<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests;

use Http\Mock\Client as MockHttpClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Mailkube\Client;
use Mailkube\Laravel\MailkubeServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface;

/**
 * Base class for this package's tests.
 *
 * Two things it deliberately does NOT do: boot a real application, and fake the SDK. Testbench
 * supplies a minimal container, and the SDK runs for real over a stubbed PSR-18 client, so these
 * tests exercise the argument names and response handling that the package actually depends on. A
 * hand-written fake of the SDK would keep passing after the SDK renamed an argument underneath it.
 *
 * No database is configured, and none is needed: this package persists nothing.
 */
abstract class TestCase extends TestbenchTestCase
{
    /**
     * The stubbed PSR-18 client every SDK call in these tests goes through.
     */
    protected MockHttpClient $http;

    /**
     * PSR-17 factories, used both by the SDK and to build the canned responses below.
     */
    protected Psr17Factory $psr17;

    /**
     * Register the package under test.
     *
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MailkubeServiceProvider::class];
    }

    /**
     * Configure a host application that routes its mail through this package.
     *
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');
        $config->set('mailkube.api_key', 'mk_test_key');
        $config->set('mail.default', 'mailkube');
        $config->set('mail.mailers.mailkube', ['transport' => 'mailkube']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->psr17 = new Psr17Factory();
        $this->http = new MockHttpClient();

        // Binding the PSR-18 CLIENT, not a pre-built SDK client. Handing over a finished client
        // would skip `Config::client()` entirely, so the tests would never exercise the argument
        // mapping or the User-Agent suffix, and the per-mailer path (which builds its own client)
        // would quietly reach the real network instead.
        $this->container()->instance(HttpClient::class, $this->http);
        $this->container()->forgetInstance(Client::class);
    }

    /**
     * The booted application, typed non-nullable.
     *
     * Testbench declares `$app` as nullable because it does not exist before `setUp()`. Every use
     * below is after boot, and this is where that is asserted once instead of at each call site.
     */
    protected function container(): Application
    {
        $app = $this->app;
        self::assertNotNull($app, 'the application is not booted');

        return $app;
    }

    /**
     * The application's config repository.
     */
    protected function config(): ConfigRepository
    {
        return $this->container()->make(ConfigRepository::class);
    }

    /**
     * Queue one canned API response.
     *
     * @param array<string, mixed> $body
     */
    protected function queueResponse(int $status, array $body): void
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        $this->http->addResponse(
            $this->psr17->createResponse($status)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17->createStream($json)),
        );
    }

    /**
     * Queue the response a successful send returns.
     */
    protected function queueAcceptedSend(string $id = 'em_1', string $messageId = '<msg-1@mailkube>'): void
    {
        $this->queueResponse(200, ['id' => $id, 'message_id' => $messageId]);
    }

    /**
     * Return the request the SDK actually sent, failing the test when nothing was sent.
     */
    protected function lastRequest(): RequestInterface
    {
        $requests = $this->http->getRequests();
        self::assertNotSame([], $requests, 'the SDK sent no request');

        return $requests[count($requests) - 1];
    }

    /**
     * Return the decoded body of the request the SDK actually sent.
     *
     * @return array<string, mixed>
     */
    protected function lastRequestBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
