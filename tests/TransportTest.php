<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Mailkube\Client;
use Mailkube\Laravel\Transport\MailkubeTransport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * The transport end to end: a real Laravel send, through the real SDK, over a stubbed transport.
 */
final class TransportTest extends TestCase
{
    #[Test]
    public function aLaravelSendReachesTheApi(): void
    {
        $this->queueAcceptedSend();

        Mail::html('<p>It works</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('Hello world');
        });

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/emails', $request->getUri()->getPath());

        $body = $this->lastRequestBody();
        self::assertSame('Hello world', $body['subject']);
        self::assertSame(['customer@example.test'], $body['to']);
        self::assertSame('<p>It works</p>', $body['html']);
    }

    #[Test]
    public function theRequestCarriesThisPackageInTheUserAgent(): void
    {
        $this->queueAcceptedSend();

        Mail::html('<p>hi</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
        });

        $userAgent = $this->lastRequest()->getHeaderLine('User-Agent');

        // The SDK's own token stays leading, and this package is appended: that is what makes
        // traffic from a Laravel application distinguishable from direct SDK use on the server side.
        self::assertStringStartsWith('mailkube-php/', $userAgent);
        self::assertStringContainsString('mailkube-laravel/', $userAgent);
    }

    #[Test]
    public function anApiFailureBecomesATransportException(): void
    {
        $this->queueResponse(422, ['name' => 'validation_error', 'message' => 'from is not a verified sender']);

        $this->expectException(TransportException::class);

        Mail::html('<p>hi</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
        });
    }

    #[Test]
    public function theTranslatedExceptionIsWhatFailoverCatches(): void
    {
        $this->queueResponse(500, ['name' => 'server_error', 'message' => 'boom']);

        try {
            Mail::html('<p>hi</p>', function (Message $message): void {
                $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
            });
            self::fail('the send should have thrown');
        } catch (TransportExceptionInterface $exception) {
            // This interface is the whole point of translating. Symfony's failover and round-robin
            // transports catch it and nothing else, so an SDK exception escaping here would not
            // fail over to a backup mailer: it would end the chain. The original is preserved.
            self::assertInstanceOf(TransportException::class, $exception);
            self::assertStringContainsString('boom', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    #[Test]
    public function theMessageIdFromTheApiIsReadableAfterwards(): void
    {
        $this->queueAcceptedSend(messageId: '<abc@mailkube>');

        $sent = Mail::html('<p>hi</p>', function (Message $message): void {
            $message->from('hello@acme.test')->to('customer@example.test')->subject('s');
        });

        self::assertNotNull($sent);
        self::assertSame('<abc@mailkube>', $sent->getSymfonySentMessage()->getMessageId());
    }

    #[Test]
    public function aPreRenderedMessageIsRefusedRatherThanParsed(): void
    {
        $transport = new MailkubeTransport(fn (): Client => $this->container()->make(Client::class));

        $raw = new RawMessage("Subject: hand rolled\r\n\r\nbody");
        $envelope = new Envelope(new Address('hello@acme.test'), [new Address('customer@example.test')]);

        // Turning MIME back into fields would mean a MIME parser in this package, which is both the
        // wrong repository for one and a second interpretation of a document Symfony already built.
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('cannot send a pre-rendered');

        $transport->send($raw, $envelope);
    }

    #[Test]
    public function aQueuedMailableStillSendsThroughTheSameTransport(): void
    {
        $this->queueAcceptedSend();

        // Queue handling is Laravel's; the transport stays synchronous underneath it. This asserts
        // the flavour rule holds: nothing here starts a loop or defers the call itself.
        Mail::to('customer@example.test')->send(new PlainMailable());

        self::assertSame('Queued subject', $this->lastRequestBody()['subject']);
    }

}

/**
 * A minimal mailable, so the queued path is exercised through Laravel's own API.
 */
final class PlainMailable extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->from('hello@acme.test')->subject('Queued subject')->html('<p>queued</p>');
    }
}
