@use('App\Support\Email\Theme', 'T')
<x-email::layout title="Your verification code"
    :preheader="$code . ' is your Trumo verification code. It expires in ' . $expiresInMinutes . ' minutes. Don\'t share it with anyone.'"
    reason="Sent because someone entered this email to create a Trumo account.">
    <x-email::heading>Here's your code, {{ $firstName }}</x-email::heading>
    <x-email::text>Enter this code back on Trumo to confirm your email and finish setting up your account.</x-email::text>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 22px;">
        <tr>
            <td align="center" style="background-color:{{ T::PAGE }}; border-radius:6px; padding:22px 16px; {!! T::MONO !!} font-size:34px; font-weight:700; letter-spacing:0.3em; color:{{ T::INK }};">{{ $code }}</td>
        </tr>
    </table>
    <x-email::text>This code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. Trumo will never ask you to read it out.</x-email::text>
    <x-email::text small>Didn't try to create a Trumo account? You can safely ignore this email. Nothing happens without this code.</x-email::text>
</x-email::layout>
