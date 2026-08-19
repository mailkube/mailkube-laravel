<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Transport;

use Closure;
use Mailkube\Client;
use Mailkube\Exception\MailkubeException;
use Mailkube\Laravel\Payload;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * The `mailkube` mail transport.
 *
 * It extends {@see AbstractTransport}, which handles the parts of sending that are the same for
 * every transport (dispatching Symfony's mailer events, honouring the message envelope) and leaves
 * {@see self::doSend()} to actually deliver. Deliberately **not** `AbstractHttpTransport`: that base
 * opens its own HTTP client and expects this class to build a request, which would put a second
 * copy of the wire format in this package. The SDK already owns it.
 *
 * Synchronous, because `doSend()` is: the mail stack calls it and expects delivery to have been
 * attempted when it returns. Queueing is Laravel's job, through `Mail::queue`, and running an event
 * loop underneath a synchronous hook to reach an async client would be a way to deadlock a worker.
 */
final class MailkubeTransport extends AbstractTransport
{
    /**
     * @phpstan-param Closure(): Client $client Resolves the SDK client, once, on the first send.
     */
    public function __construct(private readonly Closure $client)
    {
        parent::__construct();
    }

    /**
     * Return the DSN-ish identity Symfony prints when it names this transport.
     */
    public function __toString(): string
    {
        return 'mailkube://';
    }

    /**
     * Deliver one message through the SDK.
     *
     * @throws TransportException When the API rejects the send, or when the message is not one
     *                            this transport can express.
     */
    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        // A RawMessage that is not an Email carries only pre-rendered MIME. Parsing it back into
        // fields would mean implementing a MIME parser here, which is both the wrong repository for
        // that and a second interpretation of a document Symfony already built. Refusing is honest;
        // this arises only from `Mailer::send()` called with a hand-built RawMessage.
        if (! $original instanceof Email) {
            throw new TransportException(sprintf(
                'The mailkube transport needs a %s; it cannot send a pre-rendered %s.',
                Email::class,
                $original::class,
            ));
        }

        try {
            $sent = ($this->client)()->emails->send(...Payload::build($original, $message->getEnvelope()));
        } catch (MailkubeException $exception) {
            // Translate at the boundary. Symfony's failover and round-robin transports catch
            // TransportExceptionInterface and nothing else, so an SDK exception escaping here does
            // not fail over to the backup mailer: it ends the chain. Laravel's queued-mail failure
            // handling keys off the same interface.
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        // What the application reads back from `SentMessage::getMessageId()`, and usually logs.
        $message->setMessageId($sent->messageId ?? $sent->id);
    }
}
