@props(['src', 'alt' => '', 'href' => null])
@if ($src)
    <div style="margin:0 0 24px;">
        @if ($href)<a href="{{ $href }}" target="_blank">@endif
        <img src="{{ $src }}" alt="{{ $alt }}" width="518" style="display:block; width:100%; max-width:518px; height:auto; border:0; border-radius:6px;">
        @if ($href)</a>@endif
    </div>
@endif
