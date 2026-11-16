@if (app()->isProduction() && filled(config('site.gtm_id')))
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ urlencode(config('site.gtm_id')) }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif
