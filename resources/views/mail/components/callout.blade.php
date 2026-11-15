{{-- Tinted box with a blue bar: "<b>lead:</b> text". --}}
@props(['lead' => null])
@use('App\Support\Email\Theme', 'T')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 20px;">
    <tr>
        <td style="background-color:{{ T::PAGE }}; border-left:3px solid {{ T::ACCENT }}; padding:18px 22px; {!! T::FONT !!} font-size:15px; line-height:1.65; color:{{ T::TEXT }};">
            @if ($lead)<strong style="color:{{ T::INK }};">{{ $lead }}</strong> @endif{{ $slot }}
        </td>
    </tr>
</table>
