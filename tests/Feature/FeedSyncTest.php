<?php

namespace Tests\Feature;

use App\Mail\FeedStale;
use App\Models\Brand;
use App\Models\Dealer;
use App\Models\Rooftop;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class FeedSyncTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Rooftop $store;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('homenet');
        config(['filesystems.disks.homenet.root' => Storage::disk('homenet')->path('')]);

        $dealer = $this->makeDealer([
            'name' => 'Test Mazda', 'feed_rooftop_id' => 'homenet-testmazda',
            'brand_id' => Brand::where('name', 'Mazda')->value('id'), 'certifies_at_pickup' => true,
        ]);
        $this->store = $dealer->syncPrimaryRooftop();
        $this->store->update(['feed_file' => 'Trumo_TestMazda.csv']);
    }

    private function putFeed(?int $modifiedAt = null): void
    {
        Storage::disk('homenet')->put('Trumo_TestMazda.csv', file_get_contents(__DIR__ . '/../Fixtures/Trumo_TestMazda.csv'));
        touch(Storage::disk('homenet')->path('Trumo_TestMazda.csv'), $modifiedAt ?? time());
    }

    public function test_a_changed_file_is_pulled_and_imported_and_an_unchanged_one_is_left(): void
    {
        $this->putFeed(time() - 60);

        $this->artisan('feeds:sync')->assertSuccessful();

        $this->assertSame(2, Vehicle::where('is_published', true)->count());
        $this->store->refresh();
        $this->assertNotNull($this->store->feed_file_modified_at);
        $this->assertNull($this->store->feed_error);

        Vehicle::query()->update(['price_in_cents' => 1]); // would be overwritten by a re-import
        $this->artisan('feeds:sync')->expectsOutputToContain('unchanged')->assertSuccessful();
        $this->assertSame(1, (int) Vehicle::max('price_in_cents'));

        $this->putFeed(time()); // HomeNet drops a new file
        $this->artisan('feeds:sync')->assertSuccessful();
        $this->assertGreaterThan(1, (int) Vehicle::min('price_in_cents'));
    }

    public function test_a_missing_file_records_the_problem_and_leaves_the_cars(): void
    {
        $this->putFeed();
        $this->artisan('feeds:sync')->assertSuccessful();
        Storage::disk('homenet')->delete('Trumo_TestMazda.csv');

        $this->artisan('feeds:sync')->assertFailed();

        $this->assertStringContainsString("isn't in HomeNet's upload folder", $this->store->fresh()->feed_error);
        $this->assertSame(2, Vehicle::where('is_published', true)->count());
    }

    public function test_a_feed_that_stops_changing_is_emailed_once_a_day(): void
    {
        $this->putFeed(now()->subDays(3)->timestamp);

        $this->artisan('feeds:sync')->assertSuccessful();
        $this->artisan('feeds:sync')->assertSuccessful();

        Mail::assertSent(FeedStale::class, 1);
        Mail::assertSent(FeedStale::class, fn (FeedStale $mail) => $mail->hasTo('shingi@trueleads.ca') && $mail->stores->first()->is($this->store));
    }

    public function test_a_missing_upload_folder_says_what_to_set(): void
    {
        config(['filesystems.disks.homenet.root' => '/nowhere/trumo_homenet']);

        $this->artisan('feeds:sync')->expectsOutputToContain('HOMENET_FEED_PATH')->assertFailed();
    }

    public function test_status_lists_each_store(): void
    {
        $this->artisan('feeds:sync', ['--status' => true])->expectsOutputToContain('Trumo_TestMazda.csv')->assertSuccessful();
    }
}
