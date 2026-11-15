{{--
    Footer for every marketing email (CASL): who it's from, the mailing
    address, why they got it, and unsubscribe links — this type only, or
    manage everything. Needs $user, $type (EmailTypes) and $reason.
--}}
@php
    $footerBrand = config('site.name');
    $footerAddress = \App\Support\PlatformSettings::get('business_mailing_address');
    $footerFont = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
@endphp
<tr>
    <td style="padding:28px 36px 34px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="border-top:2px solid #071437; padding-top:18px; {{ $footerFont }} font-size:12.5px; line-height:1.7; color:#78829D;">
                {{ $reason }}<br>
                {{ $footerBrand }}@if ($footerAddress !== '') · {{ $footerAddress }}@endif<br>
                To stop {{ strtolower(\App\Support\Marketing\EmailTypes::label($type)) }}, <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('marketing.unsubscribe.type', ['user' => $user->id, 'type' => $type]) }}" target="_blank" style="color:#4B5675; text-decoration:underline;">click here</a>.<br>
                Manage email preferences or unsubscribe from all <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('email.preferences', ['user' => $user->id]) }}" target="_blank" style="color:#4B5675; text-decoration:underline;">here</a>.
            </td>
        </tr></table>
    </td>
</tr>
