@use('App\Support\Email\Theme', 'T')
<h1 class="mail-h1" style="margin:0 0 14px; {!! T::FONT !!} font-size:28px; line-height:1.2; font-weight:700; color:{{ T::INK }};">{{ $slot }}</h1>
