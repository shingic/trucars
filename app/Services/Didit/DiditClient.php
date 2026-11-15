<?php

declare(strict_types=1);

namespace App\Services\Didit;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Didit's verification API (docs.didit.me): create a hosted ID-check session
 * and read its decision. Keys come from Business Console → Developers → API
 * keys; a sandbox application's key (sk_test_…) runs free test sessions.
 */
class DiditClient
{
    public const BASE_URL = 'https://verification.didit.me';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->workflowId() !== '';
    }

    /**
     * @return array<string, mixed> session_id, url, status, expires_at, …
     *
     * @throws RequestException
     */
    public function createSession(string $vendorData, string $callbackUrl): array
    {
        return $this->http()->post('/v3/session/', [
            'workflow_id'     => $this->workflowId(),
            'vendor_data'     => $vendorData,
            'callback'        => $callbackUrl,
            'callback_method' => 'both',
            'language'        => 'en',
        ])->throw()->json();
    }

    /**
     * @return array<string, mixed> status, id_verifications[], …
     *
     * @throws RequestException
     */
    public function decision(string $sessionId): array
    {
        return $this->http()->get('/v3/session/' . rawurlencode($sessionId) . '/decision/')->throw()->json();
    }

    /**
     * Is the key accepted? Asks for a session that can't exist: 404 means
     * Didit took the key, 401/403 means it didn't.
     *
     * @return array{0: bool, 1: string}
     */
    public function checkKey(): array
    {
        try {
            $status = $this->http()->get('/v3/session/00000000-0000-0000-0000-000000000000/decision/')->status();
        } catch (\Throwable $e) {
            return [false, 'Couldn’t reach Didit: ' . $e->getMessage()];
        }

        return match (true) {
            $status === 404, $status === 200 => [true, 'API key accepted.'],
            $status === 401                  => [false, 'Didit rejected the API key (401). Check DIDIT_API_KEY.'],
            $status === 403                  => [false, 'The API key is missing permissions (403).'],
            default                          => [false, "Unexpected answer from Didit (HTTP {$status})."],
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['x-api-key' => $this->apiKey()])
            ->acceptJson()
            ->timeout(10);
    }

    private function apiKey(): string
    {
        return (string) config('services.didit.api_key');
    }

    private function workflowId(): string
    {
        return (string) config('services.didit.workflow_id');
    }
}
