<?php

return [
    // The public brand in page titles, descriptions and structured data.
    'name' => env('SITE_NAME', 'Trumo'),

    // Google Tag Manager container, on shopper-facing pages in production only
    // (not the Command Centre or dealer portal). Set GTM_ID= (empty) to turn it off.
    'gtm_id' => env('GTM_ID', 'GTM-W6W8LT2D'),
];
