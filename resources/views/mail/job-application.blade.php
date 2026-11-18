<x-email::layout title="New job application"
    :preheader="$application['name'] . ' applied for ' . $application['role']"
    :link="route('careers')"
    reason="Sent because someone applied on the Trumo careers page.">
    <x-email::heading>{{ $application['role'] }}</x-email::heading>
    <x-email::details label="Applicant" :rows="[
        'Name'     => $application['name'],
        'Email'    => $application['email'],
        'Phone'    => $application['phone'],
        'LinkedIn / portfolio' => $application['link'],
        'CV'       => $resumeName ? 'Attached (' . $resumeName . ')' : 'None attached',
    ]" />
    <x-email::section>About them</x-email::section>
    <x-email::text>{!! nl2br(e($application['message'])) !!}</x-email::text>
    <x-email::text small>Reply to this email to answer them directly. The CV is only in this email; Trumo doesn't keep a copy.</x-email::text>
</x-email::layout>
