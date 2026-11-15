{{-- Numbered list. items: strings. --}}
@props(['items' => []])
@use('App\Support\Email\Theme', 'T')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    @foreach (array_values(array_filter($items)) as $i => $item)
        <tr>
            <td width="30" valign="top" style="width:30px; padding:0 0 12px; {!! T::FONT !!} font-size:15px; font-weight:700; line-height:1.6; color:{{ T::ACCENT }};">{{ $i + 1 }}.</td>
            <td valign="top" style="padding:0 0 12px; {!! T::FONT !!} font-size:15px; line-height:1.6; color:{{ T::TEXT }};">{{ $item }}</td>
        </tr>
    @endforeach
</table>
