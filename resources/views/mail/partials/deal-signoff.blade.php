{{-- Closing line on a buyer's deal emails. Takes $deal. --}}
<x-email::text small top="4">{{ $deal->seller_name }} is handling your purchase and can answer any questions. Your reference is {{ $deal->reference }}.</x-email::text>
