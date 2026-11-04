<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Support\Seo\SeoCatalog;
use Illuminate\Http\Response;

/**
 * Every public page worth indexing: the home page, the shop, each search
 * landing page with cars in stock, and every listed car.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = collect([
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('shop'), 'priority' => '0.9'],
            ['loc' => route('how-it-works'), 'priority' => '0.5'],
        ])
            ->concat(collect(SeoCatalog::sitemapPaths())->map(fn (string $path) => ['loc' => url($path), 'priority' => '0.8']))
            ->concat(Vehicle::query()->listed()->latest('updated_at')->get(['id', 'updated_at'])
                ->map(fn (Vehicle $car) => ['loc' => url('/cars/' . $car->id), 'lastmod' => $car->updated_at?->toAtomString(), 'priority' => '0.7']));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . e($url['loc']) . '</loc>'
                . (empty($url['lastmod']) ? '' : '<lastmod>' . $url['lastmod'] . '</lastmod>')
                . '<priority>' . $url['priority'] . '</priority></url>' . "\n";
        }

        return response($xml . '</urlset>' . "\n", 200, ['Content-Type' => 'application/xml']);
    }
}
