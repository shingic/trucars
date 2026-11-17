<?php

namespace Tests\Feature;

use App\Mail\ContactMessageReceived;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ContactPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_linked_from_the_menus_not_the_top_nav(): void
    {
        $this->get(route('contact'))->assertOk()->assertSee('What can we help you with?');
        $this->get(route('home'))->assertOk()->assertSee(route('contact'));
    }

    public function test_a_message_is_saved_to_inquiries_and_emailed_to_trumo(): void
    {
        Mail::fake();

        Livewire::test('pages::contact')
            ->set('firstName', 'Sam')->set('lastName', 'Lee')
            ->set('email', 'sam@example.test')->set('phone', '416 555 0199')
            ->set('topic', 'financing')
            ->set('message', 'Can I get pre-approved before reserving?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            ->assertSee('Thanks, Sam');

        $lead = Lead::withoutGlobalScopes()->sole();
        $this->assertSame(['Sam Lee', 'sam@example.test', 'financing', null, null], [$lead->name, $lead->email, $lead->topic, $lead->vehicle_id, $lead->dealer_id]);

        Mail::assertSent(ContactMessageReceived::class, fn ($mail) => $mail->hasTo('shingi@trueleads.ca') && $mail->hasReplyTo('sam@example.test'));
    }

    public function test_required_fields_and_a_real_topic(): void
    {
        Livewire::test('pages::contact')
            ->set('topic', 'nonsense')
            ->call('send')
            ->assertHasErrors(['firstName', 'lastName', 'email', 'topic', 'message']);

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_bots_filling_the_hidden_field_are_ignored(): void
    {
        Mail::fake();

        Livewire::test('pages::contact')
            ->set('firstName', 'Bot')->set('lastName', 'Bot')->set('email', 'bot@example.test')
            ->set('message', 'Buy cheap followers now!!')->set('website', 'http://spam.test')
            ->call('send')->assertSet('sent', true);

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
        Mail::assertNothingSent();
    }

    public function test_a_signed_in_shopper_is_prefilled(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Jordan Smith', 'email' => 'jordan@example.test']));

        Livewire::test('pages::contact')
            ->assertSet('firstName', 'Jordan')->assertSet('lastName', 'Smith')->assertSet('email', 'jordan@example.test');
    }

    public function test_it_shows_in_the_command_centre_inquiries_with_its_topic(): void
    {
        Lead::create(['name' => 'Sam Lee', 'email' => 'sam@example.test', 'topic' => 'trade_in', 'message' => 'What is my car worth?']);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => \App\Support\Permission::normalize([\App\Support\Permission::CUSTOMERS_VIEW])]));

        $this->get(route('admin.inquiries'))->assertOk()->assertSee('Contact form · Trading in my vehicle');
    }
}
