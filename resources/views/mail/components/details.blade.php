{{-- Label/value rows under a small uppercase caption. rows: [label => value], nulls skipped. --}}
@props(['label' => null, 'rows' => []])
@use('App\Support\Email\Theme', 'T')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 24px;">
    @if ($label)
        <tr>
            <td colspan="2" style="padding:0 0 10px; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:{{ T::TEXT }};">{{ $label }}</td>
        </tr>
    @endif
    @foreach (array_filter($rows, fn ($value) => $value !== null && $value !== '') as $rowLabel => $value)
        <tr>
            <td valign="top" class="mail-stack" style="padding:13px 12px 13px 0; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:15px; line-height:1.5; color:{{ T::MUTED }};">{{ $rowLabel }}</td>
            <td valign="top" align="right" class="mail-stack" style="padding:13px 0 13px 12px; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:15px; line-height:1.5; color:{{ T::INK }};">{{ $value }}</td>
        </tr>
    @endforeach
    {{ $slot }}
</table>
