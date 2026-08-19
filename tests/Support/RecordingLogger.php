<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps what it was given, so a test can assert on it.
 *
 * Hand-written rather than mocked: `Psr\Log\Test\TestLogger` was removed in psr/log 2.x, and a
 * mock would need expectations set before the call, which is the wrong shape for "prove the SDK
 * wrote something through the channel Laravel handed it".
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * Every record this logger has been handed, in order.
     *
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * Record one log call.
     *
     * `$context` is typed exactly as PSR-3 types it. Narrowing it to `array<string, mixed>` here
     * would make this override contravariant with the interface, which phpstan rejects and which
     * would be a lie anyway: a caller may key the context however it likes.
     *
     * @phpstan-param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
