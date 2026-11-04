{{--
    Search links at the foot of every public page: makes, models, body styles,
    price bands, fuel types and towns that have enough cars in stock. They give
    search engines a path to every landing page (App\Support\Seo\SeoCatalog).
--}}
@php $groups = \App\Support\Seo\SeoCatalog::footerGroups(); @endphp
@if ($groups)
    <style>
        .seo-links { border-bottom:1px solid rgba(255,255,255,.14); }
        .seo-links-inner { max-width:1600px; margin:0 auto; padding:34px 24px 26px; display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:28px 40px; }
        .seo-links-inner.is-narrow { max-width:1288px; }
        .seo-group h2 { font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#fff; margin:0 0 10px; }
        .seo-group ul { list-style:none; margin:0; padding:0; display:grid; gap:7px; }
        .seo-group a { font-size:14px; color:rgba(255,255,255,.72); text-decoration:none; }
        .seo-group a:hover { color:#fff; text-decoration:underline; text-underline-offset:3px; }
        .seo-more { margin-top:9px; font-size:13.5px; font-weight:700; color:var(--primary-bright); background:none; border:none; padding:0; cursor:pointer; }
        .seo-more:hover { text-decoration:underline; text-underline-offset:3px; }
        @media (max-width:860px) { .seo-links-inner { grid-template-columns:1fr 1fr; padding:26px 16px 20px; gap:24px 20px; } }
        @media (max-width:520px) { .seo-links-inner { grid-template-columns:1fr; } }
    </style>
    <div class="seo-links">
        <div class="seo-links-inner {{ ($narrowShell ?? false) ? 'is-narrow' : '' }}">
            @foreach ($groups as $heading => $links)
                <div class="seo-group" x-data="{ more: false }">
                    <h2>{{ $heading }}</h2>
                    <ul>
                        @foreach ($links as $index => $link)
                            <li @if ($index >= 8) x-show="more" x-cloak @endif><a href="{{ $link['url'] }}" wire:navigate>{{ $link['text'] }}</a></li>
                        @endforeach
                    </ul>
                    @if (count($links) > 8)
                        <button type="button" class="seo-more" @click="more = ! more" x-text="more ? 'Show less' : 'Show more'">Show more</button>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
