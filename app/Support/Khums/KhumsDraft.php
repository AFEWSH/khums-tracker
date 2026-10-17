<?php

namespace App\Support\Khums;

use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Pure, read-only evaluation of explicitly supplied religious components. */
final class KhumsDraft
{
    /**
     * @param  array<int,array{component_id:string,value_rial:?string,eligible_rial:?string,rule_id:?string,evidence_status:string}>  $components
     * @param  array<int,string>  $activeRuleIds
     */
    public static function evaluate(array $components, array $activeRuleIds, bool $boundaryConfirmed): array
    {
        $seen = [];
        $items = [];
        $issues = $boundaryConfirmed ? [] : ['boundary_time_unconfirmed'];
        $knownEligible = BigDecimal::zero();
        $knownCount = 0;

        foreach ($components as $component) {
            $id = $component['component_id'];
            if ($id === '' || isset($seen[$id])) {
                throw new InvalidArgumentException('Each basis component must have a unique nonempty id.');
            }
            $seen[$id] = true;
            $value = $component['value_rial'] === null ? null : MoneyRial::fromRial($component['value_rial']);
            $eligible = $component['eligible_rial'] === null ? null : MoneyRial::fromRial($component['eligible_rial']);
            if (($value !== null && str_starts_with($value->rial(), '-'))
                || ($eligible !== null && str_starts_with($eligible->rial(), '-'))
                || ($value !== null && $eligible !== null && BigDecimal::of($eligible->rial())->isGreaterThan($value->rial()))) {
                throw new InvalidArgumentException('Component values must be nonnegative and eligible cannot exceed value.');
            }
            $reason = match (true) {
                $value === null => 'value_missing',
                $eligible === null => 'eligible_basis_missing',
                $component['evidence_status'] !== 'confirmed' => 'evidence_unconfirmed',
                $component['rule_id'] === null || ! in_array($component['rule_id'], $activeRuleIds, true) => 'rule_not_active',
                default => null,
            };
            if ($reason === null) {
                $knownEligible = $knownEligible->plus($eligible->rial());
                $knownCount++;
            } else {
                $issues[] = $reason.':'.$id;
            }
            $items[] = [
                'component_id' => $id, 'value_rial' => $value?->rial(),
                'eligible_rial' => $reason === null ? $eligible->rial() : null,
                'one_fifth_exact_rial' => $reason === null ? (string) BigDecimal::of($eligible->rial())->dividedBy(5, 1)->strippedOfTrailingZeros() : null,
                'rule_id' => $component['rule_id'],
                'status' => $reason === null ? 'computed' : ($reason === 'value_missing' || $reason === 'eligible_basis_missing' ? 'incomplete' : 'needs_review'),
                'issue' => $reason,
            ];
        }

        if ($components === []) {
            $issues[] = 'no_components';
        }

        return [
            'items' => $items,
            'issues' => $issues,
            'status' => $issues === [] ? 'computed' : 'incomplete',
            'known_subtotal_exact_rial' => $knownCount === 0 ? null : (string) $knownEligible->dividedBy(5, 1)->strippedOfTrailingZeros(),
            'total_due_exact_rial' => $issues === [] ? (string) $knownEligible->dividedBy(5, 1)->strippedOfTrailingZeros() : null,
            'payment_amount_rial' => null,
        ];
    }
}
