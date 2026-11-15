{{-- A paragraph. small: the quieter 13px note style. --}}
@props(['small' => false, 'top' => 0])
@use('App\Support\Email\Theme', 'T')
<p style="margin:{{ $top }}px 0 14px; {!! T::FONT !!} font-size:{{ $small ? '13px' : '16px' }}; line-height:1.65; color:{{ $small ? T::MUTED : T::TEXT }};">{{ $slot }}</p>
