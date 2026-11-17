<x-email::layout title="Reset your password"
    preheader="Choose a new password for your Trumo account. The link works for 60 minutes."
    reason="Sent because someone asked to reset the password for this Trumo account.">
    <x-email::heading>Reset your password, {{ $firstName }}</x-email::heading>
    <x-email::text>Tap the button to choose a new password for your Trumo account.</x-email::text>
    <x-email::button :url="$resetUrl">Choose a new password</x-email::button>
    <x-email::text>The link works once, for {{ $expiresInMinutes }} minutes.</x-email::text>
    <x-email::text small>Didn't ask for this? You can ignore this email. Your password stays the same unless you use the link.</x-email::text>
</x-email::layout>
