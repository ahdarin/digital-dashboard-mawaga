<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Phase 4.3 audit - GoogleAuthController deliberately has ZERO dependency
 * on CEO_EMAIL/config('organization.ceo_email') - login tetap murni exact
 * lookup User.email yang SUDAH ADA, tidak pernah auto-create user atau
 * auto-assign role apapun (termasuk CEO) berdasarkan email Google. Test-
 * test ini regression buat kontrak keamanan itu TETAP tidak berubah
 * setelah CEO-identity cleanup di RoleSeeder/DemoSeeder.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSocialiteUser(string $email, string $googleId = 'google-id-123'): void
    {
        $socialiteUser = \Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getId')->andReturn($googleId);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    }

    public function test_unknown_google_email_remains_rejected(): void
    {
        $this->fakeSocialiteUser('nobody-registered@example.com');

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        // Unknown Google account TIDAK BOLEH auto-created.
        $this->assertDatabaseMissing('users', ['email' => 'nobody-registered@example.com']);
    }

    public function test_inactive_known_google_user_remains_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@523studio.test',
            'status' => 'inactive',
            'login_enabled' => true,
        ]);
        $this->fakeSocialiteUser('inactive@523studio.test');

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_disabled_known_google_user_remains_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'no-login-access@523studio.test',
            'status' => 'active',
            'login_enabled' => false,
        ]);
        $this->fakeSocialiteUser('no-login-access@523studio.test');

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_valid_active_pre_existing_user_still_authenticates(): void
    {
        $user = User::factory()->create([
            'email' => 'valid-user@523studio.test',
            'status' => 'active',
            'login_enabled' => true,
        ]);
        $this->fakeSocialiteUser('valid-user@523studio.test');

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('profile.me'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * CEO_EMAIL cleanup TIDAK mengubah perilaku ini - login tetap TIDAK
     * PERNAH auto-assign role apapun (termasuk CEO) hanya karena Google
     * email seseorang kebetulan match config CEO_EMAIL. Role assignment
     * SATU-SATUNYA jalur tetap RoleSeeder (dijalankan eksplisit oleh
     * operator) atau User Management UI, bukan login flow.
     */
    public function test_google_login_never_auto_assigns_ceo_role_even_if_email_matches_ceo_config(): void
    {
        config(['organization.ceo_email' => 'matches-ceo-config@523studio.test']);
        $user = User::factory()->create([
            'email' => 'matches-ceo-config@523studio.test',
            'status' => 'active',
            'login_enabled' => true,
        ]);
        $this->fakeSocialiteUser('matches-ceo-config@523studio.test');

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('profile.me'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertCount(0, $user->fresh()->roles, 'Login Google TIDAK BOLEH auto-assign role apapun, termasuk CEO - RoleSeeder-lah yang melakukan itu, bukan login flow.');
    }

    /**
     * Error output HTTP-facing (redirect + session errors, yang benar-
     * benar dikirim ke browser) TIDAK BOLEH pernah menyebut CEO_EMAIL
     * config value - baik karena bocor langsung maupun karena kebetulan
     * ke-include di pesan generik manapun. GoogleAuthController memang
     * tidak pernah membaca config ini sama sekali (audit di atas), test
     * ini mendokumentasikan invariant itu secara eksplisit.
     */
    public function test_ceo_email_config_value_never_leaks_into_google_auth_error_output(): void
    {
        config(['organization.ceo_email' => 'super-secret-ceo-value@523studio.test']);
        $this->fakeSocialiteUser('nobody-registered@example.com');

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $errorBag = session('errors');
        $this->assertInstanceOf(\Illuminate\Support\ViewErrorBag::class, $errorBag);
        $message = $errorBag->getBag('default')->first('email');
        $this->assertStringNotContainsString('super-secret-ceo-value@523studio.test', $message);
    }
}
