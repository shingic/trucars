<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The search landing pages the shop can be opened at, built from what's
 * actually in stock: every make and model, body style, fuel type, price band
 * and town that has listed cars.
 *
 *   /used-cars/honda              make
 *   /used-cars/honda/civic        make + model
 *   /used-cars/body/suv           body style
 *   /used-cars/fuel/hybrid        fuel type
 *   /used-cars/under-20000        price band
 *   /used-cars/in/chilliwack-bc   town the cars are in
 *
 * The counts behind it are cached for ten minutes. A page is only linked
 * from the footer and listed in the sitemap once it has enough cars to be
 * worth landing on, so nothing thin gets published. The dealership itself
 * is never named.
 *
 * Town pages are "cars near this city": every car within NEAR_KM of it,
 * since it's pickup only and a Mississauga shopper happily drives to
 * Toronto. The towns are MARKET_CITIES plus any town a dealer is in; a town
 * only gets a page when there are cars near it, so Halifax appears by itself
 * once a dealer there lists cars.
 */
class SeoCatalog
{
    public const PREFIX = '/used-cars';

    /** The price bands that get their own page. */
    public const PRICE_BANDS = [10000, 15000, 20000, 25000, 30000, 40000, 50000];

    /** Fewest cars before a page is linked from the footer. */
    public const FOOTER_MIN = 3;

    /** How close a car has to be for a town's page (straight-line km, as the shop uses). */
    public const NEAR_KM = 100;

    /**
     * Towns worth a page, most searched first (the order the links are shown
     * in): [city, province, latitude, longitude].
     */
    public const MARKET_CITIES = [
        ['Toronto', 'ON', 43.7067, -79.3940],
        ['Vancouver', 'BC', 49.2638, -123.1043],
        ['Mississauga', 'ON', 43.6048, -79.6443],
        ['Brampton', 'ON', 43.7112, -79.7590],
        ['Surrey', 'BC', 49.1271, -122.8219],
        ['Hamilton', 'ON', 43.2424, -79.8429],
        ['Burnaby', 'BC', 49.2439, -122.9736],
        ['Markham', 'ON', 43.8753, -79.2883],
        ['Vaughan', 'ON', 43.8361, -79.4983],
        ['Richmond', 'BC', 49.1633, -123.1225],
        ['Kitchener', 'ON', 43.4335, -80.4774],
        ['Abbotsford', 'BC', 49.0606, -122.3032],
        ['Richmond Hill', 'ON', 43.8908, -79.4223],
        ['Oakville', 'ON', 43.4506, -79.7069],
        ['Coquitlam', 'BC', 49.2508, -122.8619],
        ['Burlington', 'ON', 43.3646, -79.8300],
        ['Oshawa', 'ON', 43.9328, -78.8710],
        ['Langley', 'BC', 49.1042, -122.6604],
        ['Barrie', 'ON', 44.3586, -79.6784],
        ['Guelph', 'ON', 43.5298, -80.2483],
        ['Chilliwack', 'BC', 49.1128, -121.8342],
        ['Cambridge', 'ON', 43.3908, -80.3032],
        ['Whitby', 'ON', 43.9092, -78.9554],
        ['Delta', 'BC', 49.0991, -123.0017],
        ['Ajax', 'ON', 43.8673, -79.0280],
        ['Waterloo', 'ON', 43.4762, -80.5588],
        ['Milton', 'ON', 43.5158, -79.8835],
        ['Maple Ridge', 'BC', 49.2389, -122.5395],
        ['Pickering', 'ON', 43.8729, -79.1147],
        ['Newmarket', 'ON', 44.0499, -79.4734],
        ['New Westminster', 'BC', 49.2044, -122.9191],
        ['North Vancouver', 'BC', 49.3481, -123.0415],
        ['Port Coquitlam', 'BC', 49.2781, -122.7633],
        ['Aurora', 'ON', 44.0001, -79.4663],
        ['Mission', 'BC', 49.4496, -122.4311],
        // Further afield: listed only once a dealer near them has cars.
        ['Ottawa', 'ON', 45.3991, -75.6963],
        ['Calgary', 'AB', 51.0441, -114.0671],
        ['Edmonton', 'AB', 53.5398, -113.4932],
        ['London', 'ON', 42.9664, -81.2417],
        ['Halifax', 'NS', 44.6356, -63.6101],
        ['Victoria', 'BC', 48.4275, -123.3624],
        ['Kelowna', 'BC', 49.9109, -119.4114],
        ['Windsor', 'ON', 42.2871, -82.9910],
        ['Kingston', 'ON', 44.2687, -76.5224],
        ['Dartmouth', 'NS', 44.6926, -63.5287],
        ['Moncton', 'NB', 46.1185, -64.8683],
        ['Saint John', 'NB', 45.2836, -66.0535],
        ['Fredericton', 'NB', 45.9522, -66.6257],
        ['Sydney', 'NS', 46.1268, -60.1843],
        ['Nanaimo', 'BC', 49.2008, -123.9970],
        ['Kamloops', 'BC', 50.6777, -120.3928],
    ];

    private const CACHE_KEY = 'seo:facets:v2';

    private const CACHE_SECONDS = 600;

    /** The landing page at these URL segments, or null when there's no such page. */
    public static function resolve(string $first, ?string $second = null): ?Landing
    {
        $first = strtolower($first);
        $second = $second === null ? null : strtolower($second);
        $facets = self::facets();

        if (in_array($first, ['body', 'fuel', 'in'], true)) {
            $key = ['body' => 'bodies', 'fuel' => 'fuels', 'in' => 'cities'][$first];
            $entry = $second === null ? null : ($facets[$key][$second] ?? null);
            if ($entry !== null && $entry['count'] < 1) {
                $entry = null;
            }

            return $entry ? self::landingFor($first === 'in' ? 'city' : $first, $entry) : null;
        }

        if ($second === null && preg_match('/^under-(\d+)$/', $first, $match) && in_array((int) $match[1], self::PRICE_BANDS, true)) {
            return self::priceLanding((int) $match[1]);
        }

        $make = $facets['makes'][$first] ?? null;
        if ($make === null) {
            return null;
        }

        if ($second === null) {
            return self::landingFor('make', $make);
        }

        $model = $make['models'][$second] ?? null;

        return $model ? self::landingFor('model', $model + ['make' => $make['value'], 'makeSlug' => $first]) : null;
    }

    /** Narrow a vehicle query to a landing page's fixed filter. */
    public static function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when(isset($filters['make']), fn (Builder $q) => $q->where('make', $filters['make']))
            ->when(isset($filters['model']), fn (Builder $q) => $q->where('model', $filters['model']))
            ->when(isset($filters['body']), fn (Builder $q) => $q->where('body_type', $filters['body']))
            ->when(isset($filters['fuel']), fn (Builder $q) => $q->where('fuel_type', $filters['fuel']))
            ->when(isset($filters['priceMax']), fn (Builder $q) => $q->where('price_in_cents', '<=', $filters['priceMax'] * 100))
            ->when(isset($filters['near']), fn (Builder $q) => $q->whereIn($q->getModel()->qualifyColumn('id'), self::facets()['cities'][$filters['near']]['ids'] ?? []));
    }

    /**
     * The search link groups: only pages with enough cars, busiest first.
     * Each link has the full search phrase ("Used Honda Civic for Sale", for
     * the shop's link sections) and a short label ("Honda Civic", for the
     * home page's footer columns).
     *
     * @return array<string, list<array{text: string, short: string, url: string, count: int}>>  keyed models, makes, bodies, prices, fuels, cities
     */
    public static function linkGroups(): array
    {
        $facets = self::facets();
        $link = fn (Landing $landing, string $short, int $count) => ['text' => $landing->linkText(), 'short' => $short, 'url' => url($landing->path), 'count' => $count];
        $busiest = fn (array $entries) => collect($entries)->filter(fn ($entry) => $entry['count'] >= self::FOOTER_MIN)->sortByDesc('count');

        $models = collect($facets['makes'])->flatMap(fn ($make, $makeSlug) => collect($make['models'])
            ->map(fn ($model) => $model + ['make' => $make['value'], 'makeSlug' => $makeSlug]));

        return array_filter([
            'models' => $busiest($models->all())->map(fn ($e) => $link(self::landingFor('model', $e), $e['make'] . ' ' . $e['value'], $e['count']))->values()->all(),
            'makes'  => $busiest($facets['makes'])->map(fn ($e) => $link(self::landingFor('make', $e), $e['value'], $e['count']))->values()->all(),
            'bodies' => $busiest($facets['bodies'])->map(fn ($e) => $link(self::landingFor('body', $e), $e['value'], $e['count']))->values()->all(),
            'prices' => collect($facets['prices'])->filter(fn ($count) => $count >= self::FOOTER_MIN)->sortKeysDesc()
                ->map(fn ($count, $band) => $link(self::priceLanding((int) $band), 'Under $' . number_format((int) $band), $count))->values()->all(),
            'fuels'  => $busiest($facets['fuels'])->map(fn ($e) => $link(self::landingFor('fuel', $e), $e['value'], $e['count']))->values()->all(),
            'cities' => collect($facets['cities'])->filter(fn ($entry) => $entry['count'] >= self::FOOTER_MIN)->map(fn ($e) => $link(self::landingFor('city', $e), 'Used Cars in ' . $e['city'] . ', ' . $e['province'], $e['count']))->values()->all(),
        ]);
    }

    /**
     * Every landing page with at least one car, for the sitemap.
     *
     * @return list<string>
     */
    public static function sitemapPaths(): array
    {
        $facets = self::facets();
        $paths = [];

        foreach ($facets['makes'] as $makeSlug => $make) {
            $paths[] = self::landingFor('make', $make)->path;
            foreach ($make['models'] as $model) {
                $paths[] = self::landingFor('model', $model + ['make' => $make['value'], 'makeSlug' => $makeSlug])->path;
            }
        }
        foreach (['bodies' => 'body', 'fuels' => 'fuel', 'cities' => 'city'] as $key => $type) {
            foreach ($facets[$key] as $entry) {
                if ($entry['count'] < 1) {
                    continue;
                }
                $paths[] = self::landingFor($type, $entry)->path;
            }
        }
        foreach ($facets['prices'] as $band => $count) {
            if ($count > 0) {
                $paths[] = self::priceLanding((int) $band)->path;
            }
        }

        return $paths;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @param  array<string, mixed>  $entry */
    private static function landingFor(string $type, array $entry): Landing
    {
        return match ($type) {
            'make'  => new Landing('make', $entry['value'], ['make' => $entry['value']], self::PREFIX . '/' . $entry['slug']),
            'model' => new Landing('model', $entry['make'] . ' ' . $entry['value'], ['make' => $entry['make'], 'model' => $entry['value']], self::PREFIX . '/' . $entry['makeSlug'] . '/' . $entry['slug']),
            'body'  => new Landing('body', Landing::plural($entry['value']), ['body' => $entry['value']], self::PREFIX . '/body/' . $entry['slug']),
            'fuel'  => new Landing('fuel', $entry['value'] . ' Cars', ['fuel' => $entry['value']], self::PREFIX . '/fuel/' . $entry['slug']),
            'city'  => new Landing('city', 'Cars', ['city' => $entry['city'], 'province' => $entry['province'], 'near' => $entry['slug']], self::PREFIX . '/in/' . $entry['slug'], $entry['city'] . ', ' . $entry['province']),
        };
    }

    /**
     * Every market city and dealer town, with the listed cars within NEAR_KM
     * of it. A car whose store has no coordinates counts only for its own town.
     *
     * @param  Collection<int, Vehicle>  $cars
     * @return array<string, array{city: string, province: string, slug: string, count: int, ids: list<int>}>
     */
    private static function citiesNear(Collection $cars): array
    {
        $centres = [];
        foreach (self::MARKET_CITIES as [$city, $province, $latitude, $longitude]) {
            $centres[Str::slug($city . ' ' . $province)] = compact('city', 'province', 'latitude', 'longitude');
        }

        $places = $cars->mapWithKeys(fn (Vehicle $car) => [$car->id => $car->locatedAt()]);
        foreach ($places as $place) {
            if ($place === null || blank($place->city) || blank($place->province)) {
                continue;
            }
            $city = mb_convert_case(mb_strtolower(trim($place->city)), MB_CASE_TITLE);
            $centres[Str::slug($city . ' ' . $place->province)] ??= [
                'city' => $city, 'province' => $place->province,
                'latitude' => $place->hasCoordinates() ? $place->latitude : null,
                'longitude' => $place->hasCoordinates() ? $place->longitude : null,
            ];
        }

        $cities = [];
        foreach ($centres as $slug => $centre) {
            $ids = $places->filter(function ($place) use ($centre) {
                if ($place === null) {
                    return false;
                }
                $km = $centre['latitude'] === null ? null : \App\Support\ShopperLocation::distanceKm($centre, $place);

                return $km !== null
                    ? $km <= self::NEAR_KM
                    : strcasecmp(trim((string) $place->city), $centre['city']) === 0 && $place->province === $centre['province'];
            })->keys()->all();

            $cities[$slug] = ['city' => $centre['city'], 'province' => $centre['province'], 'slug' => $slug, 'count' => count($ids), 'ids' => $ids];
        }

        return $cities;
    }

    private static function priceLanding(int $band): Landing
    {
        return new Landing('price', 'Cars Under $' . number_format($band), ['priceMax' => $band], self::PREFIX . '/under-' . $band);
    }

    /**
     * What's in stock, counted every way a landing page slices it.
     *
     * @return array{makes: array, bodies: array, fuels: array, cities: array, prices: array<int, int>}
     */
    private static function facets(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $cars = Vehicle::query()->listed()->with(['rooftop', 'dealer'])
                ->get(['id', 'dealer_id', 'rooftop_id', 'make', 'model', 'body_type', 'fuel_type', 'price_in_cents']);

            $group = function (Collection $values): array {
                return $values->filter(fn ($value) => filled($value) && Str::slug((string) $value) !== '')
                    ->groupBy(fn ($value) => Str::slug((string) $value))
                    ->map(fn (Collection $same, string $slug) => ['value' => (string) $same->first(), 'slug' => $slug, 'count' => $same->count()])
                    ->all();
            };

            $makes = [];
            foreach ($cars->filter(fn ($car) => filled($car->make))->groupBy(fn ($car) => Str::slug($car->make)) as $slug => $ofMake) {
                if ($slug === '' || in_array($slug, ['body', 'fuel', 'in'], true) || str_starts_with($slug, 'under-')) {
                    continue;
                }
                $makes[$slug] = ['value' => $ofMake->first()->make, 'slug' => $slug, 'count' => $ofMake->count(), 'models' => $group($ofMake->pluck('model'))];
            }

            $cities = self::citiesNear($cars);

            $prices = [];
            foreach (self::PRICE_BANDS as $band) {
                $prices[$band] = $cars->where('price_in_cents', '<=', $band * 100)->count();
            }

            return [
                'makes'  => $makes,
                'bodies' => $group($cars->pluck('body_type')),
                'fuels'  => $group($cars->pluck('fuel_type')),
                'cities' => $cities,
                'prices' => $prices,
            ];
        });
    }
}
