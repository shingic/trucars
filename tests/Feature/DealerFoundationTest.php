<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\Scopes\DealerScope;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealerFoundationTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    /* ---- dealer record ----------------------------------------------- */

    public function test_province_and_postal_code_are_stored_normalised(): void
    {
        $dealer = $this->makeDealer(['province' => 'british columbia', 'postal_code' => 'v2p2m9']);

        $this->assertSame('BC', $dealer->fresh()->province);
        $this->assertSame('V2P 2M9', $dealer->fresh()->postal_code);
    }

    public function test_vehicle_location_comes_from_its_dealer(): void
    {
        $vehicle = $this->vehicle($this->makeDealer());

        $this->assertSame('Chilliwack, BC', $vehicle->display_location);
        $this->assertSame(['45510 Yale Rd', 'Chilliwack, BC V2P 2M9'], $vehicle->dealer->address_lines);
    }

    public function test_vehicle_cannot_be_published_while_its_dealer_lacks_an_address(): void
    {
        $dealer = Dealer::create(['name' => 'Half Set Up Motors', 'city' => 'Toronto', 'status' => Dealer::STATUS_ACTIVE]);

        try {
            $this->vehicle($dealer);
            $this->fail('Publishing should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('street_address', $exception->errors()['is_published'][0]);
        }

        // Unpublished is fine — the car can be loaded and waits for the address.
        $draftCar = $this->vehicle($dealer, ['is_published' => false]);

        $this->expectException(ValidationException::class);
        $draftCar->update(['is_published' => true]);
    }

    public function test_vehicle_cannot_be_moved_onto_a_dealer_without_an_address(): void
    {
        $vehicle = $this->vehicle($this->makeDealer());
        $incomplete = Dealer::create(['name' => 'No Address Auto', 'city' => 'Toronto']);

        $this->expectException(ValidationException::class);
        $vehicle->update(['dealer_id' => $incomplete->id]);
    }

    public function test_draft_dealers_cars_are_not_listed(): void
    {
        $dealer = $this->makeDealer();
        $vehicle = $this->vehicle($dealer, ['model' => 'Hidden Outlander']);
        $dealer->update(['status' => Dealer::STATUS_DRAFT]);

        $this->get('/shop')->assertOk()->assertDontSee('Hidden Outlander');
        $this->get('/cars/' . $vehicle->id)->assertNotFound();
    }

    public function test_vehicle_page_shows_the_dealer_address_and_regulator(): void
    {
        $vehicle = $this->vehicle($this->makeDealer(['registration_number' => '31234']));

        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertSee('45510 Yale Rd · Chilliwack, BC V2P 2M9')
            ->assertSee('VSA&nbsp;#31234', false)
            ->assertDontSee('OMVIC&nbsp;#', false);
    }

    /* ---- reservations keep their history ----------------------------- */

    public function test_reservation_preserves_the_dealer_as_it_was(): void
    {
        $dealer = $this->makeDealer(['registration_number' => '31234']);
        $deal = $this->deal($this->vehicle($dealer));

        $dealer->update(['name' => 'Renamed Motors', 'street_address' => '1 New St']);

        $deal->refresh();
        $this->assertSame('CHILLIWACK MITSUBISHI', $deal->seller_name);
        $this->assertSame('45510 Yale Rd', $deal->dealer_snapshot['street_address']);
        $this->assertSame('31234', $deal->dealer_snapshot['registration_number']);
        $this->assertSame('America/Vancouver', $deal->dealer_snapshot['timezone']);
    }

    public function test_handover_window_is_dealer_local_and_stored_in_utc(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        $vehicle = $this->vehicle($this->makeDealer());
        $this->actingAs(User::factory()->create());

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $vehicle]);
        $this->assertSame('2026-07-02', $checkout->get('handoverDate'));

        $checkout->set('handoverTimeSlot', '10:00')
            ->set('firstName', 'Sam')
            ->set('phone', '6045550100')
            ->call('reserve');

        $deal = Deal::withoutGlobalScope(DealerScope::class)->sole();

        // 10am in Vancouver (PDT, UTC-7) is 17:00 UTC.
        $this->assertSame('2026-07-02 17:00:00', $deal->getRawOriginal('pickup_at'));
        $this->assertSame('2026-07-02 10:00', $deal->local_pickup_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('10:00 am PDT', $deal->handover_summary);

        Carbon::setTestNow();
    }

    /* ---- isolation ---------------------------------------------------- */

    public function test_dealer_staff_still_only_see_their_own_deals(): void
    {
        $ours = $this->makeDealer();
        $theirs = $this->makeDealer(['name' => 'Other Rooftop']);
        $this->deal($this->vehicle($ours));
        $this->deal($this->vehicle($theirs));

        $this->actingAs(User::factory()->staffOf($ours)->create());

        $this->assertSame([$ours->id], Deal::pluck('dealer_id')->unique()->values()->all());
    }

    /* ---- admin ------------------------------------------------------- */


    public function test_admin_sees_dealers_needing_backfill(): void
    {
        $this->makeDealer();
        Dealer::create(['name' => 'Legacy Nissan', 'city' => 'Toronto', 'status' => Dealer::STATUS_ACTIVE]);

        $this->actingAs($this->admin())
            ->get(route('admin.dealers'))
            ->assertOk()
            ->assertSee('1 dealer needs an address backfill')
            ->assertSee('Missing: street address, province, postal code, country');
    }


    public function test_admin_can_create_a_draft_dealer_with_gaps(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer')
            ->set('brandId', $this->brandId('Toyota'))
            ->set('name', 'Bayview Toyota')
            ->set('city', 'Toronto')
            ->set('country', '')
            ->call('save')
            ->assertHasNoErrors();

        $dealer = Dealer::sole();
        $this->assertSame(Dealer::STATUS_DRAFT, $dealer->status);
        $this->assertFalse($dealer->hasCompleteAddress());
        $this->assertNull($dealer->timezone);
    }

    public function test_going_live_needs_a_complete_address_and_timezone(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer')
            ->set('name', 'Bayview Toyota')
            ->set('city', 'Toronto')
            ->set('status', Dealer::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['streetAddress', 'province', 'postalCode', 'timezone']);

        $this->assertSame(0, Dealer::count());
    }

    public function test_admin_creates_a_live_dealer_with_normalised_address_and_suggested_timezone(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer')
            ->set('name', 'Bayview Toyota')
            ->set('registrationNumber', '4567890')
            ->set('brandId', $this->brandId('Toyota'))
            ->set('streetAddress', '1 Bayview Ave')
            ->set('unit', '2')
            ->set('city', 'Toronto')
            ->set('province', 'ON')
            ->assertSet('timezone', 'America/Toronto')
            ->set('postalCode', 'm4g3a1')
            ->set('website', 'bayviewtoyota.ca')
            ->set('status', Dealer::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $dealer = Dealer::sole();
        $this->assertSame('M4G 3A1', $dealer->postal_code);
        $this->assertSame('CA', $dealer->country);
        $this->assertSame('America/Toronto', $dealer->timezone);
        $this->assertSame('https://bayviewtoyota.ca', $dealer->website);
        $this->assertSame('4567890', $dealer->registration_number);
        $this->assertSame('Toyota', $dealer->brand->name);
        $this->assertSame('Toyota', $dealer->franchise_make);
        $this->assertTrue($dealer->isActive());
    }

    public function test_a_dealer_must_have_a_brand(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer')
            ->set('name', 'Bayview Motors')
            ->set('city', 'Toronto')
            ->call('save')
            ->assertHasErrors('brandId');

        $this->assertSame(0, Dealer::count());
    }

    public function test_choosing_a_brand_shows_what_the_dealer_will_follow(): void
    {
        $program = \App\Models\InspectionProgram::create(['make' => 'Toyota', 'name' => 'Toyota Certified', 'applies_to' => 'feed_certified']);
        $program->points()->create(['position' => 1, 'label' => 'Brakes']);
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer')
            ->set('brandId', $this->brandId('Toyota'))
            ->assertSee('Will follow Toyota settings')
            ->assertSee('1-point CPO checklist');
    }

    public function test_saving_the_profile_never_changes_the_brand(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->set('tab', 'info')
            ->assertDontSee('Choose a brand…')
            ->set('tab', 'cpo')
            ->assertSee('Change brand…')
            ->set('brandId', $this->brandId('Nissan'))
            ->set('phone', '604-555-0100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Mitsubishi', $dealer->fresh()->brand->name);
    }

    public function test_changing_a_brand_needs_a_reason_and_the_typed_dealer_name(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs($this->admin());

        $page = Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->call('startBrandChange')
            ->set('newBrandId', $this->brandId('Nissan'))
            ->assertSee('169 points')
            ->call('confirmBrandChange')
            ->assertHasErrors(['brandChangeReason', 'brandChangeConfirm']);

        $page->set('brandChangeReason', 'Re-franchised to Nissan')
            ->set('brandChangeConfirm', 'Chilliwack Mitsu')
            ->call('confirmBrandChange')
            ->assertHasErrors('brandChangeConfirm');

        $this->assertSame('Mitsubishi', $dealer->fresh()->brand->name);

        $page->set('brandChangeConfirm', 'chilliwack mitsubishi')
            ->call('confirmBrandChange')
            ->assertHasNoErrors()
            ->assertSee('is now a Nissan dealer');

        $this->assertSame('Nissan', $dealer->fresh()->franchise_make);
        $entry = \App\Models\AuditLog::where('action', 'dealer.brand_changed')->sole();
        $this->assertSame(['brand' => 'Mitsubishi'], $entry->before);
        $this->assertSame('Re-franchised to Nissan', $entry->after['reason']);
    }

    public function test_only_dealer_managers_can_change_a_brand(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [\App\Support\Permission::DEALERS_VIEW]]));

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->assertDontSee('Change brand…')
            ->call('startBrandChange')
            ->assertForbidden();
    }

    public function test_admin_can_correct_a_timezone_for_an_exception_area(): void
    {
        $dealer = $this->makeDealer(['city' => 'Dawson Creek', 'postal_code' => 'V1G 1A1']);
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->set('timezone', 'America/Dawson_Creek')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('America/Dawson_Creek', $dealer->fresh()->timezone);
    }

    public function test_fixed_offsets_and_foreign_zones_are_rejected(): void
    {
        $this->actingAs($this->admin());

        foreach (['EST', 'UTC-5', 'Europe/London'] as $zone) {
            Livewire::test('pages::admin-dealer', ['dealer' => $this->makeDealer()])
                ->set('timezone', $zone)
                ->call('save')
                ->assertHasErrors('timezone');
        }
    }

    public function test_postal_code_must_match_the_province(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->makeDealer()])
            ->set('postalCode', 'M5V 2T6')
            ->call('save')
            ->assertHasErrors('postalCode');
    }

    public function test_a_live_legacy_dealer_can_be_edited_before_its_backfill(): void
    {
        $legacy = Dealer::create(['name' => 'Legacy Nissan', 'city' => 'Toronto', 'status' => Dealer::STATUS_ACTIVE]);
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer', ['dealer' => $legacy])
            ->assertSet('country', '')
            ->set('brandId', $this->brandId('Nissan'))
            ->set('phone', '416-555-0100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('416-555-0100', $legacy->fresh()->phone);
        $this->assertNull($legacy->fresh()->country); // never assumed
    }

    public function test_backfill_report_lists_incomplete_dealers(): void
    {
        $this->makeDealer();
        $legacy = Dealer::create(['name' => 'Legacy Nissan', 'city' => 'Toronto', 'status' => Dealer::STATUS_ACTIVE]);

        $this->artisan('dealers:needs-backfill')
            ->expectsOutputToContain('Legacy Nissan | active | street_address, province, postal_code, country, timezone')
            ->doesntExpectOutputToContain('CHILLIWACK MITSUBISHI')
            ->assertSuccessful();

        $this->assertNull($legacy->fresh()->street_address);
    }


    /* ---- helpers ----------------------------------------------------- */

    public function test_the_profile_shows_every_tab_with_real_numbers(): void
    {
        $dealer = $this->makeDealer(['phone' => '604-555-0100', 'registration_number' => '30215']);
        $this->vehicle($dealer);
        $this->actingAs($this->admin());

        $page = Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->assertSee('Chilliwack Mitsubishi')
            ->assertSee('Live vehicles')
            ->assertSee('Reservations (30 days)')
            ->assertSee('Setup checklist')
            ->assertSee('3 of 5 completed')
            ->assertDontSee('Page views');

        foreach (['info' => 'Public dealership name', 'location' => 'Street address', 'team' => 'Send invitation', 'cpo' => 'Inspection checklist', 'offers' => 'No current or upcoming offers', 'integrations' => 'Inventory feed'] as $tab => $text) {
            $page->set('tab', $tab)->assertSee($text);
        }
    }

    public function test_status_changes_are_their_own_audited_action(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->call('changeStatus', Dealer::STATUS_DRAFT)
            ->assertSee('back to draft');

        $this->assertSame(Dealer::STATUS_DRAFT, $dealer->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dealer.status_changed', 'target_id' => $dealer->id]);
    }

    public function test_a_storefront_photo_can_be_added_and_is_served(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $dealer = $this->makeDealer();
        $this->actingAs($this->admin());

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->set('coverUpload', \Illuminate\Http\UploadedFile::fake()->image('store.jpg', 1600, 600))
            ->assertSee('Storefront photo updated.');

        $dealer->refresh();
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($dealer->cover_photo_path);
        $this->get($dealer->coverPhotoUrl())->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->owner()->create();
    }

    private function vehicle(Dealer $dealer, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin'            => Str::upper(Str::random(17)),
            'model_year'     => (int) date('Y') - 2,
            'make'           => 'Mitsubishi',
            'model'          => 'Outlander',
            'kilometres'     => 30000,
            'price_in_cents' => 3299000,
            'condition'      => 'USED',
            'is_published'   => true,
            'is_certified'   => true,
        ]);
    }

    private function deal(Vehicle $vehicle): Deal
    {
        return Deal::create([
            'dealer_id'  => $vehicle->dealer_id,
            'vehicle_id' => $vehicle->id,
            'first_name' => 'Pat',
            'last_name'  => 'Lee',
            'email'      => 'pat@example.com',
            'phone'      => '6045550199',
        ]);
    }
}
