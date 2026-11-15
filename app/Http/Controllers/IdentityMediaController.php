<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\IdentityVerification;
use App\Models\Scopes\DealerScope;
use App\Services\Didit\DiditClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's ID photos and Didit's PDF report, for the dealership holding
 * the reservation (e.g. to attach to the Dealertrack credit application) and
 * for Command Centre staff with reservations.view.
 *
 * Nothing is stored here: every request fetches fresh from Didit and passes
 * the file through, so Didit's short-lived links never reach a browser. Only
 * after the deposit (a deal exists), never on a cancelled deal, and every
 * view or download is audited.
 */
class IdentityMediaController extends Controller
{
    public const PARTS = [
        'front'  => 'licence-front',
        'back'   => 'licence-back',
        'selfie' => 'selfie',
        'report' => 'id-report',
    ];

    /** Staff host: the deal is fenced to the signed-in dealership by DealerScope. */
    public function forDealer(Deal $deal, string $part, Request $request, DiditClient $didit): Response
    {
        return $this->respond($deal, $part, $request, $didit);
    }

    /** Command Centre host (reservations.view, checked by the route). */
    public function forCommandCentre(int $deal, string $part, Request $request, DiditClient $didit): Response
    {
        return $this->respond(Deal::withoutGlobalScope(DealerScope::class)->findOrFail($deal), $part, $request, $didit);
    }

    public static function available(Deal $deal): bool
    {
        $verification = $deal->identityVerification;

        return $verification !== null
            && ! $verification->isDemo()
            && $deal->stage !== 'cancelled'
            && in_array($verification->status, [IdentityVerification::APPROVED, IdentityVerification::IN_REVIEW, IdentityVerification::KYC_EXPIRED], true);
    }

    private function respond(Deal $deal, string $part, Request $request, DiditClient $didit): Response
    {
        abort_unless(array_key_exists($part, self::PARTS) && self::available($deal), 404);

        $sessionId = $deal->identityVerification->session_id;
        $download = $request->boolean('download') || $part === 'report';
        $baseName = Str::slug(trim($deal->last_name . ' ' . $deal->first_name) ?: (string) $deal->reference) . '-' . self::PARTS[$part];

        try {
            if ($part === 'report') {
                [$bytes, $type, $extension] = [$didit->reportPdf($sessionId), 'application/pdf', 'pdf'];
            } else {
                $url = $this->mediaUrl($didit, $sessionId, $part);
                abort_if($url === null, 404);
                [$bytes, $type] = $didit->media($url);
                $extension = str_contains($type, 'png') ? 'png' : 'jpg';
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Didit media fetch failed', ['deal_id' => $deal->id, 'part' => $part, 'error' => $e->getMessage()]);
            Cache::forget($this->cacheKey($sessionId));

            return response('The ID photos couldn’t be loaded from Didit just now. Try again in a moment.', 502);
        }

        AuditLog::record($download ? 'identity.downloaded' : 'identity.viewed', $deal, null, ['reference' => $deal->reference, 'part' => $part]);

        return response($bytes, 200, [
            'Content-Type'           => $type,
            'Content-Disposition'    => ($download ? 'attachment' : 'inline') . '; filename="' . $baseName . '.' . $extension . '"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The presigned URL for one photo, from a decision fetched at most a minute ago. */
    private function mediaUrl(DiditClient $didit, string $sessionId, string $part): ?string
    {
        $decision = Cache::remember($this->cacheKey($sessionId), 60, fn () => $didit->decision($sessionId));
        $document = $decision['id_verifications'][0] ?? [];
        $liveness = $decision['liveness_checks'][0] ?? [];

        $url = match ($part) {
            'front'  => $document['front_image'] ?? $document['full_front_image'] ?? null,
            'back'   => $document['back_image'] ?? $document['full_back_image'] ?? null,
            'selfie' => $liveness['reference_image'] ?? ($decision['face_matches'][0]['target_image'] ?? null),
        };

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function cacheKey(string $sessionId): string
    {
        return 'didit-decision:' . $sessionId;
    }
}
