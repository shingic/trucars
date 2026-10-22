<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\DocumentsReminder;
use App\Models\Deal;
use Illuminate\Console\Command;

/**
 * One reminder per request: when a document the buyer was asked for is still
 * missing two days later, email them once. A new request (insurance at
 * finalization, a re-upload) earns one more reminder of its own.
 */
class RemindOutstandingDocuments extends Command
{
    public const WAIT_HOURS = 48;

    protected $signature = 'deals:remind-documents';

    protected $description = 'Email buyers once about documents still missing two days after they were asked for';

    public function handle(): int
    {
        $deals = Deal::withoutGlobalScopes()
            ->whereNotIn('stage', ['cancelled', 'delivered'])
            ->whereHas('documents', fn ($documents) => $documents->outstanding()
                ->where('requested_at', '<=', now()->subHours(self::WAIT_HOURS))
                ->where(fn ($unreminded) => $unreminded->whereNull('deals.documents_reminded_at')
                    ->orWhereColumn('deal_documents.requested_at', '>', 'deals.documents_reminded_at')))
            ->get();

        foreach ($deals as $deal) {
            $deal->forceFill(['documents_reminded_at' => now()])->save();
            $deal->emailBuyer(new DocumentsReminder($deal), 'Documents reminder');
        }

        $this->info('Document reminders sent: ' . $deals->count());

        return self::SUCCESS;
    }
}
