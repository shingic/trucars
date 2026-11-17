    <style>
        .auth-top { border-bottom:1px solid var(--line-strong); background:var(--card); }
        .auth-top-inner { max-width:1080px; margin:0 auto; padding:0 26px; display:flex; align-items:center; height:64px; }
        .auth-wrap { max-width:440px; margin:0 auto; padding:56px 26px 90px; }
        .auth-card { background:var(--card); border:1px solid var(--line-strong); border-top:3px solid var(--ink); border-radius:var(--radius); padding:30px 30px 32px; }
        .auth-h1 { font-family:var(--font-display); font-size:36px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0 0 10px; }
        .auth-lede { font-size:15px; color:var(--ink-2); margin:0 0 24px; line-height:1.55; }
        .auth-form .btn-primary { font-family:var(--font-display); text-transform:uppercase; font-size:18px; font-weight:600; border-radius:8px; }
        .auth-form { display:flex; flex-direction:column; gap:16px; }
        .auth-remember { display:flex; align-items:center; gap:9px; font-size:14px; color:var(--ink-2); font-weight:500; cursor:pointer; user-select:none; }
        .auth-remember input { width:17px; height:17px; margin:0; accent-color:var(--ink); border-radius:5px; }
        .auth-swap { text-align:center; font-size:14px; color:var(--ink-2); margin-top:18px; }
        .auth-label-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; }
        .auth-forgot { font-size:13.5px; font-weight:600; }
        .auth-inline-btn { background:none; padding:0; font:inherit; cursor:pointer; }
        .field-error { color:#D81A48; font-size:13px; font-weight:600; margin:7px 0 0; }
        @media (max-width:560px) { .auth-wrap { padding:32px 16px 70px; } .auth-top-inner { padding:0 16px; } .auth-card { padding:24px 18px; } }
    </style>
