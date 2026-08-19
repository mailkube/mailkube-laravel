<?php

declare(strict_types=1);

namespace Mailkube\Laravel\Tests;

use Mailkube\Laravel\Payload;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The payload module's contract: which part of a Symfony message answers which SDK argument.
 */
final class PayloadTest extends TestCase
{
    #[Test]
    public function itMapsTheOrdinaryFields(): void
    {
        $email = (new Email())
            ->from('Acme <hello@acme.test>')
            ->to('customer@example.test')
            ->subject('Hello world')
            ->text('plain')
            ->html('<p>rich</p>');

        $payload = Payload::build($email, $this->envelope($email));

        // Symfony quotes the display name when it renders an address, so this is its output rather
        // than the input string. The API accepts either.
        self::assertSame('"Acme" <hello@acme.test>', $payload['from']);
        self::assertSame(['customer@example.test'], $payload['to']);
        self::assertSame('Hello world', $payload['subject']);
        self::assertArrayHasKey('text', $payload);
        self::assertSame('plain', $payload['text']);
        self::assertArrayHasKey('html', $payload);
        self::assertSame('<p>rich</p>', $payload['html']);
    }

    #[Test]
    public function itKeepsDisplayNamesOnRecipients(): void
    {
        $email = (new Email())
            ->from('hello@acme.test')
            ->to(new Address('customer@example.test', 'A Customer'))
            ->subject('s');

        $payload = Payload::build($email, $this->envelope($email));

        // `.toString()` rather than the bare address: a recipient's display name is part of what
        // the application asked to send, and dropping it is invisible until someone reads an inbox.
        self::assertSame(['"A Customer" <customer@example.test>'], $payload['to']);
    }

    #[Test]
    public function theSenderComesFromTheEnvelopeSoGlobalOverridesAreHonoured(): void
    {
        $email = (new Email())->from('written@acme.test')->to('customer@example.test')->subject('s');

        // What `Mail::alwaysFrom` and Symfony's EnvelopeListener do: rewrite the envelope and leave
        // the message header alone. Reading the message here would ignore the application's global.
        $envelope = new Envelope(new Address('forced@acme.test'), [new Address('customer@example.test')]);

        self::assertSame('forced@acme.test', Payload::build($email, $envelope)['from']);
    }

    #[Test]
    public function blindCopiesAreTheEnvelopeRecipientsThatAreNotVisible(): void
    {
        $email = (new Email())
            ->from('hello@acme.test')
            ->to('to@example.test')
            ->cc('cc@example.test')
            ->bcc('bcc@example.test')
            ->subject('s');

        $payload = Payload::build($email, $this->envelope($email));

        self::assertSame(['to@example.test'], $payload['to']);
        self::assertArrayHasKey('cc', $payload);
        self::assertSame(['cc@example.test'], $payload['cc']);
        self::assertArrayHasKey('bcc', $payload);
        self::assertSame(['bcc@example.test'], $payload['bcc']);
    }

    #[Test]
    public function aBlindCopyIsNeverPublishedAsAVisibleRecipient(): void
    {
        $email = (new Email())
            ->from('hello@acme.test')
            ->to('to@example.test')
            ->bcc('secret@example.test')
            ->subject('s');

        $payload = Payload::build($email, $this->envelope($email));

        // The failure this pins is a disclosure, not a crash: taking recipients wholesale from the
        // envelope puts every blind copy in `to`, and every recipient then sees the whole list.
        self::assertSame(['to@example.test'], $payload['to']);
        self::assertNotContains('secret@example.test', $payload['to']);
    }

    #[Test]
    public function aDisplayNameOnAVisibleRecipientDoesNotTurnItIntoABlindCopy(): void
    {
        $email = (new Email())
            ->from('hello@acme.test')
            ->to(new Address('customer@example.test', 'A Customer'))
            ->subject('s');

        // The envelope stores bare addresses while the visible list is rendered with display names,
        // so a naive string comparison finds no match and files the recipient under `bcc` as well.
        self::assertArrayNotHasKey('bcc', Payload::build($email, $this->envelope($email)));
    }

    #[Test]
    public function itReadsBodiesHeldAsStreams(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'streamed body');
        rewind($stream);

        $email = (new Email())->from('hello@acme.test')->to('customer@example.test')->subject('s');
        $email->text($stream);

        // Symfony may hold a body as a resource rather than a string, and a plain cast would send
        // the literal "Resource id #5".
        $payload = Payload::build($email, $this->envelope($email));
        self::assertArrayHasKey('text', $payload);
        self::assertSame('streamed body', $payload['text']);
    }

    #[Test]
    public function itConvertsAttachmentsAsDecodedBytes(): void
    {
        $email = (new Email())
            ->from('hello@acme.test')
            ->to('customer@example.test')
            ->subject('s')
            ->attach('raw bytes', 'notes.txt', 'text/plain');

        $payload = Payload::build($email, $this->envelope($email));

        $attachments = $payload['attachments'] ?? [];
        self::assertCount(1, $attachments);
        $attachment = $attachments[0];
        self::assertSame('notes.txt', $attachment->filename);
        self::assertSame('text/plain', $attachment->contentType);
    }

    #[Test]
    public function itForwardsCustomHeadersAndDropsTheDerivedOnes(): void
    {
        $email = (new Email())->from('hello@acme.test')->to('customer@example.test')->subject('s');
        $email->getHeaders()->addTextHeader('X-Campaign', 'spring');

        $payload = Payload::build($email, $this->envelope($email));
        self::assertArrayHasKey('headers', $payload);
        $headers = $payload['headers'];

        self::assertSame(['X-Campaign' => 'spring'], $headers);

        // `content-type` describes a MIME document that is never transmitted: this transport hands
        // over structured fields instead, so re-sending it would contradict what the API builds.
        self::assertArrayNotHasKey('Content-Type', $headers);
        self::assertArrayNotHasKey('Subject', $headers);
    }

    #[Test]
    public function absentFieldsAreOmittedEntirely(): void
    {
        $email = (new Email())->from('hello@acme.test')->to('customer@example.test')->subject('s')->text('t');

        $payload = Payload::build($email, $this->envelope($email));

        self::assertArrayNotHasKey('html', $payload);
        self::assertArrayNotHasKey('cc', $payload);
        self::assertArrayNotHasKey('attachments', $payload);
    }

    /**
     * Build the envelope Symfony would derive from the message itself.
     */
    private function envelope(Email $email): Envelope
    {
        return Envelope::create($email);
    }
}
