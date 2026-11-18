<?php

return [
    // The public brand in page titles, descriptions and structured data.
    'name' => env('SITE_NAME', 'Trumo'),

    // Google Tag Manager container, on shopper-facing pages in production only
    // (not the Command Centre or dealer portal). Set GTM_ID= (empty) to turn it off.
    'gtm_id' => env('GTM_ID', 'GTM-W6W8LT2D'),

    // Where contact-form messages are emailed (they also land in Command Centre › Inquiries).
    'contact_email' => env('CONTACT_EMAIL', 'shingi@trueleads.ca'),

    // Careers page. Applications (with the CV attached) are emailed here; the
    // CV isn't kept on our server. Add open roles below; with none, the page
    // invites general applications. Each role:
    //   ['title' => 'Customer Success Lead', 'team' => 'Operations',
    //    'location' => 'Toronto, ON (hybrid)', 'type' => 'Full-time',
    //    'summary' => 'One or two sentences on the job.']
    'careers_email' => env('CAREERS_EMAIL', env('CONTACT_EMAIL', 'shingi@trueleads.ca')),
    'careers_roles' => [],

    // Tags on cars. "Just listed" for this many days after a car first goes
    // live; "Price dropped" for this many days after a cut of at least the
    // minimum. "Popular" shows once a car has this many visitors in a day;
    // leave HOT_VIEWS_THRESHOLD empty to keep it off (`php artisan vehicles:views` shows the numbers).
    'just_listed_days' => (int) env('JUST_LISTED_DAYS', 7),
    'price_drop_days' => (int) env('PRICE_DROP_DAYS', 14),
    'price_drop_min_cents' => 25000,
    'hot_views_threshold' => env('HOT_VIEWS_THRESHOLD') ? (int) env('HOT_VIEWS_THRESHOLD') : null,
];
