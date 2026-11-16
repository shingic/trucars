<x-email::layout title="Your Trumo Command Centre invitation"
    preheader="Set your password to join the Trumo Command Centre."
    :link="$acceptUrl"
    reason="Sent because a Trumo admin invited you to the Command Centre.">
    <x-email::heading>You're invited, {{ $invitation->name }}</x-email::heading>
    <x-email::text>You've been invited to join the <strong>Trumo Command Centre</strong>, Trumo's internal workspace. Set your password to finish creating your account.</x-email::text>
    <x-email::button :url="$acceptUrl">Set up my account</x-email::button>
    <x-email::text small>This link works once and expires {{ $invitation->expires_at->format('F j, Y') }}. If you weren't expecting it, you can ignore this email. No account is created until you set a password.</x-email::text>
</x-email::layout>
