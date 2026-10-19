<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

it('renders the Persian RTL login and protected dashboard', function (): void {
    $this->get('/login')->assertOk()
        ->assertSee('<html lang="fa" dir="rtl">', escape: false)
        ->assertSee('ورود به حساب')
        ->assertSee('autocomplete="current-password"', escape: false);

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertOk()->assertSee('حساب مالک')->assertSee('خروج از حساب');
});

it('sets security headers and prevents caching private HTML', function (): void {
    $response = $this->actingAs(User::factory()->create())->get('/dashboard');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('issues a session cookie with HttpOnly, strict SameSite and browser-close expiry', function (): void {
    $response = $this->get('/login');
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('strict');
    expect($cookie->getExpiresTime())->toBe(0);
});

it('encrypts private session data before writing it to SQLite', function (): void {
    $this->withSession(['private-marker' => 'synthetic-private-value'])->get('/login')->assertOk();
    $payload = DB::table('sessions')->where('id', session()->getId())->value('payload');
    $stored = base64_decode($payload, strict: true);

    expect($stored)->not->toContain('synthetic-private-value');
    expect(Crypt::decrypt($stored))->toContain('synthetic-private-value');
});

it('protects logout with the real request forgery middleware', function (): void {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    app()->instance('env', 'local'); // Laravel skips CSRF in the testing environment by default.

    $this->post('/logout')->assertStatus(419);
    $this->assertAuthenticatedAs($owner);

    $this->withSession(['_token' => 'synthetic-csrf-token'])
        ->post('/logout', ['_token' => 'synthetic-csrf-token'])
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('protects Livewire updates with request forgery validation', function (): void {
    $uri = app(HandleRequests::class)->getUpdateUri();
    app()->instance('env', 'local');

    $this->withHeader('X-Livewire', 'true')
        ->postJson($uri, ['components' => []])->assertStatus(419);
});

it('rechecks authentication on a real update of a private Livewire component', function (): void {
    $this->actingAs(User::factory()->create());
    $page = $this->get('/dashboard')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    expect($matches)->toHaveCount(2);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

    Auth::logout();

    $this->withHeader('X-Livewire', 'true')
        ->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ])->assertUnauthorized();
});

it('creates only the implemented phase tables and enables SQLite foreign keys', function (): void {
    $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
        ->pluck('name')->sort()->values()->all();

    expect($tables)->toBe([
        'accounts', 'amendment_cases', 'asset_accounts', 'asset_fact_versions', 'asset_opening_items',
        'asset_origin_events', 'asset_prices', 'audit_events', 'categories', 'cpi_points', 'cpi_revisions', 'cpi_series', 'financial_state',
        'foreign_obligation_opening_items', 'foreign_obligations', 'instruments', 'journal_lines',
        'khums_assessments', 'khums_basis_carryovers', 'khums_period_closures', 'khums_period_reviews', 'khums_schedule_versions', 'khums_settlements',
        'lot_allocation_projections', 'lot_allocations', 'lot_basis_adjustments', 'lots', 'migrations', 'obligations',
        'opening_items', 'operations', 'projection_revisions', 'provenance_events', 'quantity_entries',
        'replay_adjustments', 'replay_cash_positions', 'replay_lot_positions', 'sessions',
        'setup_sessions', 'user_decisions', 'users',
    ]);
    expect((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);

    expect(fn () => DB::table('sessions')->insert([
        'id' => 'synthetic-orphan-session', 'user_id' => 99, 'payload' => '', 'last_activity' => now()->timestamp,
    ]))->toThrow(QueryException::class);
});

it('escapes the displayed username instead of rendering HTML', function (): void {
    $owner = User::factory()->create(['login' => '<script>alert(1)</script>']);

    $this->actingAs($owner)->get('/dashboard')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
});

it('does not serve private files or source configuration', function (string $uri): void {
    $this->get($uri)->assertNotFound();
})->with(['/.env', '/storage/app/private/database.sqlite', '/backups/example.sqlite', '/config/database.php']);
