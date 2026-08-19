<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests;

use Illuminate\Log\Logger;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mailkube\Client;
use Mailkube\Laravel\Transport\MailkubeTransport;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the service provider wires, and what it deliberately leaves alone.
 */
final class ProviderTest extends TestCase
{
    #[Test]
    public function theTransportIsRegisteredUnderItsDriverName(): void
    {
        self::assertInstanceOf(MailkubeTransport::class, Mail::mailer('mailkube')->getSymfonyTransport());
    }

    #[Test]
    public function thePackagedConfigIsMergedSoNoPublishStepIsRequired(): void
    {
        // An application that only sets environment variables should work with no published file,
        // and one that published an older copy should still receive keys added by a later release.
        self::assertIsArray($this->config()->get('mailkube.webhooks'));
    }

    #[Test]
    public function buildingTheTransportCostsNoApiCallAndNoClient(): void
    {
        // Resolving the SDK client eagerly inside `Mail::extend` would construct it during every
        // `php artisan` boot, where a missing API key throws and takes down every console command,
        // including the ones you would run to fix the configuration.
        $this->container()->bind(Client::class, static function (): Client {
            throw new \LogicException('the client must not be resolved until a message is sent');
        });

        Mail::mailer('mailkube')->getSymfonyTransport();

        self::assertSame([], $this->http->getRequests());
    }

    #[Test]
    public function aConfiguredLogChannelIsResolvedThroughLaravelsLoggingStack(): void
    {
        $config = $this->config();
        $config->set('logging.channels.mailkube_test', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ]);
        $config->set('mailkube.log_channel', 'mailkube_test');
        $this->container()->forgetInstance(Client::class);

        $this->queueAcceptedSend();
        Mail::mailer('mailkube')->html('<p>hi</p>', static function (Message $message): void {
            $message->from('sender@example.test')->to('customer@example.test')->subject('Hello');
        });

        // The `Log::channel()` arm of the provider only runs at send time, inside the closure the
        // transport holds. Line coverage cannot tell the two arms of that ternary apart, so this is
        // the test that proves the facade is resolvable at that point and that the SDK really
        // writes through the channel the application named rather than through a logger of its own.
        $logger = Log::channel('mailkube_test');
        self::assertInstanceOf(Logger::class, $logger);

        // `Illuminate\Log\Logger::getLogger()` is declared as returning a PSR-3 logger, so the
        // Monolog-only accessor below has to be reached through a narrowed type rather than assumed.
        $monolog = $logger->getLogger();
        self::assertInstanceOf(MonologLogger::class, $monolog);

        $handler = $monolog->getHandlers()[0] ?? null;
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertNotSame([], $handler->getRecords(), 'the SDK logged nothing through the channel');
    }

    #[Test]
    public function aMailerCarryingItsOwnCredentialsGetsItsOwnClient(): void
    {
        $config = $this->config();
        $config->set('mail.mailers.second', [
            'transport' => 'mailkube',
            'api_key' => 'mk_second_account',
        ]);

        $this->queueAcceptedSend();

        // Sharing the container singleton here would silently send this mailer's mail on the
        // default account, which is the kind of bug that only shows up in someone's billing.
        Mail::mailer('second')->html('<p>hi</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
        });

        self::assertSame('Bearer mk_second_account', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function aMailerWithoutOverridesUsesTheSharedClient(): void
    {
        $this->queueAcceptedSend();

        // Laravel always passes at least `transport` in the per-mailer array, so emptiness says
        // nothing about whether anything was overridden: the check has to be by key.
        Mail::mailer('mailkube')->html('<p>hi</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
        });

        self::assertSame('Bearer mk_test_key', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function noWebhookRouteIsRegisteredUntilAPathIsConfigured(): void
    {
        // This class leaves `webhooks.path` at its default, which is the point: a package must not
        // mount an unauthenticated POST route merely because it was installed. The provider
        // registers routes at boot, so the assertion has to run in an application booted without
        // the setting rather than in one where it is unset afterwards.
        self::assertNull(
            $this->container()->make('router')->getRoutes()->getByName('mailkube.webhooks'),
        );
    }

    #[Test]
    public function anApplicationBoundHttpClientIsUsedForEveryMailer(): void
    {
        $this->config()->set('mail.mailers.second', [
            'transport' => 'mailkube',
            'api_key' => 'mk_second_account',
        ]);

        $this->queueAcceptedSend();

        // A mailer with its own credentials builds its own client. Without threading the bound
        // PSR-18 client through that path, it would resolve one by discovery and send over the real
        // network, which is a proxy or an instrumentation layer silently bypassed in production and
        // an outbound request from a test suite.
        Mail::mailer('second')->html('<p>hi</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
        });

        self::assertCount(1, $this->http->getRequests());
    }

    #[Test]
    public function thePackagePersistsNothing(): void
    {
        // The integration contract forbids state, and this is what keeps the suite free of a
        // database: no migrations to publish means nothing to migrate.
        self::assertDirectoryDoesNotExist(__DIR__ . '/../database');
        self::assertDirectoryDoesNotExist(__DIR__ . '/../src/Models');
    }
}
