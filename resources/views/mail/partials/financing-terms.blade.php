{{-- The approved financing terms as a two-column table. Takes $deal. --}}
@php($font = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;")
@php($rows = array_filter([
    'Lender'          => $deal->financing_lender,
    'Payment'         => '$' . number_format($deal->financing_payment_in_cents / 100, 2) . ' ' . strtolower($deal->financing_frequency_label),
    'Rate'            => rtrim(rtrim(number_format((float) $deal->financing_apr, 2), '0'), '.') . '% APR',
    'Term'            => $deal->financing_term_months . ' months' . ($deal->financing_payment_count ? " ({$deal->financing_payment_count} payments)" : ''),
    'Amount financed' => '$' . number_format($deal->financing_amount_in_cents / 100, 2),
    'Down payment'    => $deal->financing_down_payment_in_cents !== null ? '$' . number_format($deal->financing_down_payment_in_cents / 100, 2) : null,
    'First payment'   => $deal->financing_first_payment_on?->format('F j, Y'),
    'Conditions'      => $deal->financing_conditions,
]))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FFFFFF; border:1px solid #D5D7DD; border-top:2px solid #071437;">
    @foreach ($rows as $label => $value)
        <tr>
            <td style="padding:{{ $loop->first ? '16px' : '9px' }} 20px {{ $loop->last ? '16px' : '0' }}; {{ $font }} font-size:14px; color:#4B5675;">{{ $label }}</td>
            <td align="right" style="padding:{{ $loop->first ? '16px' : '9px' }} 20px {{ $loop->last ? '16px' : '0' }}; {{ $font }} font-size:14px; font-weight:700; color:#071437;">{{ $value }}</td>
        </tr>
    @endforeach
</table>
