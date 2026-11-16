@php($dealerName = ucwords(strtolower($invitation->dealer->name)))
<x-email::layout title="Your Trumo dealer portal invitation"
    :preheader="'Set your password to join the ' . $dealerName . ' dealer portal on Trumo.'"
    :link="$acceptUrl"
    reason="Sent because someone at your dealership invited you to Trumo.">
    <x-email::heading>You're invited, {{ $invitation->name }}</x-email::heading>
    <x-email::text>You've been invited to join the <strong>{{ $dealerName }}</strong> dealer portal on Trumo, where you'll manage reservations and inventory. Set your password to finish creating your account.</x-email::text>
    <x-email::button :url="$acceptUrl">Set up my account</x-email::button>
    <x-email::text small>This link works once and expires {{ $invitation->expires_at->format('F j, Y') }}. If you weren't expecting it, you can ignore this email. No account is created until you set a password.</x-email::text>
</x-email::layout>
