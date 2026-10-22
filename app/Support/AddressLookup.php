<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Start typing your address" suggestions — Google Places API (New).
 *
 * Calls go through the server so the key stays private. The browser makes
 * a session token per address entry and passes it to both calls, so Google
 * bills the typing and the pick as one session. Any failure returns nothing,
 * and the buyer simply types the address themselves.
 */
class AddressLookup
{
    private const BASE = 'https://places.googleapis.com/v1';

    public function enabled(): bool
    {
        return filled(config('services.google_places.key'));
    }

    /**
     * Canadian address suggestions for what's been typed so far.
     *
     * @return array<int, array{id: string, text: string, description: string}>
     */
    public function find(string $term, string $sessionToken): array
    {
        $term = trim($term);
        if (! $this->enabled() || mb_strlen($term) < 3) {
            return [];
        }

        $response = $this->call(fn ($http) => $http->post(self::BASE . '/places:autocomplete', [
            'input'               => $term,
            'sessionToken'        => $sessionToken,
            'includedRegionCodes' => ['ca'],
            'languageCode'        => 'en',
        ]));

        return collect($response['suggestions'] ?? [])
            ->pluck('placePrediction')
            ->filter(fn ($prediction) => isset($prediction['placeId']))
            ->map(fn ($prediction) => [
                'id'          => (string) $prediction['placeId'],
                'text'        => (string) ($prediction['structuredFormat']['mainText']['text'] ?? $prediction['text']['text'] ?? ''),
                'description' => (string) ($prediction['structuredFormat']['secondaryText']['text'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * One full address, ready for the form.
     *
     * @return array{street: string, city: string, province: string, postal_code: string}|null
     */
    public function retrieve(string $placeId, string $sessionToken): ?array
    {
        if (! $this->enabled() || ! preg_match('/^[A-Za-z0-9_-]+$/', $placeId)) {
            return null;
        }

        $place = $this->call(fn ($http) => $http
            ->withHeaders(['X-Goog-FieldMask' => 'addressComponents'])
            ->get(self::BASE . '/places/' . $placeId, ['sessionToken' => $sessionToken, 'languageCode' => 'en']));

        $part = function (string $type, string $form = 'longText') use ($place): string {
            foreach ($place['addressComponents'] ?? [] as $component) {
                if (in_array($type, $component['types'] ?? [], true)) {
                    return (string) ($component[$form] ?? '');
                }
            }

            return '';
        };

        $street = trim($part('street_number') . ' ' . $part('route', 'shortText'));
        if ($street === '') {
            return null; // a city or a business with no street address — nothing useful to fill
        }

        $unit = $part('subpremise');

        return [
            'street'      => $unit !== '' ? "{$street}, Unit {$unit}" : $street,
            'city'        => $part('locality') ?: $part('postal_town') ?: $part('sublocality') ?: $part('administrative_area_level_3'),
            'province'    => CanadianAddress::PROVINCES[$part('administrative_area_level_1', 'shortText')] ?? $part('administrative_area_level_1'),
            'postal_code' => $part('postal_code'),
        ];
    }

    /** Send one request to Google; problems are logged and come back empty. */
    private function call(\Closure $send): array
    {
        try {
            $http = Http::timeout(4)->withHeaders(['X-Goog-Api-Key' => (string) config('services.google_places.key')]);

            return $send($http)->throw()->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('Address lookup failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
