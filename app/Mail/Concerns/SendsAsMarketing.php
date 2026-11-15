<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\MessageStreamHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

/**
 * Marketing email goes out through its own sender — Postmark's broadcast
 * stream and the marketing from-address — so a spam complaint or unsubscribe
 * there can never block a shopper's receipts or verification codes, which
 * stay on the transactional stream.
 *
 * config/mail.php → 'marketing': MAIL_MARKETING_STREAM (default "broadcast"),
 * MAIL_MARKETING_FROM_ADDRESS / _NAME (default: the normal from-address).
 */
trait SendsAsMarketing
{
    /** Call from the constructor. $tag shows up in Postmark's stats (e.g. "recovery_1d"). */
    protected function sendAsMarketing(string $tag): void
    {
        $stream = (string) config('mail.marketing.stream');

        $this->withSymfonyMessage(function (Email $message) use ($stream, $tag) {
            $headers = $message->getHeaders();

            if ($stream !== '' && ! $headers->has('X-PM-Message-Stream')) {
                $headers->add(new MessageStreamHeader($stream));
            }

            if (! $headers->has('X-Tag')) {
                $headers->add(new TagHeader($tag));
            }
        });
    }

    protected static function marketingFrom(): Address
    {
        return new Address(
            (string) (config('mail.marketing.from.address') ?: config('mail.from.address')),
            (string) (config('mail.marketing.from.name') ?: config('mail.from.name')),
        );
    }
}
