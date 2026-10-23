<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\Scopes\DealerScope;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * One dealer's inventory: list, publish/unpublish, and feed import. Shared by
 * the Command Centre page and the dealer portal page so both behave the same.
 *
 * The host component decides who may do what:
 *   - inventoryDealerId(): which dealer — fixed server-side, never from input
 *   - authorizeInventoryView() / authorizeInventoryManage(): the access checks,
 *     run before every read and every action
 *
 * Every lookup is fenced to that dealer's id, every publish goes through the
 * model (so the dealer-address guard runs), and every change is audited.
 */
trait ManagesDealerInventory
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';
    public string $show = 'all';

    /** @var array<int, string> vehicle ids ticked for a bulk action */
    public array $selected = [];

    public $feed = null;

    /** Unpublish cars that aren't in the uploaded feed any more (sold / removed). */
    public bool $unpublishMissing = true;

    public string $statusMessage = '';
    public string $errorMessage = '';
    public string $importOutput = '';

    abstract protected function inventoryDealerId(): int;

    abstract protected function authorizeInventoryView(): void;

    abstract protected function authorizeInventoryManage(): void;

    abstract public function canManageInventory(): bool;

    public function inventoryDealer(): Dealer
    {
        return Dealer::findOrFail($this->inventoryDealerId());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    /** The stores this list is limited to, or null for every store of the dealer. */
    protected function inventoryRooftopIds(): ?array
    {
        return null;
    }

    private function inventoryQuery(): Builder
    {
        return Vehicle::query()
            ->where('dealer_id', $this->inventoryDealerId())
            ->when($this->inventoryRooftopIds() !== null, fn (Builder $cars) => $cars->whereIn('rooftop_id', $this->inventoryRooftopIds()));
    }

    public function publish(int $vehicleId): void
    {
        $this->authorizeInventoryManage();
        $this->setPublished($this->inventoryQuery()->whereKey($vehicleId)->get(), true);
    }

    public function unpublish(int $vehicleId): void
    {
        $this->authorizeInventoryManage();
        $this->setPublished($this->inventoryQuery()->whereKey($vehicleId)->get(), false);
    }

    public function publishSelected(): void
    {
        $this->authorizeInventoryManage();
        $this->setPublished($this->inventoryQuery()->whereKey(array_map('intval', $this->selected))->get(), true);
        $this->selected = [];
    }

    public function unpublishSelected(): void
    {
        $this->authorizeInventoryManage();
        $this->setPublished($this->inventoryQuery()->whereKey(array_map('intval', $this->selected))->get(), false);
        $this->selected = [];
    }

    /**
     * Save through the model so the publish guard runs for each car. Stops at
     * the first refusal (it's always the dealer's address, so the rest would
     * fail the same way) and reports it.
     */
    private function setPublished($vehicles, bool $published): void
    {
        $this->reset(['statusMessage', 'errorMessage']);
        $changedIds = [];

        foreach ($vehicles as $vehicle) {
            if ($vehicle->is_published === $published) {
                continue;
            }

            try {
                $vehicle->update(['is_published' => $published]);
                $changedIds[] = $vehicle->id;
            } catch (ValidationException $exception) {
                $this->errorMessage = $exception->errors()['is_published'][0] ?? 'This vehicle can’t be published.';
                break;
            }
        }

        if ($changedIds === []) {
            return;
        }

        AuditLog::record($published ? 'inventory.published' : 'inventory.unpublished', $this->inventoryDealer(), null, [
            'vehicle_ids' => $changedIds,
            'count'       => count($changedIds),
        ]);

        $this->statusMessage = ($published ? 'Published ' : 'Unpublished ') . count($changedIds) . ' ' . (count($changedIds) === 1 ? 'vehicle' : 'vehicles') . '.';
    }

    /**
     * Run the feed through the same importer as the console command, pinned to
     * this dealer. The importer matches on the feed's rooftop id and refuses a
     * file that belongs to another dealer.
     */
    public function importFeed(): void
    {
        $this->authorizeInventoryManage();
        $this->reset(['statusMessage', 'errorMessage', 'importOutput']);

        $this->validate(['feed' => ['required', 'file', 'mimes:csv,txt', 'max:20480']], attributes: ['feed' => 'feed file']);

        $exitCode = Artisan::call('vehicles:import', array_filter([
            'path'           => $this->feed->getRealPath(),
            '--dealer'       => $this->inventoryDealerId(),
            '--keep-missing' => ! $this->unpublishMissing,
        ]));

        $this->importOutput = trim(Artisan::output());
        $this->feed = null;

        AuditLog::record('inventory.imported', $this->inventoryDealer(), null, [
            'succeeded'         => $exitCode === 0,
            'unpublish_missing' => $this->unpublishMissing,
            'summary'           => mb_substr($this->importOutput, 0, 1000),
        ]);

        if ($exitCode === 0) {
            $this->statusMessage = 'Feed imported. New cars start unpublished — review and publish them below.';
        } else {
            $this->errorMessage = 'The feed wasn’t imported. See the details below.';
        }

        $this->resetPage();
    }

    /** View data for partials.inventory-manager. */
    protected function inventoryViewData(): array
    {
        $this->authorizeInventoryView();

        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($this->search)) . '%';
        $heldByDeal = fn ($deals) => $deals->withoutGlobalScope(DealerScope::class)->where('stage', '!=', 'cancelled');

        $base = $this->inventoryQuery();

        return [
            'inventoryDealer' => $this->inventoryDealer(),
            'canManage'       => $this->canManageInventory(),
            'counts'          => [
                'all'         => (clone $base)->count(),
                'published'   => (clone $base)->where('is_published', true)->count(),
                'unpublished' => (clone $base)->where('is_published', false)->count(),
                'reserved'    => (clone $base)->whereHas('deals', $heldByDeal)->count(),
            ],
            'vehicles' => $base
                ->withExists(['deals as is_reserved' => $heldByDeal])
                ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('vin', 'like', $term)
                    ->orWhere('stock_number', 'like', $term)
                    ->orWhere('make', 'like', $term)
                    ->orWhere('model', 'like', $term)))
                ->when($this->show === 'published', fn ($query) => $query->where('is_published', true))
                ->when($this->show === 'unpublished', fn ($query) => $query->where('is_published', false))
                ->when($this->show === 'reserved', fn ($query) => $query->whereHas('deals', $heldByDeal))
                ->orderByDesc('is_published')
                ->orderBy('make')
                ->orderBy('model')
                ->paginate(30),
        ];
    }
}
