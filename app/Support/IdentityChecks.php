<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Deal;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Didit\DiditClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The ID check at checkout, through Didit's hosted page:
 *
 *  1. start()   — a Didit session is created and the buyer is sent to its URL.
 *  2. The buyer scans their licence and takes a selfie at Didit.
 *  3. refresh() — on their way back, and on each signed webhook, Didit itself
 *                 is asked for the decision; nothing the browser says counts.
 *
 * Demo mode (Platform settings → ID checks) skips Didit: the buyer is
 * approved on the spot, so the site can be shown without a real ID.
 */
class IdentityChecks
{
    public function __construct(private DiditClient $didit) {}

    public function demo(): bool
    {
        return PlatformSettings::get(PlatformSettings::IDENTITY_MODE) !== PlatformSettings::IDENTITY_LIVE;
    }

    /** Live checks can be switched on — the Didit key and workflow are set. */
    public function liveReady(): bool
    {
        return $this->didit->isConfigured();
    }

    /** @return array{0: bool, 1: string} */
    public function checkConnection(): array
    {
        return $this->liveReady()
            ? $this->didit->checkKey()
            : [false, 'DIDIT_API_KEY and DIDIT_WORKFLOW_ID aren’t set in the server’s .env.'];
    }

    /**
     * The buyer's latest check that counts in the current mode. A demo
     * approval never counts once checks are live.
     */
    public function current(User $buyer): ?IdentityVerification
    {
        return IdentityVerification::query()
            ->where('user_id', $buyer->id)
            ->when(! $this->demo(), fn ($query) => $query->where('provider', IdentityVerification::PROVIDER_DIDIT))
            ->latest('id')
            ->first();
    }

    /** Demo mode: approved straight away, no ID asked for. */
    public function approveDemo(User $buyer): IdentityVerification
    {
        return IdentityVerification::create([
            'user_id'    => $buyer->id,
            'provider'   => IdentityVerification::PROVIDER_DEMO,
            'session_id' => 'demo_' . Str::random(20),
            'status'     => IdentityVerification::APPROVED,
            'decided_at' => now(),
        ]);
    }

    /**
     * A Didit session for this buyer; send them to its session_url. Didit
     * hands back the open session if the buyer already has one unfinished.
     */
    public function start(User $buyer, string $returnUrl): IdentityVerification
    {
        $session = $this->didit->createSession('user-' . $buyer->id, $returnUrl);

        $verification = IdentityVerification::firstOrNew(['session_id' => (string) $session['session_id']]);
        $verification->fill([
            'user_id'     => $buyer->id,
            'provider'    => IdentityVerification::PROVIDER_DIDIT,
            'session_url' => (string) $session['url'],
            'status'      => (string) ($session['status'] ?? IdentityVerification::NOT_STARTED),
        ])->save();

        return $verification;
    }

    /** Ask Didit for the decision and keep what staff need from it. */
    public function refresh(IdentityVerification $verification): IdentityVerification
    {
        if ($verification->isDemo()) {
            return $verification;
        }

        $decision = $this->didit->decision($verification->session_id);
        $document = $decision['id_verifications'][0] ?? $decision['id_verification'] ?? [];
        $status = (string) ($decision['status'] ?? $verification->status);

        $verification->fill([
            'status'              => $status,
            'first_name'          => $document['first_name'] ?? $verification->first_name,
            'last_name'           => $document['last_name'] ?? $verification->last_name,
            'date_of_birth'       => $this->date($document['date_of_birth'] ?? null) ?? $verification->date_of_birth,
            'document_type'       => $document['document_type'] ?? $verification->document_type,
            'document_expires_on' => $this->date($document['expiration_date'] ?? null) ?? $verification->document_expires_on,
        ]);

        $decided = in_array($status, [IdentityVerification::APPROVED, IdentityVerification::DECLINED], true);
        $verification->decided_at = $decided ? ($verification->decided_at ?? now()) : null;
        $verification->save();

        // A reservation made while the ID was under review counts as verified once Didit approves it.
        if ($verification->isApproved()) {
            Deal::withoutGlobalScopes()
                ->where('identity_verification_id', $verification->id)
                ->whereNull('identity_verified_at')
                ->update(['identity_verified_at' => $verification->decided_at]);
        }

        return $verification;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
