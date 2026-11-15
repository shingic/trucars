{{-- A small uppercase caption above a block. --}}
@use('App\Support\Email\Theme', 'T')
<p style="margin:26px 0 10px; {!! T::FONT !!} font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:{{ T::TEXT }};">{{ $slot }}</p>
