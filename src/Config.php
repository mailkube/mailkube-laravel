<?php

declare(strict_types=1);

namespace Mailkube\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mailkube\Client;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface;

/**
 * The one place this package turns Laravel configuration into SDK constructor arguments.
 *
 * Everything that reaches the SDK goes through {@see self::client()}: the transport, the webhook
 * entry point and anything added later. Two call sites building their own client is how one of
 * them ends up on a different base URL, or without the User-Agent suffix, and the difference shows
 * up as a support question rather than a failing test.
 */
final class Config
{
    /**
     * SDK constructor argument names, mapped to the settings key that answers each.
     *
     * The same suffix is read from two places: `config/mailkube.php`, and the
     * per-mailer array in `config/mail.php`. One list serves both, so a setting cannot be
     * overridable in one place and quietly ignored in the other.
     *
     * @var array<string, string>
     */
    private const CLIENT_ARGUMENTS = [
        'apiKey' => 'api_key',
        'baseUrl' => 'base_url',
        'timeout' => 'timeout',
    ];

    /**
     * Return the settings a per-mailer array in `config/mail.php` may override.
     *
     * @phpstan-return list<string>
     */
    public static function overridableKeys(): array
    {
        return array_values(self::CLIENT_ARGUMENTS);
    }

    /**
     * Build the SDK client from configuration.
     *
     * `$mailer` is the per-mailer array from `config/mail.php`. It wins over the package config, so
     * one application can reach two accounts through two mailers.
     *
     * @phpstan-param array<array-key, mixed> $mailer
     */
    public static function client(
        ConfigRepository $config,
        array $mailer = [],
        ?HttpClient $http = null,
        ?LoggerInterface $logger = null,
    ): Client {
        $arguments = self::clientArguments($config, $mailer);

        // A PSR-18 client the application bound, if it bound one. This is the seam for a proxy,
        // instrumentation or a retry middleware, and it is what makes a mailer carrying its own
        // credentials testable: without it, that path builds a client through discovery and reaches
        // the network no matter what the container says.
        if ($http instanceof HttpClient) {
            $arguments['httpClient'] = $http;
        }

        // A Laravel log channel the application named, if it named one. Absent, the argument is
        // omitted rather than passed as null, which leaves the SDK's own `MAILKUBE_LOG` fallback
        // in charge instead of silencing it with an explicit default.
        if ($logger instanceof LoggerInterface) {
            $arguments['logger'] = $logger;
        }

        // Identify this package in the User-Agent, once, here. Doing it at a call site would mean
        // whichever call site remembered, and the SDK's own token stays leading either way.
        $arguments['userAgentSuffix'] = self::userAgentSuffix();

        // Spread as named arguments: an argument this package did not resolve is simply not passed,
        // which is what leaves the SDK's own default (and its environment fallback) in charge.
        return new Client(...$arguments);
    }

    /**
     * Resolve the configured values, dropping every one that is not set.
     *
     * An unset value is **omitted, not passed as null**. The SDK falls back to its own environment
     * variables when an argument is absent, and handing it an explicit null would suppress that
     * fallback while looking like configuration.
     *
     * Laravel's config repository returns `mixed`, so each value is narrowed here rather than cast
     * at the point of use. A non-scalar setting is a misconfiguration, and skipping it leaves the
     * SDK's own resolution in charge instead of passing an array where a string belongs.
     *
     * @phpstan-param array<array-key, mixed> $mailer
     * @phpstan-return array{apiKey?: string, baseUrl?: string, timeout?: float}
     */
    public static function clientArguments(ConfigRepository $config, array $mailer = []): array
    {
        $resolved = [];

        foreach (self::CLIENT_ARGUMENTS as $argument => $key) {
            $value = $mailer[$key] ?? $config->get('mailkube.' . $key);

            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            if ($argument === 'timeout') {
                $resolved['timeout'] = (float) $value;
                continue;
            }

            $resolved[$argument] = (string) $value;
        }

        return $resolved;
    }

    /**
     * Return this package's own `name/version` token for the SDK's User-Agent.
     *
     * The version is read from the installed package metadata rather than written here. A literal
     * would be a second source of truth that the release process does not update, so it would go
     * stale on the first release and stay wrong for every one after it.
     */
    public static function userAgentSuffix(): string
    {
        $version = InstalledVersions::getPrettyVersion('mailkube/mailkube-laravel') ?? '0.0.0';

        return 'mailkube-laravel/' . ltrim($version, 'v');
    }

    /**
     * Return the Laravel log channel the SDK should write through, or null when none is configured.
     *
     * Null is the default, and it means "leave the SDK silent unless `MAILKUBE_LOG` says otherwise"
     * rather than "log to the default channel". The SDK logs request and response metadata at debug
     * level, which is useful when a send is being diagnosed and noise the rest of the time.
     */
    public static function logChannel(ConfigRepository $config): ?string
    {
        $channel = $config->get('mailkube.log_channel');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    /**
     * Return the webhook signing secret, or null when none is configured.
     */
    public static function webhookSecret(ConfigRepository $config): ?string
    {
        $secret = $config->get('mailkube.webhooks.secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Return the signature freshness tolerance, or null to accept the SDK's documented default.
     */
    public static function webhookTolerance(ConfigRepository $config): ?int
    {
        $tolerance = $config->get('mailkube.webhooks.tolerance');

        return is_numeric($tolerance) ? (int) $tolerance : null;
    }
}
