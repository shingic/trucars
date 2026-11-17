<x-email::layout title="New contact form message"
    :preheader="$lead->name . ': ' . \Illuminate\Support\Str::limit((string) $lead->message, 90)"
    :link="route('admin.inquiries')"
    reason="Sent because someone used the contact form on Trumo.">
    <x-email::heading>{{ \App\Models\Lead::TOPICS[$lead->topic] ?? 'General' }}</x-email::heading>
    <x-email::details label="From" :rows="[
        'Name'  => $lead->name,
        'Email' => $lead->email,
        'Phone' => $lead->phone,
    ]" />
    <x-email::section>Message</x-email::section>
    <x-email::text>{!! nl2br(e($lead->message)) !!}</x-email::text>
    <x-email::text small>Reply to this email to answer them directly. It's also in Command Centre › Inquiries.</x-email::text>
</x-email::layout>
