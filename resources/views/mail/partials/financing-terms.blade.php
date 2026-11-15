{{-- The approved financing terms. Takes $deal. --}}
<x-email::details label="Your terms" :rows="[
    'Lender'          => $deal->financing_lender,
    'Payment'         => '$' . number_format($deal->financing_payment_in_cents / 100, 2) . ' ' . strtolower($deal->financing_frequency_label),
    'Rate'            => rtrim(rtrim(number_format((float) $deal->financing_apr, 2), '0'), '.') . '% APR',
    'Term'            => $deal->financing_term_months . ' months' . ($deal->financing_payment_count ? ' (' . $deal->financing_payment_count . ' payments)' : ''),
    'Amount financed' => '$' . number_format($deal->financing_amount_in_cents / 100, 2),
    'Down payment'    => $deal->financing_down_payment_in_cents !== null ? '$' . number_format($deal->financing_down_payment_in_cents / 100, 2) : null,
    'First payment'   => $deal->financing_first_payment_on?->format('F j, Y'),
    'Conditions'      => $deal->financing_conditions,
]" />
