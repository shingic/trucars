{{-- A checklist of documents for a buyer email. Takes $documents (DealDocument rows). --}}
@php($font = "font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;")
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F4F2; border-radius:12px;">
    @foreach ($documents as $document)
        <tr>
            <td style="padding:{{ $loop->first ? '16px' : '10px' }} 20px {{ $loop->last ? '16px' : '0' }};">
                <p style="margin:0; {{ $font }} font-size:15px; font-weight:700; color:#16181D;">{{ $document->name }}</p>
                <p style="margin:2px 0 0; {{ $font }} font-size:13px; line-height:1.5; color:#5B6068;">{{ $document->status }}</p>
            </td>
        </tr>
    @endforeach
</table>
<p style="margin:12px 0 0; {{ $font }} font-size:13px; line-height:1.55; color:#9AA0A6;">
    A PDF or a clear phone photo works. Files go straight to {{ $deal->seller_name }} and are kept private.
</p>
