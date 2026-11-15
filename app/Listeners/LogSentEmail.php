<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Deal;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\Email\Catalogue;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Writes every sent email to the email log, typed by the catalogue, for the
 * Email Centre. Never lets a logging problem affect the email itself.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $data = $event->data;
            $type = Catalogue::typeFor((string) ($data['__laravel_mailable'] ?? ''), isset($data['kind']) ? (string) $data['kind'] : null);
            $message = $event->sent->getOriginalMessage();
            $subject = method_exists($message, 'getSubject') ? $message->getSubject() : null;

            $deal = collect($data)->first(fn ($value) => $value instanceof Deal);
            $user = collect([$data['user'] ?? null, $data['buyer'] ?? null])->first(fn ($value) => $value instanceof User);

            foreach ($event->sent->getEnvelope()->getRecipients() as $recipient) {
                $address = $recipient->getAddress();

                EmailLog::create([
                    'type'     => $type,
                    'user_id'  => $user?->id ?? $deal?->user_id ?? User::where('email', $address)->value('id'),
                    'deal_id'  => $deal?->id,
                    'to_email' => $address,
                    'subject'  => $subject !== null ? mb_substr($subject, 0, 300) : null,
                    'sent_at'  => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Email log write failed', ['error' => $e->getMessage()]);
        }
    }
}
