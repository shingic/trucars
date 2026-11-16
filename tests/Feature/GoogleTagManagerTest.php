<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleTagManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopper_pages_carry_the_tag_in_production_only(): void
    {
        $this->get(route('shop'))->assertOk()->assertDontSee('GTM-W6W8LT2D');

        $this->app['env'] = 'production';
        $this->get(route('shop'))->assertSee("'GTM-W6W8LT2D'", false)->assertSee('ns.html?id=GTM-W6W8LT2D', false);
        $this->get(route('buyer.login'))->assertSee('GTM-W6W8LT2D', false);

        // Not in the Command Centre.
        $this->actingAs(User::factory()->owner()->create())->get(route('admin.settings'))->assertOk()->assertDontSee('GTM-W6W8LT2D');

        config(['site.gtm_id' => '']);
        $this->get(route('shop'))->assertDontSee('googletagmanager');
    }
}
