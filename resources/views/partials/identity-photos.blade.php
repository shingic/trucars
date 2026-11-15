{{--
    The buyer's ID photos for staff (IdentityMediaController). Needs $deal and
    $mediaUrl: fn (string $part) => url. Photos only load once opened, and each
    view or download is audited.
--}}
@php
    $idCheck = $deal->identityVerification;
    $idPhotosReady = \App\Http\Controllers\IdentityMediaController::available($deal);
@endphp

@if ($idPhotosReady)
    <div class="idp" x-data="{ open: false }">
        <div class="idp-head">
            <b>ID photos</b>
            <button type="button" class="idp-btn" x-on:click="open = ! open" x-text="open ? 'Hide' : 'Show ID photos'">Show ID photos</button>
        </div>
        <template x-if="open">
            <div class="idp-grid">
                @foreach (['front' => 'Licence front', 'back' => 'Licence back', 'selfie' => 'Selfie'] as $part => $label)
                    <figure class="idp-fig">
                        <a href="{{ $mediaUrl($part) }}" target="_blank" rel="noopener">
                            <img src="{{ $mediaUrl($part) }}" alt="{{ $label }}" onerror="this.closest('figure').classList.add('idp-missing')">
                        </a>
                        <figcaption>
                            <span>{{ $label }}</span>
                            <a href="{{ $mediaUrl($part) }}?download=1">Download</a>
                        </figcaption>
                        <span class="idp-none">Not available</span>
                    </figure>
                @endforeach
            </div>
        </template>
        <a class="idp-report" href="{{ $mediaUrl('report') }}">Download full ID report (PDF)</a>
        <p class="idp-note">From Didit, for this deal only — e.g. to attach to the Dealertrack application. Every view and download is logged.</p>
    </div>
@elseif ($idCheck?->isDemo())
    <p class="idp-note">Demo ID check — no ID photos.</p>
@elseif ($idCheck && $deal->stage === 'cancelled')
    <p class="idp-note">ID photos are hidden on a cancelled reservation.</p>
@endif

@once
    <style>
        .idp { margin-top:14px; padding-top:12px; border-top:1px solid #E4E6EF; }
        .idp-head { display:flex; justify-content:space-between; align-items:center; gap:10px; }
        .idp-btn { border:1px solid #D0D5DD; background:#fff; border-radius:6px; padding:5px 10px; font-size:12.5px; font-weight:600; cursor:pointer; }
        .idp-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; margin-top:12px; }
        .idp-fig { margin:0; border:1px solid #E4E6EF; border-radius:8px; overflow:hidden; background:#F9F9FB; }
        .idp-fig img { display:block; width:100%; height:120px; object-fit:contain; background:#F1F1F4; }
        .idp-fig figcaption { display:flex; justify-content:space-between; padding:6px 8px; font-size:12px; }
        .idp-fig figcaption a { font-weight:600; }
        .idp-none { display:none; padding:40px 8px; text-align:center; font-size:12px; color:#78829D; }
        .idp-missing a, .idp-missing figcaption a { display:none; }
        .idp-missing .idp-none { display:block; }
        .idp-report { display:inline-block; margin-top:12px; font-size:13px; font-weight:600; }
        .idp-note { margin:8px 0 0; font-size:12px; color:#78829D; }
    </style>
@endonce
