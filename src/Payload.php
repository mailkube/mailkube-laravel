<?php

declare(strict_types=1);

namespace Mailkube\Laravel;

use Mailkube\Model\Attachment;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * The one place a Symfony message becomes SDK arguments.
 *
 * Nothing here serializes anything. The SDK owns the wire format; this class only decides which
 * part of a {@see Email} answers which SDK argument, which is a question only Laravel's mail stack
 * poses and therefore the one thing that genuinely belongs in this package.
 */
final class Payload
{
    /**
     * Headers the API derives from the arguments above, so re-sending them as custom headers would
     * either duplicate or contradict what the SDK already sends.
     *
     * `content-type` and `content-transfer-encoding` matter most: they describe a MIME document
     * that is never transmitted, because this transport hands over structured fields instead.
     *
     * @var list<string>
     */
    private const DERIVED_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'reply-to',
        'subject',
        'date',
        'message-id',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
    ];

    /**
     * Convert a Symfony message into the arguments the SDK's send verb takes.
     *
     * The return type is an explicit shape rather than `array<string, mixed>`, and the optional
     * keys are added by hand rather than filtered out afterwards. `array_filter` would erase the
     * shape, and the caller spreads this as named arguments: with a loose type, every argument
     * arrives as `mixed` and the type checker can no longer tell that `from` is the string the SDK
     * requires.
     *
     * @phpstan-return array{
     *     from: string,
     *     to: list<string>,
     *     subject: string,
     *     html?: string,
     *     text?: string,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     replyTo?: list<string>,
     *     headers?: array<string, string>,
     *     attachments?: list<Attachment>,
     * }
     */
    public static function build(Email $email, Envelope $envelope): array
    {
        $to = self::addresses($email->getTo());
        $cc = self::addresses($email->getCc());

        $payload = [
            // The SENDER comes from the envelope, not from the message. `Mail::alwaysFrom` and
            // Symfony's own EnvelopeListener rewrite the envelope and leave the message's From
            // header alone, so reading the message here would ignore a global the application set.
            'from' => $envelope->getSender()->toString(),
            'to' => $to,
            'subject' => $email->getSubject() ?? '',
        ];

        $html = self::body($email->getHtmlBody());
        if ($html !== null) {
            $payload['html'] = $html;
        }

        $text = self::body($email->getTextBody());
        if ($text !== null) {
            $payload['text'] = $text;
        }

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        // BCC is the one field neither source answers alone. It is absent from the message by
        // definition, and the envelope holds every recipient with no way to tell which was which,
        // so it is the envelope's recipients minus the visible ones. Taking recipients from the
        // envelope wholesale would publish the blind copies in `to`.
        $bcc = self::blindCopies($envelope, $to, $cc);
        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = self::addresses($email->getReplyTo());
        if ($replyTo !== []) {
            $payload['replyTo'] = $replyTo;
        }

        $headers = self::headers($email);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $attachments = self::attachments($email);
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Render addresses in the `Name <local@domain>` form, so display names survive.
     *
     * @phpstan-param array<Address> $addresses
     * @phpstan-return list<string>
     */
    private static function addresses(array $addresses): array
    {
        // array_values, because array_map preserves the input's keys and Symfony's getters do
        // not promise a reindexed array. Without it the result is not a list.
        return array_values(
            array_map(static fn (Address $address): string => $address->toString(), $addresses),
        );
    }

    /**
     * Return the envelope recipients that appear in neither `to` nor `cc`.
     *
     * @phpstan-param list<string> $to
     * @phpstan-param list<string> $cc
     * @phpstan-return list<string>
     */
    private static function blindCopies(Envelope $envelope, array $to, array $cc): array
    {
        $visible = array_map(
            static fn (string $address): string => strtolower(self::localPart($address)),
            [...$to, ...$cc],
        );

        $blind = [];

        foreach ($envelope->getRecipients() as $recipient) {
            if (! in_array(strtolower($recipient->getAddress()), $visible, true)) {
                $blind[] = $recipient->toString();
            }
        }

        return $blind;
    }

    /**
     * Extract the bare address from a rendered `Name <local@domain>` string.
     *
     * The comparison above is between an envelope recipient (always bare) and a rendered visible
     * recipient (possibly carrying a display name), so one side has to be reduced to the other.
     */
    private static function localPart(string $rendered): string
    {
        if (preg_match('/<([^>]+)>/', $rendered, $matches) === 1) {
            return $matches[1];
        }

        return $rendered;
    }

    /**
     * Read a message body, which Symfony may hold as a stream rather than a string.
     *
     * @phpstan-param resource|string|null $body
     */
    private static function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * Convert Symfony attachments into SDK attachments.
     *
     * `getBody()` returns the DECODED bytes, so they go through `fromBytes` and the SDK does the
     * base64 encoding. Passing them as pre-encoded would ship base64 of base64, which arrives as a
     * file the recipient cannot open and which no test that only counts attachments would catch.
     *
     * @phpstan-return list<Attachment>
     */
    private static function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            $attachments[] = Attachment::fromBytes(
                self::filename($part),
                $part->getBody(),
                $part->getMediaType() . '/' . $part->getMediaSubtype(),
            );
        }

        return $attachments;
    }

    /**
     * Return an attachment's filename, falling back to its name when none was set.
     */
    private static function filename(DataPart $part): string
    {
        $filename = $part->getFilename();

        return $filename ?? $part->getName() ?? 'attachment';
    }

    /**
     * Collect the custom headers, dropping the ones the API derives from the arguments.
     *
     * @phpstan-return array<string, string>
     */
    private static function headers(Email $email): array
    {
        $headers = [];

        /** @var HeaderInterface $header */
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if (in_array($name, self::DERIVED_HEADERS, true)) {
                continue;
            }
            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }
}
