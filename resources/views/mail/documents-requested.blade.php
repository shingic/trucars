<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Documents to finish your reservation</title>
</head>
<body style="margin:0; padding:0; background-color:#F4F4F2; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F4F4F2; opacity:0;">
    Your dealer is ready for your paperwork. Upload {{ $outstandingDocuments->count() }} {{ $outstandingDocuments->count() === 1 ? 'document' : 'documents' }} in My Garage to keep your reservation moving.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F4F2;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(22,24,29,0.08);">

                {{-- Header band --}}
                <tr>
                    <td style="background-color:#F5631F; padding:26px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:20px; font-weight:800; letter-spacing:-0.02em; color:#FFFFFF;">
                                    TruCars
                                </td>
                                <td align="right" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#FFE2D2;">
                                    Documents
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Hero --}}
                <tr>
                    <td style="padding:38px 36px 8px;">
                        <h1 style="margin:0 0 10px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:26px; line-height:1.2; font-weight:800; letter-spacing:-0.025em; color:#16181D;">
                            A few documents, {{ $firstName }}.
                        </h1>
                        <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#5B6068;">
                            Your dealer is ready to move your {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }} forward. Upload these in My Garage and they'll take it from there.
                        </p>
                    </td>
                </tr>

                {{-- Outstanding documents --}}
                <tr>
                    <td style="padding:24px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #ECECEA; border-radius:12px;">
                            @foreach ($outstandingDocuments as $document)
                                <tr>
                                    <td style="padding:15px 22px; {{ $loop->last ? '' : 'border-bottom:1px solid #F1F1EF;' }} font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:600; color:#16181D;">
                                        {{ $document->name }}
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:28px 36px 6px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" bgcolor="#F5631F" style="border-radius:10px;">
                                    <a href="{{ route('garage') }}" target="_blank" style="display:inline-block; padding:14px 30px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700; color:#FFFFFF; text-decoration:none; border-radius:10px;">
                                        Upload in My Garage →
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:28px 36px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="border-top:1px solid #ECECEA; padding-top:20px;">
                                    <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; line-height:1.6; color:#9AA0A6;">
                                        Your documents go straight to the dealership handling your purchase — they own the financing, the verification and the delivery. TruCars just keeps your reservation organized.
                                    </p>
                                    <p style="margin:10px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; line-height:1.6; color:#C2C6CB;">
                                        Sent because your reservation reached the documents stage.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
