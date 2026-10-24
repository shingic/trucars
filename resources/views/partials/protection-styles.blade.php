{{-- Styles for the protection product pages (dealer portal and Command Centre). --}}
<style>
    .pm-intro h1 { font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; margin:0 0 8px; }
    .pm-intro p { margin:0; max-width:720px; color:var(--ink-2); font-size:15px; }
    .pm-sec { margin-top:34px; border-top:2px solid var(--ink); }
    .pm-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 0 4px; }
    .pm-head h2 { margin:0; font-size:23px; font-weight:700; text-transform:uppercase; display:flex; align-items:center; gap:9px; }
    .pm-head h2 svg { color:var(--ink); }
    .pm-note { margin:0 0 10px; font-size:14px; color:var(--ink-3); }
    .pm-row { display:grid; grid-template-columns:minmax(0, 1fr) 170px auto; gap:18px; align-items:start; padding:14px 0; border-bottom:1px solid var(--line-strong); }
    .pm-row.is-hidden .pm-main, .pm-row.is-hidden .pm-price { opacity:.5; }
    .pm-name { font-size:15.5px; font-weight:700; display:flex; flex-wrap:wrap; align-items:center; gap:6px 8px; }
    .pm-tag { font-size:12.5px; font-weight:600; padding:2px 7px; border-radius:5px; background:var(--bg-2); color:var(--ink-2); border:1px solid var(--line-strong); }
    .pm-tag.own { background:#E9F3FF; color:var(--primary-press); border-color:transparent; }
    .pm-tag.off { background:#FFF8DD; color:#7A4E00; border-color:transparent; }
    .pm-tag.star { background:var(--ink); color:#fff; border-color:transparent; }
    .pm-desc { font-size:14px; color:var(--ink-2); margin-top:3px; }
    .pm-feats { margin:8px 0 0; padding:0; list-style:none; display:flex; flex-wrap:wrap; gap:4px 14px; font-size:13.5px; color:var(--ink-2); }
    .pm-feats li::before { content:"✓ "; color:var(--good); font-weight:700; }
    .pm-price { font-family:var(--font-display); font-size:20px; font-weight:700; text-align:right; }
    .pm-price.none { font-family:var(--font-ui); font-size:14px; font-weight:500; color:var(--ink-3); }
    .pm-price small { display:block; font-family:var(--font-ui); font-size:12.5px; font-weight:500; color:var(--ink-3); }
    .pm-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
    .pm-btn { font-size:13.5px; font-weight:600; padding:6px 11px; border-radius:8px; border:1.5px solid var(--ink); background:#fff; color:var(--ink); white-space:nowrap; }
    .pm-btn:hover { background:var(--ink); color:#fff; }
    .pm-btn.primary { background:var(--primary); border-color:var(--primary); color:#fff; }
    .pm-btn.primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
    .pm-btn.danger { border-color:#D81A48; color:#D81A48; }
    .pm-btn.danger:hover { background:#D81A48; color:#fff; }
    .pm-btn:disabled { opacity:.4; cursor:default; }
    .pm-empty { padding:16px; margin:10px 0 0; border:1.5px dashed var(--line-strong); color:var(--ink-3); font-size:14px; }
    .pm-inline { display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:12px 14px; margin:8px 0; border-radius:10px; background:var(--bg-2); border:1px solid var(--line-strong); }
    .pm-inline em { flex-basis:100%; color:#D81A48; font-style:normal; font-size:13px; }

    .pf { margin:10px 0 4px; padding:18px; background:#fff; border:1.5px solid var(--ink); border-radius:8px; }
    .pf-title { font-family:var(--font-display); font-size:19px; font-weight:700; text-transform:uppercase; margin-bottom:12px; }
    .pf-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .pf-field { display:flex; flex-direction:column; gap:5px; font-size:14px; font-weight:600; }
    .pf-field small { font-weight:500; color:var(--ink-3); }
    .pf-field input, .pf-field select, .pf-field textarea { padding:9px 11px; font-size:15px; width:100%; }
    .pf-field em { color:#D81A48; font-style:normal; font-size:13px; font-weight:600; }
    .pf-wide { grid-column:1 / -1; }
    .pf-money { display:flex; align-items:center; gap:6px; } .pf-money b { color:var(--ink-3); font-weight:600; }
    .pf-check { display:flex; align-items:center; gap:8px; font-size:14.5px; font-weight:600; }
    .pf-actions { display:flex; gap:8px; margin-top:16px; }

    @media (max-width:760px) {
        .pm-row { grid-template-columns:minmax(0, 1fr); gap:8px; }
        .pm-price { text-align:left; }
        .pm-actions { justify-content:flex-start; }
        .pf-grid { grid-template-columns:minmax(0, 1fr); }
    }
</style>
