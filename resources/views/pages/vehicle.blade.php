<?php

use App\Models\Vehicle;
use App\Support\CertificationProgram;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Vehicle $vehicle;

    #[Computed]
    public function certification(): ?CertificationProgram
    {
        return CertificationProgram::resolveFor($this->vehicle);
    }
};
?>

<div class="vdp">
    <a href="/" wire:navigate class="vdp-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        Back to results
    </a>

    <div class="vdp-grid">
        <div class="vdp-main">
            <div x-data="{ current: @js($vehicle->primary_photo_url), photos: @js($vehicle->photos ?? []) }">
                <div class="gallery-hero">
                    <template x-if="current"><img :src="current" class="gallery-img" alt="{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}"></template>
                    @if ($this->certification)
                        <span class="gallery-badge">
                            <span class="dot"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                            {{ $this->certification->shortName }}
                        </span>
                    @endif
                </div>
                <div class="gallery-thumbs-wrap" x-show="photos.length > 1">
                    <button type="button" class="gthumb-nav" x-on:click="$refs.thumbs.scrollBy({ left: -240, behavior: 'smooth' })" aria-label="Previous photos">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <div class="gallery-thumbs" x-ref="thumbs">
                        <template x-for="photo in photos" :key="photo">
                            <button type="button" class="gthumb" :class="{ on: photo === current }" x-on:click="current = photo">
                                <img :src="photo" alt="">
                            </button>
                        </template>
                    </div>
                    <button type="button" class="gthumb-nav" x-on:click="$refs.thumbs.scrollBy({ left: 240, behavior: 'smooth' })" aria-label="More photos">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            </div>

            <div class="vdp-titlerow">
                <h1 class="vdp-h1">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</h1>
                <p class="vdp-trim">{{ collect([$vehicle->trim, $vehicle->stock_number ? 'Stock #' . $vehicle->stock_number : null])->filter()->implode(' · ') }}</p>
                <div class="vdp-quickmeta">
                    <span class="qm"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg> <b>{{ number_format($vehicle->kilometres) }}</b> km</span>
                    @if ($vehicle->drivetrain)<span class="qm"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h18M5 13 7 6h10l2 7"/></svg> {{ $vehicle->drivetrain }}</span>@endif
                    @if ($vehicle->fuel_type)<span class="qm">{{ $vehicle->fuel_type }}</span>@endif
                    @if ($vehicle->transmission)<span class="qm">{{ $vehicle->transmission }}</span>@endif
                    @if ($vehicle->colour)<span class="qm">{{ $vehicle->colour }}</span>@endif
                </div>
            </div>

            @if ($this->certification)
                <div class="vdp-section" style="border-top:none; padding-top:4px;">
                    <div class="cert-block">
                        <div class="cert-head">
                            <div class="cert-shield"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/></svg></div>
                            <div>
                                <h3>{{ $this->certification->name }}, certified by {{ ucwords(strtolower($vehicle->dealer->name)) }}</h3>
                                <span class="prog">{{ $this->certification->inspectionPoints }}-point inspection</span>
                                <p>{{ $this->certification->tagline }} Inspected and certified by {{ ucwords(strtolower($vehicle->dealer->name)) }}'s technicians, with a powertrain warranty of {{ $this->certification->warrantyMonths }} months / {{ number_format($this->certification->warrantyKilometres) }} km from the contract date.</p>
                            </div>
                        </div>
                        <div class="cert-benefits">
                            @foreach ($this->certification->benefits as $benefit)
                                <div class="cben">
                                    <span class="ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="{{ $benefit['iconPath'] }}"/></svg></span>
                                    <div>
                                        <div class="t">{{ $benefit['title'] }}</div>
                                        <div class="s">{{ $benefit['detail'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="cert-foot">
                            <span class="cert-link"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1Z"/></svg> {{ $this->certification->inspectionPoints }}-point inspection report</span>
                            <span class="cert-link"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-6.4 2.6L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg> CARFAX history report</span>
                            <span class="note">Signed inspection &amp; CARFAX are attached to this exact VIN.</span>
                        </div>
                    </div>
                </div>
            @endif

            <div class="vdp-section">
                <h2>Vehicle details</h2>
                <div class="specs">
                    @if ($vehicle->body_type)<div class="spec"><span class="k">Body style</span><span class="v">{{ $vehicle->body_type }}</span></div>@endif
                    @if ($vehicle->drivetrain)<div class="spec"><span class="k">Drivetrain</span><span class="v">{{ $vehicle->drivetrain }}</span></div>@endif
                    @if ($vehicle->transmission)<div class="spec"><span class="k">Transmission</span><span class="v">{{ $vehicle->transmission }}</span></div>@endif
                    @if ($vehicle->fuel_type)<div class="spec"><span class="k">Fuel</span><span class="v">{{ $vehicle->fuel_type }}</span></div>@endif
                    @if ($vehicle->colour)<div class="spec"><span class="k">Exterior</span><span class="v">{{ $vehicle->colour }}</span></div>@endif
                    <div class="spec"><span class="k">Mileage</span><span class="v">{{ number_format($vehicle->kilometres) }} km</span></div>
                    <div class="spec"><span class="k">Condition</span><span class="v">{{ ucfirst(strtolower($vehicle->condition)) }}</span></div>
                    @if ($vehicle->stock_number)<div class="spec"><span class="k">Stock #</span><span class="v">{{ $vehicle->stock_number }}</span></div>@endif
                    <div class="spec"><span class="k">VIN</span><span class="v">{{ $vehicle->vin }}</span></div>
                </div>
            </div>

            <div class="vdp-section">
                <h2>Sold by</h2>
                <div class="dealer-card">
                    <div class="dealer-top">
                        <span class="dealer-logo">{{ strtoupper(substr($vehicle->dealer->name, 0, 1)) }}</span>
                        <div>
                            <div class="dealer-name">{{ ucwords(strtolower($vehicle->dealer->name)) }}</div>
                            <div class="dealer-sub">
                                {{ $vehicle->dealer->city }}
                                @if ($vehicle->dealer->omvic_number)
                                    <span class="dot-sep"></span> OMVIC&nbsp;#{{ $vehicle->dealer->omvic_number }}
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <aside class="buy-rail">
            <div class="buy-card">
                <div class="buy-top">
                    <div class="buy-pricelabel">TruCars price</div>
                    <div class="buy-price">{{ $vehicle->display_price }}</div>
                    <div class="price-disclosure">
                        <div class="pd-row">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h18M5 13 7 6h10l2 7M7 17h.01M17 17h.01"/></svg>
                            $149 delivery to your door
                        </div>
                        <div class="pd-note">All-in price — freight, PDI, admin &amp; OMVIC fee included. <b>Excludes HST &amp; licensing.</b></div>
                    </div>
                    <div class="buy-pay">
                        <div class="big">${{ number_format($vehicle->estimated_biweekly) }}<span class="per"> /biweekly</span></div>
                        <div class="sub">est. 72 mo · 7.5% APR · $0 down · before HST &amp; licensing</div>
                    </div>
                    @if ($this->certification)
                        <div class="buy-cert-mini">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/></svg>
                            <div>
                                <div class="t">{{ $this->certification->shortName }}</div>
                                <div class="s">{{ $this->certification->inspectionPoints }}-pt inspection · {{ $this->certification->warrantyMonths }}-mo warranty</div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="buy-actions">
                    <a href="{{ route('checkout', $vehicle) }}" wire:navigate class="btn btn-primary">
                        Reserve my car now
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                </div>

                <div class="buy-assure">
                    <div class="ba"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Fully refundable $150 deposit until delivery</div>
                    <div class="ba"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Home delivery or dealership pickup</div>
                    <div class="ba"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Trade-in &amp; financing built into checkout</div>
                </div>
            </div>
        </aside>
    </div>

    <!-- mobile-only sticky action bar: price + biweekly on top, full-width Reserve pinned to the bottom -->
    <div class="vdp-stickybar">
        <div class="sb-line">
            <span class="sb-amount">{{ $vehicle->display_price }}</span>
            <span class="sb-bw">or <b>${{ number_format($vehicle->estimated_biweekly) }}</b>/biweekly</span>
            <span class="sb-allin">All-in · +HST &amp; lic.</span>
        </div>
        <a href="{{ route('checkout', $vehicle) }}" wire:navigate class="btn btn-primary sb-cta">
            Reserve my car now
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </div>

    <style>
        [x-cloak] { display:none !important; }

        .vdp-back { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600; color:var(--ink-2); margin-bottom:18px; text-decoration:none; }
        .vdp-back:hover { color:var(--primary); }
        .vdp-grid { display:grid; grid-template-columns:1fr 400px; gap:48px; align-items:start; }
        .vdp-main { min-width:0; }

        .gallery-hero { height:440px; border-radius:var(--radius); background:var(--hero-grad); position:relative; overflow:hidden; }
        .gallery-img { width:100%; height:100%; object-fit:cover; display:block; }
        .gallery-badge { position:absolute; top:18px; left:18px; z-index:2; display:inline-flex; align-items:center; gap:7px; background:rgba(255,255,255,.96); border-radius:var(--radius-pill); padding:8px 15px 8px 10px; font-size:13px; font-weight:700; color:#0E5A43; box-shadow:var(--shadow-md); }
        .gallery-badge .dot { width:18px; height:18px; border-radius:50%; background:var(--good); display:grid; place-items:center; }

        .gallery-thumbs-wrap { display:flex; align-items:center; gap:8px; margin-top:12px; }
        .gthumb-nav { flex:0 0 auto; width:32px; height:66px; border-radius:10px; border:1px solid var(--line); background:var(--card); color:var(--ink-2); display:grid; place-items:center; cursor:pointer; }
        .gthumb-nav:hover { border-color:var(--primary); color:var(--primary); }
        .gallery-thumbs { display:flex; gap:10px; overflow-x:auto; scroll-behavior:smooth; scrollbar-width:thin; flex:1; padding-bottom:2px; }
        .gallery-thumbs::-webkit-scrollbar { height:6px; }
        .gallery-thumbs::-webkit-scrollbar-thumb { background:var(--line-strong); border-radius:3px; }
        .gthumb { flex:0 0 108px; height:66px; border-radius:12px; background:var(--bg-2); border:1.5px solid var(--line); cursor:pointer; overflow:hidden; padding:0; }
        .gthumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .gthumb.on { border-color:var(--primary); }

        .vdp-titlerow { margin-top:26px; padding-bottom:22px; border-bottom:1px solid var(--line); }
        .vdp-h1 { font-size:30px; font-weight:800; letter-spacing:-.025em; margin:0; }
        .vdp-trim { color:var(--ink-2); font-size:16px; margin:4px 0 0; }
        .vdp-quickmeta { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
        .qm { display:inline-flex; align-items:center; gap:7px; background:var(--bg-2); border-radius:10px; padding:9px 13px; font-size:13px; color:var(--ink); font-weight:500; }
        .qm svg { color:var(--ink-3); }
        .qm b { font-weight:700; }

        .vdp-section { padding:26px 0; border-bottom:1px solid var(--line); }
        .vdp-section h2 { font-size:20px; font-weight:800; letter-spacing:-.015em; margin:0 0 16px; }

        .cert-block { border:1.5px solid rgba(18,184,134,.35); border-radius:var(--radius); overflow:hidden; }
        .cert-head { background:linear-gradient(180deg, var(--good-soft), transparent); padding:22px 24px 20px; display:flex; align-items:flex-start; gap:16px; }
        .cert-shield { width:50px; height:50px; border-radius:14px; background:var(--good); display:grid; place-items:center; flex-shrink:0; box-shadow:0 10px 22px rgba(18,184,134,.32); }
        .cert-head h3 { margin:0; font-size:18px; font-weight:800; letter-spacing:-.01em; }
        .cert-head .prog { display:inline-block; font-size:11.5px; font-weight:700; color:#0E5A43; background:rgba(18,184,134,.16); padding:2px 9px; border-radius:var(--radius-pill); margin-top:4px; }
        .cert-head p { font-size:13.5px; color:var(--ink-2); margin:8px 0 0; line-height:1.55; }
        .cert-benefits { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--line); }
        .cben { background:var(--card); padding:16px 18px; display:flex; gap:12px; align-items:flex-start; }
        .cben .ic { width:30px; height:30px; border-radius:9px; background:var(--good-soft); color:var(--good); display:grid; place-items:center; flex-shrink:0; }
        .cben .t { font-size:13.5px; font-weight:700; }
        .cben .s { font-size:12px; color:var(--ink-2); margin-top:2px; line-height:1.45; }
        .cert-foot { padding:16px 22px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; background:var(--bg-2); }
        .cert-link { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:var(--ink); background:var(--card); border:1px solid var(--line-strong); border-radius:10px; padding:9px 14px; }
        .cert-link svg { color:var(--good); }
        .cert-foot .note { font-size:11.5px; color:var(--ink-3); margin-left:auto; max-width:230px; text-align:right; }

        .specs { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--line); border:1px solid var(--line); border-radius:14px; overflow:hidden; }
        .spec { background:var(--card); padding:13px 17px; display:flex; justify-content:space-between; gap:12px; font-size:13.5px; }
        .spec .k { color:var(--ink-2); }
        .spec .v { font-weight:600; text-align:right; }

        .dealer-card { border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; }
        .dealer-top { padding:20px 22px; display:flex; align-items:center; gap:15px; }
        .dealer-logo { width:52px; height:52px; border-radius:14px; background:var(--ink); color:#fff; display:grid; place-items:center; font-weight:800; font-size:18px; flex-shrink:0; }
        .dealer-name { font-size:17px; font-weight:800; letter-spacing:-.01em; }
        .dealer-sub { font-size:13px; color:var(--ink-2); margin-top:4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .dealer-sub .dot-sep { width:3px; height:3px; border-radius:50%; background:var(--ink-3); }

        .buy-rail { position:sticky; top:90px; }
        .buy-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); overflow:hidden; }
        .buy-top { padding:22px 22px 18px; }
        .buy-pricelabel { font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); }
        .buy-price { font-size:34px; font-weight:800; letter-spacing:-.03em; margin-top:2px; }
        .price-disclosure { margin-top:14px; }
        .pd-row { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--ink-2); font-weight:500; }
        .pd-row svg { color:var(--ink-3); flex-shrink:0; }
        .pd-note { margin-top:11px; padding-top:11px; border-top:1px solid var(--line); font-size:11.5px; color:var(--ink-3); line-height:1.5; }
        .pd-note b { color:var(--ink-2); font-weight:700; }
        .buy-pay { margin-top:16px; padding:14px 16px; background:var(--bg-2); border-radius:14px; }
        .buy-pay .big { font-size:20px; font-weight:800; letter-spacing:-.02em; }
        .buy-pay .big .per { font-size:13px; color:var(--ink-3); font-weight:500; }
        .buy-pay .sub { font-size:12px; color:var(--ink-2); margin-top:3px; }
        .buy-cert-mini { margin-top:14px; display:flex; align-items:center; gap:10px; background:var(--good-soft); border-radius:12px; padding:11px 13px; }
        .buy-cert-mini svg { color:var(--good); flex-shrink:0; }
        .buy-cert-mini .t { font-size:12.5px; font-weight:700; color:#0E5A43; }
        .buy-cert-mini .s { font-size:11px; color:#12805F; }
        .buy-actions { padding:0 22px 22px; }
        .btn { border-radius:var(--radius-pill); font-weight:700; font-size:15px; padding:15px 22px; display:inline-flex; align-items:center; justify-content:center; gap:9px; transition:all .16s ease; width:100%; cursor:pointer; text-decoration:none; }
        .btn-primary { background:var(--primary); color:#fff; box-shadow:var(--shadow-primary); border:none; }
        .btn-primary:hover { background:var(--primary-press); transform:translateY(-1px); }
        .buy-assure { padding:16px 22px; border-top:1px solid var(--line); display:flex; flex-direction:column; gap:11px; }
        .ba { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--ink-2); }
        .ba svg { color:var(--good); flex-shrink:0; }

        /* sticky bottom action bar — hidden on desktop, shown when the rail goes static */
        .vdp-stickybar {
            display:none;
            position:fixed; left:0; right:0; bottom:0; z-index:50;
            flex-direction:column; gap:10px;
            background:rgba(255,255,255,.97); backdrop-filter:saturate(160%) blur(14px);
            border-top:1px solid var(--line);
            box-shadow:0 -10px 34px rgba(22,24,29,.12);
            padding:12px 16px calc(12px + env(safe-area-inset-bottom));
        }
        .vdp-stickybar .sb-line { display:flex; align-items:baseline; flex-wrap:wrap; gap:5px 9px; }
        .vdp-stickybar .sb-amount { font-size:20px; font-weight:800; letter-spacing:-.025em; }
        .vdp-stickybar .sb-bw { font-size:13px; color:var(--ink-2); font-weight:500; }
        .vdp-stickybar .sb-bw b { color:var(--ink); font-weight:700; }
        .vdp-stickybar .sb-allin { margin-left:auto; font-size:11px; color:var(--ink-3); font-weight:600; white-space:nowrap; }
        .vdp-stickybar .sb-cta { width:100%; padding:15px 22px; font-size:15.5px; }

        @media (max-width:1000px) {
            .vdp-grid { grid-template-columns:1fr; gap:30px; }
            .buy-rail { position:static; }
            .gallery-hero { height:380px; }
            .vdp { padding-bottom:calc(124px + env(safe-area-inset-bottom)); }
            .vdp-stickybar { display:flex; }
        }

        @media (max-width:860px) {
            .vdp-back { margin-bottom:14px; }
            .gallery-hero { height:290px; border-radius:var(--radius-sm); }
            .gallery-badge { top:12px; left:12px; padding:6px 12px 6px 8px; font-size:12px; }
            .gthumb-nav { display:none; }
            .gthumb { flex-basis:90px; height:58px; }
            .vdp-titlerow { margin-top:20px; padding-bottom:18px; }
            .vdp-h1 { font-size:24px; }
            .vdp-trim { font-size:14.5px; }
            .vdp-section { padding:22px 0; }
            .vdp-section h2 { font-size:18px; }
            .cert-head { padding:18px 18px 16px; gap:13px; }
            .cert-shield { width:44px; height:44px; }
            .cert-head h3 { font-size:16px; }
            .cert-benefits { grid-template-columns:1fr; }
            .cert-foot { padding:14px 16px; }
            .cert-foot .note { margin-left:0; max-width:none; width:100%; text-align:left; }
            .specs { grid-template-columns:1fr; }
            .spec { padding:12px 15px; }
            .dealer-top { padding:16px 16px; gap:13px; }
        }
    </style>
</div>
