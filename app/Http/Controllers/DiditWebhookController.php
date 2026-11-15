<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IdentityVerification;
use App\Support\IdentityChecks;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Didit → TruCars. A status change on an ID check (status.updated). Only
 * signed, fresh requests are accepted, and even then the decision is read
 * back from Didit's API rather than taken from the payload.
 *
 * Set up in Didit's Business Console → API & Webhooks: this URL, webhook
 * version v3, event status.updated; its secret goes in DIDIT_WEBHOOK_SECRET.
 */
class DiditWebhookController extends Controller
{
    /** Didit's limit: a request more than 5 minutes off is refused. */
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, IdentityChecks $checks): Response
    {
        $secret = (string) config('services.didit.webhook_secret');
        abort_if($secret === '', 404);

        $raw = $request->getContent();
        $timestamp = (int) $request->header('X-Timestamp');

        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS || ! self::signedBy($secret, $raw, $request)) {
            return response('Invalid signature', 401);
        }

        // Shown on Platform settings → ID checks as proof the webhook works.
        PlatformSettings::put(PlatformSettings::DIDIT_WEBHOOK_LAST_SEEN, now()->toIso8601String(), null);

        $sessionId = (string) (json_decode($raw, true)['session_id'] ?? '');
        $verification = $sessionId === '' ? null : IdentityVerification::firstWhere('session_id', $sessionId);

        if ($verification !== null) {
            try {
                $checks->refresh($verification);
            } catch (\Throwable $e) {
                Log::warning('Didit decision fetch failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);

                return response('Try again', 503); // Didit retries twice
            }
        }

        return response('ok');
    }

    /**
     * X-Signature-V2: HMAC-SHA256 of the body re-encoded canonically (keys
     * sorted, compact, slashes and unicode unescaped, whole floats as
     * integers) — survives proxies that re-format JSON. X-Signature: of the
     * raw bytes as sent.
     */
    public static function signedBy(string $secret, string $raw, Request $request): bool
    {
        $v2 = (string) $request->header('X-Signature-V2');
        if ($v2 !== '') {
            $decoded = json_decode($raw);
            if ($decoded !== null && hash_equals(hash_hmac('sha256', self::canonical($decoded), $secret), $v2)) {
                return true;
            }
        }

        $v1 = (string) $request->header('X-Signature');

        return $v1 !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $v1);
    }

    public static function canonical(mixed $payload): string
    {
        return json_encode(self::sorted($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new stdClass;
            foreach ($properties as $key => $item) {
                $sorted->{$key} = self::sorted($item);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        return $value;
    }
}
