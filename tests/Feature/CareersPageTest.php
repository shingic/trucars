<?php

namespace Tests\Feature;

use App\Mail\JobApplicationReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class CareersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_linked_from_the_footer_and_takes_general_applications(): void
    {
        config(['site.careers_roles' => []]);

        $this->get(route('careers'))->assertOk()->assertSee('No open roles right now')->assertSee('General application');
        $this->get(route('home'))->assertOk()->assertSee(route('careers'));
    }

    public function test_open_roles_are_listed_and_can_be_applied_for(): void
    {
        config(['site.careers_roles' => [
            ['title' => 'Customer Success Lead', 'team' => 'Operations', 'location' => 'Toronto, ON', 'type' => 'Full-time', 'summary' => 'Look after shoppers.'],
        ]]);

        $this->get(route('careers'))->assertOk()->assertSee('Customer Success Lead')->assertSee('Operations · Toronto, ON · Full-time')->assertDontSee('No open roles right now');

        Livewire::test('pages::careers')->call('applyFor', 'Customer Success Lead')->assertSet('role', 'Customer Success Lead')
            ->call('applyFor', 'Made-up role')->assertSet('role', 'Customer Success Lead');
    }

    public function test_an_application_is_emailed_with_the_cv_attached(): void
    {
        Mail::fake();

        Livewire::test('pages::careers')
            ->set('firstName', 'Sam')->set('lastName', 'Lee')
            ->set('email', 'sam@example.test')
            ->set('link', 'https://www.linkedin.com/in/samlee')
            ->set('message', 'Five years in automotive retail, keen to help build Trumo.')
            ->set('resume', UploadedFile::fake()->create('my resume final.pdf', 200, 'application/pdf'))
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            ->assertSee('Thanks, Sam');

        Mail::assertSent(JobApplicationReceived::class, function (JobApplicationReceived $mail) {
            return $mail->hasTo('shingi@trueleads.ca')
                && $mail->hasReplyTo('sam@example.test')
                && $mail->application['role'] === 'General application'
                && $mail->resumeName === 'sam-lee-CV.pdf';
        });
    }

    public function test_required_fields_file_types_and_roles_are_checked(): void
    {
        Mail::fake();

        Livewire::test('pages::careers')
            ->set('role', 'CEO')
            ->set('link', 'not a link')
            ->set('resume', UploadedFile::fake()->create('cv.exe', 10))
            ->call('send')
            ->assertHasErrors(['firstName', 'lastName', 'email', 'role', 'link', 'message', 'resume']);

        Mail::assertNothingSent();
    }

    public function test_bots_filling_the_hidden_field_are_ignored(): void
    {
        Mail::fake();

        Livewire::test('pages::careers')
            ->set('firstName', 'Bot')->set('lastName', 'Bot')->set('email', 'bot@example.test')
            ->set('message', 'Buy cheap followers now, best prices!!')->set('website', 'http://spam.test')
            ->call('send')->assertSet('sent', true);

        Mail::assertNothingSent();
    }

    public function test_the_email_renders(): void
    {
        $html = (new JobApplicationReceived(['name' => 'Sam Lee', 'email' => 'sam@example.test', 'phone' => null, 'role' => 'General application', 'link' => null, 'message' => "Hello\nthere"]))->render();

        $this->assertStringContainsString('General application', $html);
        $this->assertStringContainsString('None attached', $html);
    }
}
