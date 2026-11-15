{{-- A checklist of documents for a buyer email. Takes $documents (DealDocument rows) and $deal. --}}
@use('App\Support\Email\Theme', 'T')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 8px;">
    <tr>
        <td style="padding:0 0 10px; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:{{ T::TEXT }};">Still needed</td>
    </tr>
    @foreach ($documents as $document)
        <tr>
            <td style="padding:13px 0; border-bottom:1px solid {{ T::LINE }}; {!! T::FONT !!} font-size:15px; line-height:1.5;">
                <span style="font-weight:700; color:{{ T::INK }};">{{ $document->name }}</span><br>
                <span style="font-size:14px; color:{{ T::MUTED }};">{{ $document->status }}</span>
            </td>
        </tr>
    @endforeach
</table>
<x-email::text small>A PDF or a clear phone photo works. Files go straight to {{ $deal->seller_name }} and are kept private.</x-email::text>
