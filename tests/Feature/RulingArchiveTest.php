<?php

use App\Support\Khums\RuleCatalog;

function rulingIndex(): array
{
    return json_decode(file_get_contents(base_path('docs/khums/rulings/index.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('indexes every khums issue exactly once with a hash and source page', function (): void {
    $index = rulingIndex();
    $numbers = array_map('intval', array_keys($index['issues']));
    sort($numbers);

    expect($numbers)->toBe(range(559, 858));
    foreach ($index['issues'] as $issue) {
        expect($issue['sha256'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($issue['wording_with_footnotes'])->not->toBeEmpty();
    }
    $pages = array_column($index['pages'], 'page_id');
    foreach ($index['issues'] as $issue) {
        expect($pages)->toContain($issue['page_id']);
    }
});

it('points every manifest rule at archived issues and keeps all of them inactive', function (): void {
    $index = rulingIndex();
    $catalog = app(RuleCatalog::class);

    expect($catalog->active())->toBe([]);
    foreach ($catalog->all() as $rule) {
        expect($rule['status'])->toBe('inactive')
            ->and($rule['archive_issues'])->not->toBeEmpty();
        foreach ($rule['archive_issues'] as $number) {
            expect($index['issues'])->toHaveKey((string) $number);
        }
    }
});

it('matches the archived text to its recorded hash when the local text is present', function (): void {
    $pages = rulingIndex()['pages'];
    if (! is_file(base_path('docs/khums/rulings/'.$pages[0]['file']))) {
        $this->markTestSkipped('Full ruling text stays local until the repository is private.');
    }

    foreach ($pages as $page) {
        $raw = file_get_contents(base_path('docs/khums/rulings/'.$page['file']));
        $body = substr($raw, strpos($raw, "---\n\n", 4) + 5);

        expect(hash('sha256', $body))->toBe($page['sha256']);
    }
});
