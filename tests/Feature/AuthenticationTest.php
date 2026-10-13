<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('requires authentication for the private dashboard', function (): void {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get('/')->assertRedirect(route('login'));
});

it('supports a normal POST login when Livewire JavaScript is unavailable', function (): void {
    $owner = User::factory()->create();

    $this->post('/login', ['login' => 'owner', 'password' => 'synthetic-passphrase-2026'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($owner);
});

it('does not expose registration or public password recovery', function (string $uri): void {
    $this->get($uri)->assertNotFound();
    $this->post($uri)->assertNotFound();
})->with(['/register', '/forgot-password', '/reset-password']);

it('authenticates the owner, rotates the session and clears the password property', function (): void {
    $owner = User::factory()->create();
    $previousSession = session()->getId();

    Livewire::test(Login::class)
        ->set('login', ' OWNER ')
        ->set('password', 'synthetic-passphrase-2026')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
    expect(session()->getId())->not->toBe($previousSession);
    expect(Hash::check('synthetic-passphrase-2026', $owner->password))->toBeTrue();
    $this->get('/dashboard')->assertOk()->assertSee('خوش آمدید');
});

it('rejects invalid credentials with a generic message and clears the password', function (string $login): void {
    User::factory()->create();

    Livewire::test(Login::class)
        ->set('login', $login)
        ->set('password', 'wrong-synthetic-password')
        ->call('authenticate')
        ->assertHasErrors(['login'])
        ->assertSee('نام کاربری یا رمز عبور درست نیست.')
        ->assertSet('password', '');

    $this->assertGuest();
})->with(['owner', 'unknown']);

it('validates login input on the server', function (): void {
    Livewire::test(Login::class)
        ->set('login', str_repeat('a', 65))
        ->set('password', '')
        ->call('authenticate')
        ->assertHasErrors(['login' => 'max', 'password' => 'required']);

    $this->assertGuest();
});

it('limits failed logins and permits a later attempt after the cooldown', function (): void {
    User::factory()->create();
    $form = Livewire::test(Login::class)->set('login', 'owner');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $form->set('password', 'wrong-synthetic-password')
            ->call('authenticate')->assertHasErrors(['login']);
    }

    $form->set('password', 'synthetic-passphrase-2026')
        ->call('authenticate')->assertSee('تلاش‌های ورود بیش از حد مجاز است.');
    $this->assertGuest();

    $this->travel(61)->seconds();

    $form->set('password', 'synthetic-passphrase-2026')
        ->call('authenticate')->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});

it('limits an IP even when the supplied username changes', function (): void {
    User::factory()->create();

    for ($attempt = 0; $attempt < 20; $attempt++) {
        Livewire::test(Login::class)
            ->set('login', 'unknown-'.$attempt)
            ->set('password', 'wrong-synthetic-password')
            ->call('authenticate')->assertHasErrors(['login']);
    }

    Livewire::test(Login::class)
        ->set('login', 'owner')
        ->set('password', 'synthetic-passphrase-2026')
        ->call('authenticate')->assertSee('تلاش‌های ورود بیش از حد مجاز است.');
    $this->assertGuest();
});

it('does not authenticate a disabled owner', function (): void {
    User::factory()->create(['disabled_at' => now()]);

    Livewire::test(Login::class)
        ->set('login', 'owner')
        ->set('password', 'synthetic-passphrase-2026')
        ->call('authenticate')->assertHasErrors(['login']);
    $this->assertGuest();
});

it('invalidates an existing session when the owner is disabled', function (): void {
    $owner = User::factory()->create(['disabled_at' => now()]);

    $this->actingAs($owner)->get('/dashboard')->assertForbidden();
    $this->assertGuest();
});

it('denies a non-owner identity even if its session is authenticated', function (): void {
    $other = User::factory()->create(['id' => 2, 'login' => 'other']);

    $this->actingAs($other)->get('/dashboard')->assertForbidden();
    $this->assertGuest();
});

it('redirects an authenticated owner away from the login form', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/login')->assertRedirect(route('dashboard'));
});

it('logs out through POST and invalidates the session and CSRF token', function (): void {
    $owner = User::factory()->create();
    $this->actingAs($owner)->withSession(['private-marker' => 'synthetic']);
    $previousSession = session()->getId();
    $previousToken = session()->token();

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session()->getId())->not->toBe($previousSession);
    expect(session()->token())->not->toBe($previousToken);
    expect(session()->has('private-marker'))->toBeFalse();
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('does not allow logout through GET', function (): void {
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/logout')->assertStatus(405);
    $this->assertAuthenticatedAs($owner);
});

it('does not allow a login component to switch an authenticated identity', function (): void {
    $owner = User::factory()->create();

    Livewire::actingAs($owner)->test(Login::class)
        ->set('login', 'owner')
        ->set('password', 'synthetic-passphrase-2026')
        ->call('authenticate')->assertForbidden();

    expect(Auth::id())->toBe(User::OWNER_ID);
});
