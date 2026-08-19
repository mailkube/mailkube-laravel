<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mailkube\Exception\SignatureVerificationException;
use Mailkube\Laravel\Config;
use Mailkube\Laravel\Events\WebhookReceived;
use Mailkube\Webhooks;

/**
 * Receives a webhook delivery, verifies it, and hands it to the application as a Laravel event.
 *
 * There is no cryptography in this class. Verification is the SDK's, and deliberately so: a
 * signature check reimplemented per framework is a signature check that disagrees per framework.
 * What belongs here is the part that is genuinely Laravel's: getting the raw bytes out of a
 * {@see Request}, and dispatching through the framework's own event bus.
 */
final class WebhookController
{
    /**
     * Handle one delivery.
     */
    public function __invoke(Request $request, ConfigRepository $config, Dispatcher $events): JsonResponse
    {
        $secret = Config::webhookSecret($config);

        if ($secret === null) {
            // The route exists, so the application asked for it, but nothing can be verified. A 500
            // is right: this is the receiver misconfigured, not the sender misbehaving, and a 4xx
            // would tell the platform to stop retrying a delivery that is perfectly good.
            return new JsonResponse(
                ['message' => 'No webhook signing secret is configured.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $event = Webhooks::verify(
                // getContent() is the body EXACTLY as received. The signature covers those bytes,
                // so anything that decodes and re-encodes first (a JSON round trip, a middleware
                // that rewrites the input) changes what gets verified and every delivery fails.
                $request->getContent(),
                self::headers($request),
                $secret,
                ...self::tolerance($config),
            );
        } catch (SignatureVerificationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $events->dispatch(new WebhookReceived($event));

        // Acknowledge immediately. Listeners run in this request unless the application queues
        // them, so slow work here becomes delivery latency and eventually a retry storm.
        return new JsonResponse(['message' => 'ok']);
    }

    /**
     * Flatten Symfony's header bag into the single-value map the SDK reads.
     *
     * HTTP allows a header to repeat, so the bag stores every name as a list. The signature headers
     * never repeat, and the first value is the one that was sent.
     *
     * @phpstan-return array<string, string>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;
            if (is_string($first)) {
                $headers[$name] = $first;
            }
        }

        return $headers;
    }

    /**
     * Spread the freshness tolerance only when configured, so the SDK's default survives.
     *
     * @phpstan-return array<string, int>
     */
    private static function tolerance(ConfigRepository $config): array
    {
        $tolerance = Config::webhookTolerance($config);

        return $tolerance === null ? [] : ['toleranceSeconds' => $tolerance];
    }
}
