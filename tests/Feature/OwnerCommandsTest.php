<?php

use App\Actions\Auth\CreateOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

it('creates the only owner using hidden password prompts', function (): void {
    $this->artisan('owner:create', ['login' => 'OWNER'])
        ->expectsQuestion('رمز عبور (حداقل ۱۲ نویسه)', 'synthetic-passphrase-2026')
        ->expectsQuestion('تکرار رمز عبور', 'synthetic-passphrase-2026')
        ->expectsOutput('حساب مالک ایجاد شد. اکنون می‌توانید وارد برنامه شوید.')
        ->assertSuccessful();

    $owner = User::query()->sole();
    expect($owner->id)->toBe(User::OWNER_ID);
    expect($owner->login)->toBe('owner');
    expect($owner->password)->not->toBe('synthetic-passphrase-2026');
    expect(Hash::check('synthetic-passphrase-2026', $owner->password))->toBeTrue();
});

it('rejects a second owner without changing the first credentials', function (): void {
    $owner = User::factory()->create();
    $originalHash = $owner->password;

    $this->artisan('owner:create', ['login' => 'second'])
        ->expectsOutput('حساب مالک از قبل وجود دارد؛ ساخت حساب دوم مجاز نیست.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
    expect($owner->fresh()->password)->toBe($originalHash);
});

it('rejects weak, mismatched or invalid owner credentials without writing a user', function (string $login, string $password, string $confirmation): void {
    expect(fn () => app(CreateOwner::class)->handle($login, $password, $confirmation))
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(0);
})->with([
    ['owner', 'short', 'short'],
    ['owner', 'synthetic-passphrase-2026', 'different-passphrase-2026'],
    ['invalid username', 'synthetic-passphrase-2026', 'synthetic-passphrase-2026'],
]);

it('resets the password locally and revokes all existing database sessions', function (): void {
    $owner = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'synthetic-session-1', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'synthetic-session-2', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    $this->artisan('owner:reset-password')
        ->expectsQuestion('رمز عبور جدید (حداقل ۱۲ نویسه)', 'new-synthetic-passphrase-2026')
        ->expectsQuestion('تکرار رمز عبور جدید', 'new-synthetic-passphrase-2026')
        ->assertSuccessful();

    expect(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(0);
    expect(Hash::check('synthetic-passphrase-2026', $owner->fresh()->password))->toBeFalse();
    expect(Hash::check('new-synthetic-passphrase-2026', $owner->fresh()->password))->toBeTrue();
});
