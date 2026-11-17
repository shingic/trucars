<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_links_to_forgot_password(): void
    {
        $this->get(route('buyer.login'))->assertOk()->assertSee('Forgot password?')->assertSee(route('buyer.password.request'));
        $this->get(route('buyer.password.request'))->assertOk()->assertSee('Forgot your password?');
    }

    public function test_a_shopper_gets_a_link_and_can_choose_a_new_password(): void
    {
        Mail::fake();
        $shopper = User::factory()->create(['email' => 'sam@example.test', 'role' => User::ROLE_CUSTOMER]);

        Livewire::test('pages::buyer-forgot-password')->set('email', 'sam@example.test')->call('sendLink')
            ->assertSet('sent', true)->assertSee('Check your email');

        $url = null;
        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$url) {
            $url = $mail->resetUrl;

            return $mail->hasTo('sam@example.test');
        });

        preg_match('#/reset-password/([^?]+)\?email=#', $url, $m);
        $token = $m[1];

        $this->get('/reset-password/' . $token . '?email=sam%40example.test')->assertOk()->assertSee('Choose a new password');

        Livewire::withQueryParams(['email' => 'sam@example.test'])
            ->test('pages::buyer-reset-password', ['token' => $token])
            ->set('password', 'new-secret-99')->set('password_confirmation', 'new-secret-99')
            ->call('resetPassword')->assertHasNoErrors()->assertRedirect(route('garage'));

        $this->assertTrue(Hash::check('new-secret-99', $shopper->fresh()->password));
        $this->assertSame($shopper->id, Auth::id());

        // The link works once.
        Auth::logout();
        Livewire::withQueryParams(['email' => 'sam@example.test'])
            ->test('pages::buyer-reset-password', ['token' => $token])
            ->set('password', 'another-pass-1')->set('password_confirmation', 'another-pass-1')
            ->call('resetPassword')->assertHasErrors('password');
    }

    public function test_unknown_emails_and_staff_get_the_same_answer_and_no_email(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'staff@example.test', 'role' => User::ROLE_INTERNAL_STAFF]);

        foreach (['nobody@example.test', 'staff@example.test'] as $email) {
            Livewire::test('pages::buyer-forgot-password')->set('email', $email)->call('sendLink')->assertSet('sent', true);
        }

        Mail::assertNothingSent();
    }

    public function test_a_wrong_token_changes_nothing(): void
    {
        $shopper = User::factory()->create(['email' => 'sam@example.test', 'role' => User::ROLE_CUSTOMER]);

        Livewire::withQueryParams(['email' => 'sam@example.test'])
            ->test('pages::buyer-reset-password', ['token' => 'not-a-real-token'])
            ->set('password', 'new-secret-99')->set('password_confirmation', 'new-secret-99')
            ->call('resetPassword')->assertHasErrors('password');

        $this->assertTrue(Hash::check('password', $shopper->fresh()->password));
    }

    public function test_requests_are_rate_limited(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'sam@example.test', 'role' => User::ROLE_CUSTOMER]);

        foreach (range(1, 3) as $i) {
            Livewire::test('pages::buyer-forgot-password')->set('email', 'sam@example.test')->call('sendLink');
        }
        Livewire::test('pages::buyer-forgot-password')->set('email', 'sam@example.test')->call('sendLink')->assertHasErrors('email');

        Mail::assertSent(PasswordResetLink::class, 3);
    }
}
