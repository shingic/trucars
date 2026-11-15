@props(['url'])
@use('App\Support\Email\Theme', 'T')
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;">
    <tr>
        <td align="center" bgcolor="{{ T::INK }}" style="border-radius:6px; background-color:{{ T::INK }};">
            <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:15px 28px; {!! T::FONT !!} font-size:16px; font-weight:600; line-height:1.2; color:#FFFFFF; text-decoration:none; border-radius:6px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
