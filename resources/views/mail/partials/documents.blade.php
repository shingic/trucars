{{-- A checklist of documents for a buyer email. Takes $documents (DealDocument rows). --}}
@php($font = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;")
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FFFFFF; border:1px solid #D5D7DD; border-top:2px solid #071437;">
    @foreach ($documents as $document)
        <tr>
            <td style="padding:{{ $loop->first ? '16px' : '10px' }} 20px {{ $loop->last ? '16px' : '0' }};">
                <p style="margin:0; {{ $font }} font-size:15px; font-weight:700; color:#071437;">{{ $document->name }}</p>
                <p style="margin:2px 0 0; {{ $font }} font-size:13px; line-height:1.5; color:#4B5675;">{{ $document->status }}</p>
            </td>
        </tr>
    @endforeach
</table>
<p style="margin:12px 0 0; {{ $font }} font-size:13px; line-height:1.55; color:#78829D;">
    A PDF or a clear phone photo works. Files go straight to {{ $deal->seller_name }} and are kept private.
</p>
