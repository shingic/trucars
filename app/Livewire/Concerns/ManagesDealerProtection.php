<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\DealerProtectionSetting;
use App\Models\ProtectionProduct;

/**
 * One dealership's protection menu: which TruCars defaults its buyers see and
 * at what price, plus the items it added itself. Shared by the dealer portal
 * page and the Command Centre's dealer tab so both behave the same.
 *
 * The host component decides who may do what:
 *   - protectionDealerId(): which dealer — fixed server-side, never from input
 *   - authorizeProtectionManage(): the access check, run before every change
 *
 * A dealership can only ever touch its own rows: defaults are changed through
 * its own setting row, never edited, and its own items are looked up by its id.
 */
trait ManagesDealerProtection
{
    use EditsProtectionProduct;

    public ?int $pricingProductId = null;
    public $pricingAmount = '';

    abstract protected function protectionDealerId(): int;

    abstract protected function authorizeProtectionManage(): void;

    protected function protectionDealer(): Dealer
    {
        return Dealer::findOrFail($this->protectionDealerId());
    }

    /** Show or hide a TruCars default for this dealership's buyers. */
    public function toggleDefault(int $productId): void
    {
        $this->authorizeProtectionManage();
        $product = ProtectionProduct::defaults()->findOrFail($productId);

        $setting = $this->settingFor($product);
        $setting->is_enabled = ! $setting->is_enabled;
        $setting->save();

        AuditLog::record($setting->is_enabled ? 'protection.default_shown' : 'protection.default_hidden', $this->protectionDealer(), null, ['product' => $product->name]);
    }

    public function startPricing(int $productId): void
    {
        $product = ProtectionProduct::defaults()->findOrFail($productId);
        $cents = $this->settingRow($product)?->amount_in_cents ?? $product->amount_in_cents;

        $this->resetProductForm();
        $this->pricingProductId = $product->id;
        $this->pricingAmount = $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }

    public function cancelPricing(): void
    {
        $this->pricingProductId = null;
        $this->pricingAmount = '';
        $this->resetErrorBag();
    }

    /** Set this dealership's own price for a default. Blank goes back to the TruCars price. */
    public function savePricing(): void
    {
        $this->authorizeProtectionManage();
        $this->validate(['pricingAmount' => ['nullable', 'numeric', 'min:0', 'max:100000']], [], ['pricingAmount' => 'price']);
        $product = ProtectionProduct::defaults()->findOrFail($this->pricingProductId);

        $cents = $this->pricingAmount === '' || $this->pricingAmount === null ? null : (int) round((float) $this->pricingAmount * 100);
        $setting = $this->settingFor($product);
        $before = $setting->amount_in_cents;
        $setting->amount_in_cents = $cents === $product->amount_in_cents ? null : $cents;
        $setting->save();

        AuditLog::record('protection.price_set', $this->protectionDealer(), ['product' => $product->name, 'amount_in_cents' => $before], ['product' => $product->name, 'amount_in_cents' => $setting->amount_in_cents]);
        $this->cancelPricing();
    }

    public function resetPricing(int $productId): void
    {
        $this->authorizeProtectionManage();
        $product = ProtectionProduct::defaults()->findOrFail($productId);

        if ($setting = $this->settingRow($product)) {
            $setting->update(['amount_in_cents' => null]);
            AuditLog::record('protection.price_reset', $this->protectionDealer(), null, ['product' => $product->name]);
        }
    }

    public function startEditOwn(int $productId): void
    {
        $this->cancelPricing();
        $this->fillProductForm($this->ownProduct($productId));
    }

    public function saveOwnProduct(): void
    {
        $this->authorizeProtectionManage();
        $attributes = $this->validatedProduct();
        $dealer = $this->protectionDealer();

        if ($this->editingProductId !== null) {
            $product = $this->ownProduct($this->editingProductId);
            $before = $product->only(array_keys($attributes));
            $product->update($attributes);
            AuditLog::record('protection.updated', $product, $before, $product->only(array_keys($attributes)));
        } else {
            $position = (int) $dealer->protectionProducts()->where('kind', $attributes['kind'])->max('position') + 1;
            $product = $dealer->protectionProducts()->create($attributes + ['position' => $position]);
            AuditLog::record('protection.created', $product, null, $attributes);
        }

        $this->resetProductForm();
    }

    public function removeOwnProduct(int $productId): void
    {
        $this->authorizeProtectionManage();
        $product = $this->ownProduct($productId);

        AuditLog::record('protection.removed', $product, ['name' => $product->name, 'kind' => $product->kind], null);
        $product->delete();
        $this->resetProductForm();
    }

    /**
     * The page, grouped by kind: each row says whether it's a default or the
     * dealership's own, whether buyers see it, and the price they see.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function protectionSections(): array
    {
        $dealer = $this->protectionDealer();
        $settings = DealerProtectionSetting::where('dealer_id', $dealer->id)->get()->keyBy('protection_product_id');
        $sections = array_fill_keys(array_keys(ProtectionProduct::KINDS), []);

        $products = ProtectionProduct::query()
            ->where(fn ($query) => $query->whereNull('dealer_id')->where('is_active', true)->orWhere('dealer_id', $dealer->id))
            ->ordered()->get();

        foreach ($products as $product) {
            $setting = $product->isDefault() ? $settings->get($product->id) : null;
            $ownPrice = $setting?->amount_in_cents;

            $sections[$product->kind][] = [
                'product'    => $product,
                'own'        => ! $product->isDefault(),
                'shown'      => $product->isDefault() ? ($setting?->is_enabled ?? true) : true,
                'price'      => ProtectionProduct::priceLabel($ownPrice ?? $product->amount_in_cents, $product->price_basis),
                'repriced'   => $ownPrice !== null,
                'basePrice'  => ProtectionProduct::priceLabel($product->amount_in_cents, $product->price_basis),
            ];
        }

        return $sections;
    }

    private function ownProduct(int $productId): ProtectionProduct
    {
        return ProtectionProduct::where('dealer_id', $this->protectionDealerId())->findOrFail($productId);
    }

    private function settingRow(ProtectionProduct $product): ?DealerProtectionSetting
    {
        return DealerProtectionSetting::where('dealer_id', $this->protectionDealerId())->where('protection_product_id', $product->id)->first();
    }

    private function settingFor(ProtectionProduct $product): DealerProtectionSetting
    {
        return $this->settingRow($product) ?? new DealerProtectionSetting([
            'dealer_id'             => $this->protectionDealerId(),
            'protection_product_id' => $product->id,
        ]);
    }
}
