{{--
    One car as a row: optional thumbnail, name (linked), a detail line, price.
    The default slot adds a line under the details (e.g. "$2,000 less").
--}}
@props(['car', 'detail' => null, 'thumb' => true])
@use('App\Support\Email\Theme', 'T')
@php
    $href = url('/cars/' . $car->id);
    $name = trim("{$car->model_year} {$car->make} {$car->model}" . ($car->trim ? " {$car->trim}" : ''));
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        @if ($thumb && $car->primary_photo_url)
            <td width="112" valign="top" style="width:112px; padding:14px 14px 14px 0; border-bottom:1px solid {{ T::LINE }};">
                <a href="{{ $href }}" target="_blank"><img src="{{ $car->primary_photo_url }}" alt="" width="112" style="display:block; width:112px; height:auto; border:0; border-radius:4px;"></a>
            </td>
        @endif
        <td valign="top" style="padding:14px 0; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:14px; line-height:1.55; color:{{ T::MUTED }};">
            <a href="{{ $href }}" target="_blank" style="font-size:15px; font-weight:700; color:{{ T::INK }}; text-decoration:none;">{{ $name }}</a><br>
            {{ $detail ?? number_format((int) $car->kilometres) . ' km' . ($car->display_location ? ' · ' . $car->display_location : '') }}
            @if (trim($slot) !== '')<br>{{ $slot }}@endif
        </td>
        <td valign="top" align="right" style="padding:14px 0 14px 12px; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:16px; font-weight:700; line-height:1.4; color:{{ T::INK }}; white-space:nowrap;">{{ $car->display_price }}</td>
    </tr>
</table>
