<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests;

use Mailkube\Laravel\Config;
use Mailkube\Laravel\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * The config module's contract: what reaches the SDK, and what deliberately does not.
 */
final class ConfigTest extends TestCase
{
    #[Test]
    public function anUnsetSettingIsOmittedRatherThanPassedAsNull(): void
    {
        $config = $this->config();
        $config->set('mailkube.base_url', null);
        $config->set('mailkube.timeout', null);

        $arguments = Config::clientArguments($config);

        // The keys must be ABSENT, not present-and-null. The SDK falls back to its own environment
        // variables for an argument it was not given, and an explicit null suppresses that while
        // looking like configuration, so the failure is a client silently pointed at nothing.
        self::assertArrayNotHasKey('baseUrl', $arguments);
        self::assertArrayNotHasKey('timeout', $arguments);
        self::assertArrayHasKey('apiKey', $arguments);
        self::assertSame('mk_test_key', $arguments['apiKey']);
    }

    #[Test]
    public function anEmptyStringIsTreatedAsUnset(): void
    {
        $config = $this->config();
        $config->set('mailkube.base_url', '');

        // `env()` returns '' for a variable that is present but blank, which is the shape a
        // half-filled .env produces. Passing it through would override the SDK's default with
        // nothing at all.
        self::assertArrayNotHasKey('baseUrl', Config::clientArguments($config));
    }

    #[Test]
    public function aPerMailerSettingWinsOverThePackageConfig(): void
    {
        $config = $this->config();

        $arguments = Config::clientArguments($config, ['api_key' => 'mk_other_account']);

        self::assertArrayHasKey('apiKey', $arguments);
        self::assertSame('mk_other_account', $arguments['apiKey']);
    }

    #[Test]
    public function everyOverridableKeyIsReadFromBothSources(): void
    {
        $config = $this->config();

        // Guards the one-list invariant: a setting that is readable from the package config must
        // also be overridable per mailer. Adding one to the map and forgetting the other is the
        // drift this asserts against.
        foreach (Config::overridableKeys() as $key) {
            $config->set('mailkube.' . $key, $key === 'timeout' ? 5 : 'from-package');
        }

        $fromPackage = Config::clientArguments($config);
        $overrides = array_fill_keys(Config::overridableKeys(), 'from-mailer');
        $fromMailer = Config::clientArguments($config, $overrides);

        self::assertCount(count(Config::overridableKeys()), $fromPackage);
        self::assertSame(array_keys($fromPackage), array_keys($fromMailer));
    }

    #[Test]
    public function theUserAgentSuffixNamesThisPackageAndIsNotALiteralVersion(): void
    {
        $suffix = Config::userAgentSuffix();

        self::assertStringStartsWith('mailkube-laravel/', $suffix);

        // Read from the installed package metadata, which is what the release process updates. A
        // literal here would be a second source of truth and would report the version this file was
        // written at, forever.
        [, $version] = explode('/', $suffix, 2);
        self::assertNotSame('', $version);
        self::assertStringStartsNotWith('v', $version);
    }

    #[Test]
    public function theLogChannelIsNullUntilConfiguredSoTheSdkStaysSilent(): void
    {
        $config = $this->config();

        self::assertNull(Config::logChannel($config));

        $config->set('mailkube.log_channel', 'stack');
        self::assertSame('stack', Config::logChannel($config));

        // Same shape as every other setting: `env()` yields '' for a present-but-blank variable,
        // and that must read as "unset" rather than as a channel named the empty string, which
        // Laravel's log manager would reject at the first send rather than at boot.
        $config->set('mailkube.log_channel', '');
        self::assertNull(Config::logChannel($config));
    }

    #[Test]
    public function aConfiguredLogChannelReachesTheSdkAndAnUnsetOneDoesNot(): void
    {
        $recorder = new RecordingLogger();

        $silent = Config::client($this->config(), [], $this->http);
        self::assertSame([], $recorder->records);

        $logged = Config::client($this->config(), [], $this->http, $recorder);

        // Two clients, one carrying the logger and one not. The SDK logs its transport at debug
        // level, so a request through each is what tells them apart: asserting on the constructor
        // arguments instead would only prove this test agrees with itself.
        $this->queueAcceptedSend();
        $silent->emails->send(from: 'a@example.test', to: 'b@example.test', subject: 'x', text: 'y');
        self::assertSame([], $recorder->records, 'the SDK logged without being given a logger');

        $this->queueAcceptedSend();
        $logged->emails->send(from: 'a@example.test', to: 'b@example.test', subject: 'x', text: 'y');
        self::assertNotSame([], $recorder->records, 'the configured channel never reached the SDK');
    }

    #[Test]
    public function theWebhookSecretIsNullUntilConfigured(): void
    {
        $config = $this->config();

        self::assertNull(Config::webhookSecret($config));

        $config->set('mailkube.webhooks.secret', 'whsec_1');
        self::assertSame('whsec_1', Config::webhookSecret($config));
    }

    #[Test]
    public function theWebhookToleranceIsNullUntilConfiguredSoTheSdkDefaultSurvives(): void
    {
        $config = $this->config();

        self::assertNull(Config::webhookTolerance($config));

        // `env()` yields strings, so the numeric coercion is the point of the accessor.
        $config->set('mailkube.webhooks.tolerance', '120');
        self::assertSame(120, Config::webhookTolerance($config));
    }
}
