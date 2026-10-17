<?php

use App\Support\Khums\KhumsDraft;

it('computes an exact one-fifth only for distinct eligible and validated components', function (): void {
    $result = KhumsDraft::evaluate([
        ['component_id' => 'synthetic-income-1', 'value_rial' => '101', 'eligible_rial' => '101',
            'rule_id' => 'K02', 'evidence_status' => 'confirmed'],
        ['component_id' => 'synthetic-income-2', 'value_rial' => '40', 'eligible_rial' => '20',
            'rule_id' => 'K02', 'evidence_status' => 'confirmed'],
    ], ['K02'], true);

    expect($result['status'])->toBe('computed')
        ->and($result['known_subtotal_exact_rial'])->toBe('24.2')
        ->and($result['total_due_exact_rial'])->toBe('24.2')
        ->and($result['payment_amount_rial'])->toBeNull()
        ->and($result['items'][0]['one_fifth_exact_rial'])->toBe('20.2');
});

it('excludes a synthetic protected principal without counting it again as eligible income', function (): void {
    $result = KhumsDraft::evaluate([
        ['component_id' => 'synthetic-protected-plus-income', 'value_rial' => '130', 'eligible_rial' => '30',
            'rule_id' => 'K02', 'evidence_status' => 'confirmed'],
    ], ['K02'], true);

    expect($result['total_due_exact_rial'])->toBe('6')
        ->and($result['items'][0]['eligible_rial'])->toBe('30');
});

it('keeps unknown basis, inactive rule and unknown boundary out of the total', function (): void {
    $result = KhumsDraft::evaluate([
        ['component_id' => 'known', 'value_rial' => '100', 'eligible_rial' => '100',
            'rule_id' => 'K02', 'evidence_status' => 'confirmed'],
        ['component_id' => 'unknown', 'value_rial' => '50', 'eligible_rial' => null,
            'rule_id' => 'K02', 'evidence_status' => 'unknown'],
        ['component_id' => 'inactive', 'value_rial' => '80', 'eligible_rial' => '80',
            'rule_id' => 'K04', 'evidence_status' => 'confirmed'],
    ], ['K02'], false);

    expect($result['status'])->toBe('incomplete')
        ->and($result['known_subtotal_exact_rial'])->toBe('20')
        ->and($result['total_due_exact_rial'])->toBeNull()
        ->and($result['issues'])->toContain('boundary_time_unconfirmed', 'eligible_basis_missing:unknown', 'rule_not_active:inactive');
});

it('rejects duplicate components and impossible eligible amounts', function (): void {
    $item = ['component_id' => 'same', 'value_rial' => '100', 'eligible_rial' => '80',
        'rule_id' => 'K02', 'evidence_status' => 'confirmed'];
    expect(fn () => KhumsDraft::evaluate([$item, $item], ['K02'], true))->toThrow(InvalidArgumentException::class);
    $item['eligible_rial'] = '101';
    expect(fn () => KhumsDraft::evaluate([$item], ['K02'], true))->toThrow(InvalidArgumentException::class);
    $item['eligible_rial'] = '-1';
    expect(fn () => KhumsDraft::evaluate([$item], ['K02'], true))->toThrow(InvalidArgumentException::class);
});
