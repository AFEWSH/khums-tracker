<?php

namespace App\Support\Khums;

use App\Support\Numbers\MoneyRial;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Replays explicit, evidenced physical allocations. Never chooses FIFO or a spending policy. */
final class ProtectedOriginReplay
{
    public const VERSION = 'protected-origin-replay-v1';

    public static function evaluate(array $operations, array $plan, array $anchors = []): array
    {
        $cash = [];
        $lots = [];
        $sales = [];
        $seenOperations = [];
        $lotAnchors = array_values(array_filter($anchors, fn ($a) => $a['kind'] === 'lot'));
        usort($lotAnchors, fn ($a, $b) => [$a['date'], $a['decision_id']] <=> [$b['date'], $b['decision_id']]);
        $cashAnchors = array_values(array_filter($anchors, fn ($a) => $a['kind'] === 'cash'));
        foreach ($operations as $operation) {
            $id = $operation['id'];
            if (! is_int($id) || $id < 1 || in_array($id, $seenOperations, true)) {
                throw new InvalidArgumentException('Each posted operation must have a unique positive id.');
            }
            $seenOperations[] = $id;
            $type = $operation['type'];
            self::applyLotAnchors($lotAnchors, $operation['date'], $lots);
            if (! in_array($type, ['opening', 'income', 'expense', 'transfer', 'asset_buy', 'asset_sell', 'asset_convert', 'neutral_reversal'], true)) {
                throw new InvalidArgumentException('Origin replay needs review for operation '.$id.'.');
            }
            $choice = $plan[$id] ?? [];
            if (! is_array($choice) || (isset($choice['cash_spends']) && ! is_array($choice['cash_spends']))
                || (isset($choice['lots']) && ! is_array($choice['lots']))) {
                throw new InvalidArgumentException('Origin choices must contain structured cash and lot allocations.');
            }
            if (array_diff(array_keys($choice), ['cash_spends', 'lots', 'acquisition_basis_rial', 'purchase_fee_rial']) !== []) {
                throw new InvalidArgumentException('Unknown origin allocation field.');
            }
            if ($type !== 'asset_buy' && (isset($choice['purchase_fee_rial']) || isset($choice['acquisition_basis_rial']))) {
                throw new InvalidArgumentException('Purchase evidence cannot be attached to a different operation.');
            }
            $spent = BigRational::zero();
            $creditIds = [];
            foreach ($operation['cash_lines'] as $line) {
                $account = $line['account_id'];
                $cash[$account] ??= ['balance' => BigRational::zero(), 'basis' => BigRational::zero()];
                if ($line['side'] !== 'credit') {
                    continue;
                }
                $creditIds[] = $account;
                if (! isset($choice['cash_spends'][$account]) || ! is_string($choice['cash_spends'][$account])) {
                    throw new InvalidArgumentException('Choose the protected part of each cash outflow explicitly.');
                }
                $amount = self::nonnegative($choice['cash_spends'][$account]);
                $value = BigRational::of($line['amount_rial']);
                $unprotected = $cash[$account]['balance']->minus($cash[$account]['basis']);
                $minimum = $value->minus($unprotected);
                if ($minimum->isNegative()) {
                    $minimum = BigRational::zero();
                }
                if ($amount->isGreaterThan($value) || $amount->isGreaterThan($cash[$account]['basis']) || $amount->isLessThan($minimum)) {
                    throw new InvalidArgumentException('Cash origin allocation violates conservation at operation '.$id.'.');
                }
                $cash[$account]['basis'] = $cash[$account]['basis']->minus($amount);
                $spent = $spent->plus($amount);
            }
            if (array_diff(array_keys($choice['cash_spends'] ?? []), $creditIds) !== []) {
                throw new InvalidArgumentException('Cash allocation names a nonexistent outflow.');
            }
            $received = BigRational::zero();
            if ($operation['exit'] !== null) {
                $exit = $operation['exit'];
                $atoms = BigInteger::zero();
                $consideration = BigInteger::zero();
                foreach ($choice['lots'] ?? [] as $lotId => $allocation) {
                    if (! is_array($allocation) || ! isset($lots[$lotId]) || $lots[$lotId]['account_id'] !== $exit['account_id']
                        || ! is_string($allocation['atoms'] ?? null) || ! is_string($allocation['net_proceeds_rial'] ?? null)) {
                        throw new InvalidArgumentException('Physical allocation names an unavailable lot.');
                    }
                    $lot = &$lots[$lotId];
                    $split = ProtectedLotBasis::exit($lot['remaining_atoms'], $lot['protected_basis_exact_rial'],
                        $allocation['atoms'], $allocation['net_proceeds_rial']);
                    $atoms = $atoms->plus($allocation['atoms']);
                    $consideration = $consideration->plus($allocation['net_proceeds_rial']);
                    $received = $received->plus($split['protected_proceeds_exact_rial']);
                    $lot['remaining_atoms'] = $split['remaining_atoms'];
                    $lot['protected_basis_exact_rial'] = $split['remaining_basis_exact_rial'];
                    $sales[] = $split + [
                        'operation_id' => $id, 'lot_id' => (int) $lotId, 'account_id' => $exit['account_id'],
                        'cash_account_id' => $exit['cash_account_id'], 'effective_date' => $operation['date'],
                        'exit_atoms' => $allocation['atoms'], 'net_proceeds_rial' => $allocation['net_proceeds_rial'],
                        'economic_cost_rial' => $exit['economic_cost_rial'],
                        'economic_cost_scope' => 'whole_operation',
                        'type' => $type, 'evidence' => 'explicit_physical_allocation',
                    ];
                    unset($lot);
                }
                if (! $atoms->isEqualTo($exit['atoms']) || ! $consideration->isEqualTo($exit['net_proceeds_rial'])) {
                    throw new InvalidArgumentException('Physical quantities or consideration do not match the posted operation.');
                }
            } elseif (($choice['lots'] ?? []) !== []) {
                throw new InvalidArgumentException('This operation has no asset exit.');
            }
            foreach ($operation['new_lots'] as $lot) {
                if (isset($lots[$lot['lot_id']])) {
                    throw new InvalidArgumentException('A lot cannot be acquired twice.');
                }
                $basis = match ($type) {
                    'opening' => $lot['opening_basis_rial'] === null
                        ? throw new InvalidArgumentException('The opening lot basis is not evidenced.')
                        : self::nonnegative($lot['opening_basis_rial']),
                    'asset_buy' => isset($choice['acquisition_basis_rial']) && is_string($choice['acquisition_basis_rial'])
                        ? self::nonnegative($choice['acquisition_basis_rial'])
                        : throw new InvalidArgumentException('Confirm the acquired protected basis separately from economic cost.'),
                    'asset_convert' => $received,
                    default => throw new InvalidArgumentException('Unexpected asset acquisition.'),
                };
                if ($type === 'asset_buy') {
                    if (! is_string($choice['purchase_fee_rial'] ?? null)) {
                        throw new InvalidArgumentException('Confirm the purchase fee against the posted commitment.');
                    }
                    $price = self::verifiedPurchasePrice($operation, $choice['purchase_fee_rial']);
                    $minimum = $spent->minus($choice['purchase_fee_rial']);
                    if ($minimum->isNegative()) {
                        $minimum = BigRational::zero();
                    }
                    if ($basis->isGreaterThan($spent) || $basis->isGreaterThan($price) || $basis->isLessThan($minimum)) {
                        throw new InvalidArgumentException('Purchase basis cannot create capital or discard more than the verified fee.');
                    }
                }
                if (count($operation['new_lots']) !== 1 && $type !== 'opening') {
                    throw new InvalidArgumentException('Acquisition needs an explicit split between its target lots.');
                }
                $lots[$lot['lot_id']] = ['lot_id' => $lot['lot_id'], 'account_id' => $lot['account_id'],
                    'remaining_atoms' => $lot['atoms'], 'protected_basis_exact_rial' => (string) $basis];
            }
            $debits = array_values(array_filter($operation['cash_lines'], fn ($line) => $line['side'] === 'debit'));
            if (in_array($type, ['asset_buy', 'asset_convert'], true) && count($operation['new_lots']) !== 1) {
                throw new InvalidArgumentException('The acquired target lot is missing.');
            }
            if (in_array($type, ['transfer', 'asset_sell'], true) && count($debits) !== 1) {
                throw new InvalidArgumentException('This operation needs one receiving cash account.');
            }
            foreach ($operation['cash_lines'] as $line) {
                $account = $line['account_id'];
                if ($line['side'] === 'debit') {
                    $cash[$account]['balance'] = $cash[$account]['balance']->plus($line['amount_rial']);
                    $added = match ($type) {
                        'opening' => isset($operation['opening_cash'][$account])
                            ? self::nonnegative($operation['opening_cash'][$account])
                            : throw new InvalidArgumentException('Opening cash origin is unknown.'),
                        'transfer' => $spent,
                        'asset_sell' => $received,
                        default => BigRational::zero(),
                    };
                    $cash[$account]['basis'] = $cash[$account]['basis']->plus($added);
                } else {
                    $cash[$account]['balance'] = $cash[$account]['balance']->minus($line['amount_rial']);
                }
                if ($cash[$account]['balance']->isNegative() || $cash[$account]['basis']->isGreaterThan($cash[$account]['balance'])) {
                    throw new InvalidArgumentException('Cash balance or protected basis became inconsistent.');
                }
            }
            foreach ($cashAnchors as $index => $anchor) {
                if ($anchor['after_operation_id'] !== $id) {
                    continue;
                }
                $account = $anchor['account_id'];
                if (! isset($cash[$account])) {
                    throw new InvalidArgumentException('A settled cash basis has no matching account.');
                }
                $cash[$account]['basis'] = $cash[$account]['basis']->plus(self::nonnegative($anchor['basis_rial']));
                if ($cash[$account]['basis']->isGreaterThan($cash[$account]['balance'])) {
                    throw new InvalidArgumentException('Settled cash basis exceeds the remaining funds.');
                }
                unset($cashAnchors[$index]);
            }
        }
        self::applyLotAnchors($lotAnchors, '9999-12-31', $lots);
        if ($cashAnchors !== []) {
            throw new InvalidArgumentException('A settled cash basis cannot be located in this history.');
        }
        if (array_diff(array_keys($plan), $seenOperations) !== []) {
            throw new InvalidArgumentException('Plan names an operation outside the reviewed history.');
        }
        $holdings = [];
        foreach ($lots as $lot) {
            $account = $lot['account_id'];
            $holdings[$account] ??= ['account_id' => $account, 'lot_ids' => [], 'lot_positions' => [],
                'status' => 'traced', 'protected_basis_exact_rial' => '0', 'remaining_atoms' => '0', 'issues' => []];
            $holdings[$account]['lot_ids'][] = $lot['lot_id'];
            $holdings[$account]['lot_positions'][] = $lot;
            $holdings[$account]['protected_basis_exact_rial'] = (string) BigRational::of($holdings[$account]['protected_basis_exact_rial'])->plus($lot['protected_basis_exact_rial']);
            $holdings[$account]['remaining_atoms'] = (string) BigInteger::of($holdings[$account]['remaining_atoms'])->plus($lot['remaining_atoms']);
        }
        $positions = [];
        foreach ($cash as $id => $state) {
            $positions[] = ['account_id' => (int) $id, 'status' => 'traced',
                'protected_basis_exact_rial' => (string) $state['basis'], 'sale_operation_ids' => [], 'issues' => []];
        }

        return ['policy_version' => self::VERSION, 'holdings' => array_values($holdings), 'cash_positions' => $positions, 'sales' => $sales];
    }

    private static function nonnegative(string $value): BigRational
    {
        if (! preg_match('/^[0-9]{1,78}(?:\/[1-9][0-9]{0,77})?$/D', $value)) {
            throw new InvalidArgumentException('Expected an exact nonnegative integer or fraction.');
        }

        return BigRational::of($value);
    }

    public static function verifiedPurchasePrice(array $operation, string $feeRial): string
    {
        $fee = MoneyRial::fromRial($feeRial)->rial();
        $credits = array_values(array_filter($operation['cash_lines'], fn ($line) => $line['side'] === 'credit'));
        if (count($credits) !== 1 || count($operation['new_lots']) !== 1 || str_starts_with($fee, '-')) {
            throw new InvalidArgumentException('Purchase evidence needs a single source and target.');
        }
        $price = BigInteger::of($credits[0]['amount_rial'])->minus($fee);
        if ($price->isNegative() || $price->isZero()) {
            throw new InvalidArgumentException('Purchase consideration must be positive.');
        }
        $lot = $operation['new_lots'][0];
        foreach ([false, true] as $afterOpening) {
            $payload = [$lot['account_id'], $credits[0]['account_id'], $operation['date'], $lot['atoms'],
                (string) $price, $fee, $afterOpening];
            if (hash_equals($operation['payload_hash'] ?? '', hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))) {
                return (string) $price;
            }
        }

        throw new InvalidArgumentException('Fee evidence does not match the original posted purchase.');
    }

    private static function applyLotAnchors(array &$anchors, string $throughDate, array &$lots): void
    {
        while ($anchors !== [] && $anchors[0]['date'] <= $throughDate) {
            $anchor = array_shift($anchors);
            if (! isset($lots[$anchor['lot_id']])
                || ! BigInteger::of($lots[$anchor['lot_id']]['remaining_atoms'])->isEqualTo($anchor['covered_atoms'])) {
                throw new InvalidArgumentException('Settled lot basis does not match its physical quantity.');
            }
            $lots[$anchor['lot_id']]['protected_basis_exact_rial'] = (string) self::nonnegative($anchor['basis_rial']);
        }
    }
}
