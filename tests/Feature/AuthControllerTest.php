<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    // use DatabaseTransactions; // Menggunakan ini agar aman untuk MySQL Clever Cloud
    use RefreshDatabase;

    private $user;
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup User Biasa
        $this->user = User::create([
            'first_name' => 'Regular',
            'last_name' => 'User',
            'email' => 'regular_' . Str::random(5) . '@gycora.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user'
        ]);

        // 2. Setup Admin
        $this->admin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin_' . Str::random(5) . '@gycora.com',
            'password' => bcrypt('admin123'),
            'usertype' => 'admin' // role admin
        ]);
    }

    // =========================================================================
    // TEST REGISTER
    // =========================================================================
    public function test_user_can_register()
    {
        $payload = [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'email' => 'newcustomer_' . Str::random(5) . '@example.com',
            'password' => 'securepassword123',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('message', 'User berhasil didaftarkan')
                 ->assertJsonPath('user.email', $payload['email']);

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'usertype' => 'user'
        ]);
    }

    public function test_register_fails_if_email_already_exists()
    {
        $payload = [
            'first_name' => 'Copy',
            'last_name' => 'Cat',
            'email' => $this->user->email, // Menggunakan email yang sudah ada
            'password' => 'securepassword123',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    // =========================================================================
    // TEST LOGIN USER
    // =========================================================================
    public function test_user_can_login_with_correct_credentials()
    {
        $payload = [
            'email' => $this->user->email,
            'password' => 'password123',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/login', $payload);

        $response->assertStatus(200)
                 ->assertJsonStructure(['message', 'access_token', 'token_type', 'user'])
                 ->assertJsonPath('message', 'Login Berhasil');
    }

    public function test_user_cannot_login_with_wrong_password()
    {
        $payload = [
            'email' => $this->user->email,
            'password' => 'wrongpassword',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/login', $payload);

        $response->assertStatus(422) // Laravel ValidationException melempar 422
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_cannot_login_via_user_login_route()
    {
        // Admin mencoba login di endpoint user biasa
        $payload = [
            'email' => $this->admin->email,
            'password' => 'admin123',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/login', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    // =========================================================================
    // TEST LOGIN ADMIN
    // =========================================================================
    public function test_admin_can_login_via_admin_route()
    {
        $payload = [
            'email' => $this->admin->email,
            'password' => 'admin123',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/admin/login', $payload);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Login Berhasil');
    }

    public function test_regular_user_cannot_login_via_admin_route()
    {
        $payload = [
            'email' => $this->user->email,
            'password' => 'password123',
            'recaptcha_token' => 'dummy-token-for-testing'
        ];

        $response = $this->postJson('/api/admin/login', $payload);

        $response->assertStatus(401)
                 ->assertJsonPath('message', 'Akses ditolak. Email/Password salah atau Anda tidak memiliki akses ke panel ini.');
    }

    // =========================================================================
    // TEST UPDATE PROFILE (USER)
    // =========================================================================
    public function test_user_can_update_their_profile()
    {
        $payload = [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => $this->user->email, // Email tetap
            'recaptcha_token' => 'dummy-token-for-testing',
            'phone' => '081234567890'
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/profile', $payload);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Info profil diperbarui');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'first_name' => 'Updated',
            'phone' => '081234567890'
        ]);
    }

    // =========================================================================
    // TEST UPDATE PASSWORD (USER)
    // =========================================================================
    public function test_user_can_update_password()
    {
        $payload = [
            'old_password' => 'password123',
            'password' => 'newpassword123',
            'recaptcha_token' => 'dummy-token-for-testing',
            'password_confirmation' => 'newpassword123',
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/profile/password', $payload);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Password berhasil diubah');

        // Pastikan password benar-benar terganti dengan mencoba login menggunakan password baru
        $this->assertTrue(Hash::check('newpassword123', $this->user->fresh()->password));
    }

    public function test_user_cannot_update_password_with_wrong_old_password()
    {
        $payload = [
            'old_password' => 'salahbanget',
            'password' => 'newpassword123',
            'recaptcha_token' => 'dummy-token-for-testing',
            'password_confirmation' => 'newpassword123',
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/profile/password', $payload);

        $response->assertStatus(401)
                 ->assertJsonPath('message', 'Password lama tidak sesuai');
    }

    // =========================================================================
    // TEST FORGOT PASSWORD (USER OTP)
    // =========================================================================
    public function test_user_can_request_forgot_password_otp()
    {
        // Memalsukan sistem pengiriman email
        Mail::fake();

        $response = $this->postJson('/api/forgot-password/send-code', [
            'email' => $this->user->email
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Verification code sent to your email.');

        // Pastikan email dikirim ke user
        Mail::assertSent(ResetPasswordCodeMail::class, function ($mail) {
            return $mail->hasTo($this->user->email);
        });

        // Pastikan kode tersimpan di tabel password_reset_codes
        $this->assertDatabaseHas('password_reset_codes', [
            'email' => $this->user->email
        ]);
    }

    public function test_forgot_password_fails_if_email_not_found()
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password/send-code', [
            'email' => 'tidakada@example.com'
        ]);

        $response->assertStatus(404)
                 ->assertJsonPath('message', 'Email address not found in our system.');

        Mail::assertNothingSent();
    }

    public function test_user_can_verify_otp_and_reset_password()
    {
        // 1. Setup Data OTP manual (seolah-olah user sudah request)
        $rawCode = '123456';
        DB::table('password_reset_codes')->insert([
            'email' => $this->user->email,
            'code' => Hash::make($rawCode),
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now()
        ]);

        // 2. Test Verifikasi Kode
        $verifyResponse = $this->postJson('/api/forgot-password/verify-code', [
            'email' => $this->user->email,
            'code' => $rawCode
        ]);

        $verifyResponse->assertStatus(200)
                       ->assertJsonPath('message', 'Code verified successfully.');

        // 3. Test Reset Password
        $resetResponse = $this->postJson('/api/forgot-password/reset', [
            'email' => $this->user->email,
            'code' => $rawCode,
            'password' => 'resetbaru123',
            'password_confirmation' => 'resetbaru123'
        ]);

        $resetResponse->assertStatus(200)
                      ->assertJsonPath('message', 'Password has been successfully reset.');

        // 4. Pastikan password terganti dan tabel OTP bersih
        $this->assertTrue(Hash::check('resetbaru123', $this->user->fresh()->password));

        $this->assertDatabaseMissing('password_reset_codes', [
            'email' => $this->user->email
        ]);
    }

    // =========================================================================
    // TEST FORGOT PASSWORD (ADMIN OTP)
    // =========================================================================
    public function test_admin_can_request_forgot_password_otp()
    {
        Mail::fake();

        $response = $this->postJson('/api/admin/forgot-password/send-code', [
            'email' => $this->admin->email
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Kode verifikasi telah dikirim ke email Anda.');

        $this->assertDatabaseHas('password_reset_codes', [
            'email' => $this->admin->email
        ]);
    }

    public function test_regular_user_cannot_request_admin_forgot_password()
    {
        Mail::fake();

        $response = $this->postJson('/api/admin/forgot-password/send-code', [
            'email' => $this->user->email // User biasa mencoba pakai fitur admin
        ]);

        $response->assertStatus(404)
                 ->assertJsonPath('message', 'Alamat email tidak ditemukan atau tidak memiliki izin akses.');
    }
}
