<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @include('partials.favicons')
    <title>Unsubscribed · {{ config('site.name') }}</title>
    <style>
        body { margin:0; background:#F9F9F9; font-family:"Barlow",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; color:#071437; }
        main { max-width:520px; margin:12vh auto 0; padding:0 16px; }
        .card { background:#fff; border:1px solid #D5D7DD; border-top:6px solid #1B84FF; border-radius:12px; padding:30px 28px; }
        img { display:block; height:30px; width:auto; margin-bottom:22px; }
        h1 { font-family:"Barlow Semi Condensed","Barlow",sans-serif; font-size:28px; text-transform:uppercase; margin:0 0 10px; }
        p { font-size:15.5px; line-height:1.6; color:#4B5675; margin:0 0 14px; }
        a { color:#056EE9; font-weight:700; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <img src="{{ asset('images/trumo-logo.svg') }}" alt="{{ config('site.name') }}">
        <h1>You're unsubscribed</h1>
        <p>We won't send you any more marketing emails. You'll still get emails about a reservation you make, like your receipt and pickup details.</p>
        <p><a href="{{ route('shop') }}">Back to the cars</a></p>
    </div>
</main>
</body>
</html>
