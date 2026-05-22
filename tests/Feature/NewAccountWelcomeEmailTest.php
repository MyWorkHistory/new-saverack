<?php

namespace Tests\Feature;

use App\Mail\NewAccountWelcomeMailable;
use App\Services\ShipHeroCustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class NewAccountWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_self_serve_registration_sends_welcome_email_to_new_user(): void
    {
        Mail::fake();

        $mock = Mockery::mock(ShipHeroCustomerAccountService::class);
        $mock->shouldReceive('tryCreateCustomerAccount')->andReturn(null);
        $this->app->instance(ShipHeroCustomerAccountService::class, $mock);

        $response = $this->postJson('/api/auth/register', [
            'company_name' => 'Esas Beauty',
            'full_name' => 'Chao Wang',
            'email' => 'chao-welcome@example.test',
            'phone' => '555-0100',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();

        Mail::assertSent(NewAccountWelcomeMailable::class, function (NewAccountWelcomeMailable $mail) {
            $built = $mail->build();

            return $built->hasTo('chao-welcome@example.test')
                && $built->subject === 'Your Save Rack Fulfillment account has been created'
                && $mail->greetingName === 'Chao'
                && $mail->accountName === 'Esas Beauty'
                && str_contains($mail->loginUrl, '/login');
        });
    }
}
