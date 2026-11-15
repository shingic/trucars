<x-email::layout title="Your TruCars Command Centre invitation"
    preheader="Set your password to join the TruCars Command Centre."
    :link="$acceptUrl"
    reason="Sent because a TruCars admin invited you to the Command Centre.">
    <x-email::heading>You're invited, {{ $invitation->name }}</x-email::heading>
    <x-email::text>You've been invited to join the <strong>TruCars Command Centre</strong>, TruCars' internal workspace. Set your password to finish creating your account.</x-email::text>
    <x-email::button :url="$acceptUrl">Set up my account</x-email::button>
    <x-email::text small>This link works once and expires {{ $invitation->expires_at->format('F j, Y') }}. If you weren't expecting it, you can ignore this email. No account is created until you set a password.</x-email::text>
</x-email::layout>
