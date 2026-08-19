<?php

declare(strict_types=1);

namespace Mailkube\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mailkube\Client;
use Mailkube\Laravel\Http\WebhookController;
use Mailkube\Laravel\Transport\MailkubeTransport;
use Psr\Http\Client\ClientInterface as HttpClient;

/**
 * Registers the `mailkube` mail transport and, when configured, the inbound
 * webhook route.
 *
 * Discovered automatically through `extra.laravel.providers` in composer.json, so an application
 * installs the package and is done.
 */
final class MailkubeServiceProvider extends ServiceProvider
{
    /**
     * Path to the packaged config file, relative to this class.
     */
    private const CONFIG_PATH = __DIR__ . '/../config/mailkube.php';

    /**
     * Bind the package's configuration and its SDK client.
     */
    public function register(): void
    {
        // Merging rather than requiring publication: an application that only sets the environment
        // variables never needs the file, and one that publishes an older copy still gets any keys
        // added by a later release instead of a null.
        $this->mergeConfigFrom(self::CONFIG_PATH, 'mailkube');

        $this->app->singleton(Client::class, fn (): Client => $this->build());
    }

    /**
     * Register the transport driver and the optional webhook route.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => config_path('mailkube.php')], 'mailkube-config');
        }

        // `Mail::extend` belongs in boot(): it resolves the mail manager, which cannot be touched
        // while the container is still being populated.
        Mail::extend('mailkube', function (array $config): MailkubeTransport {
            // The client is built by the closure, not here. Resolving it eagerly would construct it
            // during `php artisan` boot, where a missing API key would throw and take every console
            // command down with it, including the ones you would run to fix the configuration.
            return new MailkubeTransport(fn (): Client => $this->clientFor($config));
        });

        $this->registerWebhookRoute();
    }

    /**
     * Resolve the client this mailer should use.
     *
     * The container binding is the normal path, which is also what lets an application (or a test)
     * swap the client wholesale. A mailer that carries its own credentials in `config/mail.php`
     * gets its own client instead, because sharing the singleton would silently send its mail on
     * the default account.
     *
     * Note the overrides are looked for by key: Laravel always passes at least `transport` in this
     * array, so its emptiness says nothing about whether anything was overridden.
     *
     * @phpstan-param array<array-key, mixed> $config
     */
    private function clientFor(array $config): Client
    {
        $overrides = array_intersect_key($config, array_flip(Config::overridableKeys()));

        if ($overrides === []) {
            return $this->app->make(Client::class);
        }

        return $this->build($config);
    }

    /**
     * Build one SDK client, honouring a PSR-18 client the application bound.
     *
     * Every client this package creates goes through here, so an application that binds its own
     * HTTP client (a proxy, instrumentation, a retry middleware) gets it used on every mailer
     * rather than on whichever one happened to resolve from the container.
     *
     * @phpstan-param array<array-key, mixed> $mailer
     */
    private function build(array $mailer = []): Client
    {
        $config = $this->app->make(ConfigRepository::class);
        $channel = Config::logChannel($config);

        return Config::client(
            $config,
            $mailer,
            $this->app->bound(HttpClient::class) ? $this->app->make(HttpClient::class) : null,
            // Resolved here rather than inside `Config`, so the one config module keeps mapping
            // settings onto SDK arguments and the container lookups all stay in this class.
            $channel === null ? null : Log::channel($channel),
        );
    }

    /**
     * Register the webhook endpoint, but only when the application asked for one.
     *
     * Default is off. A package that mounts an unauthenticated POST route on installation is a
     * surprise, and the surprise is a security one.
     */
    private function registerWebhookRoute(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $path = $config->get('mailkube.webhooks.path');

        if (! is_string($path) || $path === '') {
            return;
        }

        $middleware = $config->get('mailkube.webhooks.middleware', ['api']);
        $groups = is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : ['api'];

        Route::post($path, WebhookController::class)
            ->middleware($groups)
            ->name('mailkube.webhooks');
    }
}
