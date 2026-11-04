<?php

namespace App\Services\Carfax\Providers;

use App\Services\Carfax\CarfaxReport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CARFAX Canada's Badging / VHR look-up API (CARFAX_DRIVER=carfax_canada).
 *
 * TO CONFIRM: CARFAX Canada only gives out the API spec to integration
 * partners (integrationsupport@carfax.ca). The request in lookup() and the
 * field names in toReport() are placeholders until we have that spec. Only
 * this class should change when it arrives. The rest of the feature (dealer
 * settings, caching, the vehicle page) doesn't depend on the wire format.
 */
class CarfaxCanadaProvider implements CarfaxProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 4,
    ) {}

    public function lookup(string $accountId, string $vin): ?CarfaxReport
    {
        if (blank($this->baseUrl) || blank($this->apiKey)) {
            throw new RuntimeException('CARFAX Canada is not configured: set CARFAX_BASE_URL and CARFAX_API_KEY.');
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->get('/vehicles/' . rawurlencode($vin), ['accountId' => $accountId]);

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return $this->toReport((array) $response->json());
    }

    /** @param  array<string, mixed>  $data */
    private function toReport(array $data): ?CarfaxReport
    {
        $report = new CarfaxReport(
            reportUrl: $this->httpsUrl($data['reportUrl'] ?? null),
            accidentCount: isset($data['accidentCount']) ? (int) $data['accidentCount'] : null,
            ownerCount: isset($data['ownerCount']) ? (int) $data['ownerCount'] : null,
            serviceRecordCount: isset($data['serviceRecordCount']) ? (int) $data['serviceRecordCount'] : null,
            registeredIn: array_values(array_filter((array) ($data['registeredIn'] ?? []), 'is_string')),
            stolen: (bool) ($data['stolen'] ?? false),
            badges: array_values(array_filter((array) ($data['badges'] ?? []), 'is_string')),
        );

        return $report->isEmpty() ? null : $report;
    }

    /** Only https links make it onto the page. */
    private function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
