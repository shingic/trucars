<x-email::layout title="Inventory feeds not updating"
    :preheader="$stores->count() . ' ' . ($stores->count() === 1 ? 'store’s' : 'stores’') . ' inventory hasn’t changed in over ' . \App\Console\Commands\SyncDealerFeeds::STALE_HOURS . ' hours'"
    reason="Sent because a dealer inventory file from HomeNet stopped changing.">
    <x-email::heading>Some inventory feeds have stopped updating</x-email::heading>
    <x-email::text>These stores' files in HomeNet's upload folder haven't changed in over {{ \App\Console\Commands\SyncDealerFeeds::STALE_HOURS }} hours, so cars they've sold may still be listed on Trumo.</x-email::text>
    @foreach ($stores as $store)
        <x-email::details :label="$store->dealer?->name ?? $store->feed_file" :rows="[
            'File'         => $store->feed_file,
            'Last changed' => $store->feed_file_modified_at?->timezone('America/Vancouver')->format('M j, Y g:i a') ?? 'Never received',
            'Problem'      => $store->feed_error,
        ]" />
    @endforeach
    <x-email::text small>Check with HomeNet that the export is still running. <code>php artisan feeds:sync --status</code> shows every store's feed.</x-email::text>
</x-email::layout>
