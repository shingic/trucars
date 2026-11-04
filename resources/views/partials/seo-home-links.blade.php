{{--
    The home page's footer link columns: short labels to the busiest search
    landing pages (App\Support\Seo\SeoCatalog).
--}}
@php
    $groups = \App\Support\Seo\SeoCatalog::linkGroups();
    $columns = array_filter([
        'Browse Popular Models' => $groups['models'] ?? null,
        'Browse by Make'        => $groups['makes'] ?? null,
        'Browse by Body Style'  => $groups['bodies'] ?? null,
        'Browse by Location'    => $groups['cities'] ?? null,
    ]);
@endphp
@if ($columns)
    <div class="seo-home">
        <div class="seo-home-inner">
            @foreach ($columns as $title => $links)
                <div class="seo-col" x-data="{ more: false }">
                    <h2>{{ $title }}</h2>
                    <ul>
                        @foreach ($links as $index => $link)
                            <li @if ($index >= 10) x-show="more" x-cloak @endif><a href="{{ $link['url'] }}" wire:navigate>{{ $link['short'] }}</a></li>
                        @endforeach
                    </ul>
                    @if (count($links) > 10)
                        <button type="button" class="seo-col-more" @click="more = ! more" x-text="more ? 'View less' : 'View more'">View more</button>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    <style>
        .seo-home { background:var(--bg-2); border-top:1px solid var(--line); }
        .seo-home-inner { max-width:1600px; margin:0 auto; padding:44px 24px 40px; display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:28px 40px; }
        .seo-col h2 { font-family:var(--font-display); font-size:17px; font-weight:700; color:var(--ink); margin:0 0 16px; }
        .seo-col ul { list-style:none; margin:0; padding:0; display:grid; gap:11px; }
        .seo-col a { font-size:15px; color:var(--ink-2); text-decoration:none; }
        .seo-col a:hover { color:var(--ink); text-decoration:underline; text-underline-offset:3px; }
        .seo-col-more { margin-top:14px; font-size:14.5px; font-weight:700; color:var(--ink); background:none; border:none; padding:0; cursor:pointer; }
        .seo-col a:focus-visible, .seo-col-more:focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
        @media (max-width:860px) { .seo-home-inner { grid-template-columns:1fr 1fr; padding:32px 16px; } }
        @media (max-width:480px) { .seo-home-inner { grid-template-columns:1fr; } }
    </style>
@endif
