<?php

use App\Livewire\RulingLibrary;
use App\Models\User;
use App\Support\Khums\RuleCatalog;
use Livewire\Livewire;

it('keeps the offline ruling library private and read-only', function (): void {
    $this->get('/rulings')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/rulings')
        ->assertOk()->assertSee('احکام و قواعد بررسی‌شده')->assertSee('غیرفعال')
        ->assertSee('https://www.sistani.org/persian/book/26576/6358/');

    Livewire::test(RulingLibrary::class)->assertSee('نسخهٔ فهرست');
});

it('never exposes unvalidated or partial rules as executable', function (): void {
    $catalog = app(RuleCatalog::class);
    expect($catalog->active())->toBe([]);
    foreach (['K01', 'K02', 'K04', 'K11', 'K12'] as $id) {
        expect(fn () => $catalog->requireActive($id))->toThrow(LogicException::class);
    }

    $originalHash = $catalog->manifestHash();
    expect($originalHash)->toMatch('/^[a-f0-9]{64}$/');
    config()->set('khums_rules.rules.0.status', 'active');
    expect($catalog->active())->toBe([]);
    expect($catalog->manifestHash())->not->toBe($originalHash);
});
