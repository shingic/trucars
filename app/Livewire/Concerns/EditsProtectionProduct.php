<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ProtectionProduct;
use Illuminate\Validation\Rule;

/**
 * The add/edit form for one protection product: a coverage plan, GAP, an
 * add-on or an accessory. Shared by the Command Centre defaults page and each
 * dealership's own list, so both validate the same way.
 *
 * The host decides which product may be edited and saves it; this only holds
 * the form and turns it into clean attributes.
 */
trait EditsProtectionProduct
{
    public bool $showProductForm = false;
    public ?int $editingProductId = null;

    public string $productKind = ProtectionProduct::KIND_ADDON;
    public string $productName = '';
    public string $productDescription = '';
    public string $productFeatures = '';   // one per line, plans only
    public $productAmount = '';            // dollars; blank = no price shown
    public string $productBasis = ProtectionProduct::BASIS_BIWEEKLY;
    public bool $productFeatured = false;

    public function startAddProduct(string $kind): void
    {
        $this->resetProductForm();
        $this->productKind = array_key_exists($kind, ProtectionProduct::KINDS) ? $kind : ProtectionProduct::KIND_ADDON;
        $this->productBasis = $this->productKind === ProtectionProduct::KIND_ACCESSORY ? ProtectionProduct::BASIS_ONE_TIME : ProtectionProduct::BASIS_BIWEEKLY;
        $this->showProductForm = true;
    }

    public function cancelProductForm(): void
    {
        $this->resetProductForm();
    }

    protected function fillProductForm(ProtectionProduct $product): void
    {
        $this->resetProductForm();
        $this->editingProductId = $product->id;
        $this->productKind = $product->kind;
        $this->productName = $product->name;
        $this->productDescription = (string) $product->description;
        $this->productFeatures = implode("\n", $product->features ?? []);
        $this->productAmount = $product->amount_in_cents === null ? '' : number_format($product->amount_in_cents / 100, 2, '.', '');
        $this->productBasis = $product->price_basis;
        $this->productFeatured = $product->is_featured;
        $this->showProductForm = true;
    }

    /** @return array<string, mixed> attributes ready to save */
    protected function validatedProduct(): array
    {
        $this->validate([
            'productKind'        => ['required', Rule::in(array_keys(ProtectionProduct::KINDS))],
            'productName'        => ['required', 'string', 'max:80'],
            'productDescription' => ['nullable', 'string', 'max:200'],
            'productFeatures'    => ['nullable', 'string', 'max:2000'],
            'productAmount'      => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'productBasis'       => ['required', Rule::in(array_keys(ProtectionProduct::BASES))],
        ], [], [
            'productName'   => 'name',
            'productAmount' => 'price',
        ]);

        $isPlan = $this->productKind === ProtectionProduct::KIND_PLAN;
        $features = collect(preg_split('/\R/', $this->productFeatures))
            ->map(fn ($line) => trim((string) $line))->filter()->unique()->take(12)->values()->all();

        return [
            'kind'            => $this->productKind,
            'name'            => trim($this->productName),
            'description'     => trim($this->productDescription) ?: null,
            'features'        => $isPlan && $features !== [] ? $features : null,
            'amount_in_cents' => $this->productAmount === '' || $this->productAmount === null ? null : (int) round((float) $this->productAmount * 100),
            'price_basis'     => $this->productBasis,
            'is_featured'     => $isPlan && $this->productFeatured,
        ];
    }

    protected function resetProductForm(): void
    {
        $this->showProductForm = false;
        $this->editingProductId = null;
        $this->productKind = ProtectionProduct::KIND_ADDON;
        $this->productName = '';
        $this->productDescription = '';
        $this->productFeatures = '';
        $this->productAmount = '';
        $this->productBasis = ProtectionProduct::BASIS_BIWEEKLY;
        $this->productFeatured = false;
        $this->resetErrorBag();
    }
}
