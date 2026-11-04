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
 * worth landing on, so nothing thin gets published. Towns are where the
 * cars are; the dealership itself is never named.
 */
class SeoCatalog
{
    public const PREFIX = '/used-cars';

    /** The price bands that get their own page. */
    public const PRICE_BANDS = [10000, 15000, 20000, 25000, 30000, 40000, 50000];

    /** Fewest cars before a page is linked from the footer. */
    public const FOOTER_MIN = 3;

    private const CACHE_KEY = 'seo:facets';

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
            ->when(isset($filters['city']), function (Builder $q) use ($filters) {
                $inTown = fn (Builder $place) => $place->whereRaw('lower(city) = ?', [mb_strtolower($filters['city'])])->where('province', $filters['province']);

                // A car is where its rooftop is, or its dealer's own address when it has none.
                $q->where(fn (Builder $where) => $where
                    ->whereHas('rooftop', $inTown)
                    ->orWhere(fn (Builder $noRooftop) => $noRooftop->whereNull('rooftop_id')->whereHas('dealer', $inTown)));
            });
    }

    /**
     * The footer's link groups: only pages with enough cars, busiest first.
     *
     * @return array<string, list<array{text: string, url: string, count: int}>>
     */
    public static function footerGroups(): array
    {
        $facets = self::facets();
        $link = fn (Landing $landing, int $count) => ['text' => $landing->linkText(), 'url' => url($landing->path), 'count' => $count];
        $busiest = fn (array $entries) => collect($entries)->filter(fn ($entry) => $entry['count'] >= self::FOOTER_MIN)->sortByDesc('count');

        $models = collect($facets['makes'])->flatMap(fn ($make, $makeSlug) => collect($make['models'])
            ->map(fn ($model) => $model + ['make' => $make['value'], 'makeSlug' => $makeSlug]));

        return array_filter([
            'Popular makes'      => $busiest($facets['makes'])->map(fn ($entry) => $link(self::landingFor('make', $entry), $entry['count']))->values()->all(),
            'Popular models'     => $busiest($models->all())->map(fn ($entry) => $link(self::landingFor('model', $entry), $entry['count']))->values()->all(),
            'Shop by body style' => $busiest($facets['bodies'])->map(fn ($entry) => $link(self::landingFor('body', $entry), $entry['count']))->values()->all(),
            'Shop by price'      => collect($facets['prices'])->filter(fn ($count) => $count >= self::FOOTER_MIN)->sortKeysDesc()
                ->map(fn ($count, $band) => $link(self::priceLanding((int) $band), $count))->values()->all(),
            'Shop by fuel type'  => $busiest($facets['fuels'])->map(fn ($entry) => $link(self::landingFor('fuel', $entry), $entry['count']))->values()->all(),
            'Popular locations'  => $busiest($facets['cities'])->map(fn ($entry) => $link(self::landingFor('city', $entry), $entry['count']))->values()->all(),
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
            'city'  => new Landing('city', 'Cars', ['city' => $entry['city'], 'province' => $entry['province']], self::PREFIX . '/in/' . $entry['slug'], $entry['city'] . ', ' . $entry['province']),
        };
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

            $cities = [];
            foreach ($cars as $car) {
                $place = $car->locatedAt();
                if ($place === null || blank($place->city) || blank($place->province)) {
                    continue;
                }
                $city = mb_convert_case(mb_strtolower(trim($place->city)), MB_CASE_TITLE);
                $slug = Str::slug($city . ' ' . $place->province);
                $cities[$slug] ??= ['city' => $city, 'province' => $place->province, 'slug' => $slug, 'count' => 0];
                $cities[$slug]['count']++;
            }

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
