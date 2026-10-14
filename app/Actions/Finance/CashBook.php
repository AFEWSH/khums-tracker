<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CashBook
{
    /** @param array{type:string, date:string, amount_toman:string, source_id?:int, target_id?:int, category_id?:int, idempotency_key:string, after_opening?:bool, reason?:string} $input */
    public function post(array $input): int
    {
        Gate::authorize('access-app');
        $type = $input['type'] ?? '';
        if (! in_array($type, ['income', 'accrue_income', 'expense', 'transfer', 'lend', 'collect', 'borrow', 'repay'], true)) {
            throw new InvalidArgumentException('Unsupported cash operation.');
        }
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE) {
            throw new InvalidArgumentException('Operation predates the opening.');
        }
        $afterOpening = $input['after_opening'] ?? false;
        if ($date === OpeningSetup::DATE && $afterOpening !== true) {
            throw new InvalidArgumentException('Confirm the operation happened after the opening.');
        }
        $amount = MoneyRial::fromToman($input['amount_toman'] ?? '');
        if ($amount->rial() === '0' || $amount->rial()[0] === '-') {
            throw new InvalidArgumentException('Operation amount must be positive.');
        }
        $key = $input['idempotency_key'] ?? '';
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
        $sourceId = $input['source_id'] ?? null;
        $targetId = $input['target_id'] ?? null;
        if (($sourceId !== null && (! is_int($sourceId) || $sourceId < 1)) || ($targetId !== null && (! is_int($targetId) || $targetId < 1))) {
            throw new InvalidArgumentException('Invalid account identifier.');
        }
        $reason = trim($input['reason'] ?? '');
        if (mb_strlen($reason) > 240) {
            throw new InvalidArgumentException('Reason is too long.');
        }
        $categoryId = $input['category_id'] ?? null;
        if ($categoryId !== null && (! is_int($categoryId) || $categoryId < 1)) {
            throw new InvalidArgumentException('Invalid category identifier.');
        }
        $payload = [
            'type' => $type, 'date' => $date, 'amount_rial' => $amount->rial(),
            'source_id' => $sourceId, 'target_id' => $targetId,
            'category_id' => $categoryId, 'reason' => $reason,
            'after_opening' => $afterOpening === true,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($payload, $hash, $key, $amount): int {
            $this->lockState();
            $previous = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $previous->id;
            }
            $state = DB::table('financial_state')->where('id', 1)->first();
            if ($state->opening_confirmed_at === null) {
                throw new FinanceInputException('Confirm the opening before posting transactions.');
            }
            $latest = DB::table('operations')->max('effective_date');
            if ($latest !== null && $payload['date'] < $latest) {
                throw new FinanceInputException('Backdated posting needs the replay path planned for phase 5.');
            }
            $sequence = (int) DB::table('operations')->where('effective_date', $payload['date'])->max('sequence') + 1;
            $source = $payload['source_id'] ? $this->account($payload['source_id']) : null;
            $target = $payload['target_id'] ? $this->account($payload['target_id']) : null;
            $type = $payload['type'];
            $this->validateEndpoints($type, $source, $target);
            if ($payload['category_id'] !== null) {
                $category = DB::table('categories')->where('id', $payload['category_id'])->whereNull('archived_at')->first();
                if ($category === null || $category->kind !== ($type === 'accrue_income' ? 'income' : $type)) {
                    throw new InvalidArgumentException('Category does not match operation type.');
                }
            }
            $amountRial = $amount->rial();
            $lines = [];
            $system = null;
            if (in_array($type, ['income', 'accrue_income'], true)) {
                $system = $this->systemAccount('income', 'درآمد');
                $lines = [[$target->id, 'debit'], [$system, 'credit']];
            } elseif ($type === 'expense') {
                $system = $this->systemAccount('expense', 'هزینه');
                $lines = [[$system, 'debit'], [$source->id, 'credit']];
            } elseif (in_array($type, ['transfer', 'lend'], true)) {
                $lines = [[$target->id, 'debit'], [$source->id, 'credit']];
            } elseif ($type === 'collect') {
                $lines = [[$target->id, 'debit'], [$source->id, 'credit']];
            } elseif ($type === 'borrow') {
                $lines = [[$target->id, 'debit'], [$source->id, 'credit']];
            } else {
                $lines = [[$target->id, 'debit'], [$source->id, 'credit']];
            }
            if (count($lines) !== 2 || $lines[0][1] !== 'debit' || $lines[1][1] !== 'credit') {
                throw new FinanceInputException('Journal is not balanced.');
            }

            $operationId = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => $type,
                'effective_date' => $payload['date'], 'time_precision' => 'date',
                'boundary_relation' => $payload['date'] === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'category_id' => $payload['category_id'],
                'reason' => $payload['reason'] ?: null, 'posted_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $index => [$accountId, $side]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $operationId, 'account_id' => $accountId,
                    'side' => $side, 'amount_rial' => $amountRial, 'line_no' => $index + 1,
                ]);
            }

            $transfer = in_array($type, ['transfer', 'lend', 'collect'], true);
            $movedMin = MoneyRial::fromRial('0');
            $movedMax = MoneyRial::fromRial('0');
            if ($source !== null) {
                if ($source->class === 'asset') {
                    $beforeBalance = MoneyRial::fromRial((string) $source->balance_rial);
                    $beforeMin = MoneyRial::fromRial((string) $source->protected_min_rial);
                    $beforeMax = MoneyRial::fromRial((string) $source->protected_max_rial);
                    $afterBalance = $beforeBalance->subtract($amount);
                    if ($afterBalance->rial()[0] === '-') {
                        throw new FinanceInputException('Insufficient account or receivable balance.');
                    }
                    if ($transfer) {
                        $movedMin = $this->maxZero($amount->subtract($beforeBalance->subtract($beforeMin)));
                        $movedMax = $this->lesser($amount, $beforeMax);
                    }
                    $newMin = $this->maxZero($beforeMin->subtract($amount));
                    $newMax = $this->lesser($beforeMax, $afterBalance);
                    $this->savePosition($source, $afterBalance, $newMin, $newMax, $operationId, $type, null);
                } else {
                    $afterBalance = MoneyRial::fromRial((string) $source->balance_rial)->add($amount);
                    $this->savePosition($source, $afterBalance, MoneyRial::fromRial('0'), MoneyRial::fromRial('0'), $operationId, $type, null);
                }
            }
            if ($target !== null) {
                if ($target->class === 'asset') {
                    $afterBalance = MoneyRial::fromRial((string) $target->balance_rial)->add($amount);
                    $newMin = MoneyRial::fromRial((string) $target->protected_min_rial)->add($movedMin);
                    $newMax = MoneyRial::fromRial((string) $target->protected_max_rial)->add($movedMax);
                    $this->savePosition($target, $afterBalance, $newMin, $newMax, $operationId, $type, $transfer ? $source?->id : null);
                } else {
                    $afterBalance = MoneyRial::fromRial((string) $target->balance_rial)->subtract($amount);
                    if ($afterBalance->rial()[0] === '-') {
                        throw new FinanceInputException('Repayment exceeds debt principal.');
                    }
                    $this->savePosition($target, $afterBalance, MoneyRial::fromRial('0'), MoneyRial::fromRial('0'), $operationId, $type, null);
                }
            }
            DB::table('audit_events')->insert([
                'operation_id' => $operationId, 'actor_id' => auth()->id(), 'action' => 'cash_posted',
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);

            return $operationId;
        });
    }

    public function createAccount(string $name, string $subtype): int
    {
        Gate::authorize('access-app');
        if (! in_array($subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash', 'receivable', 'payable'], true) || trim($name) === '' || mb_strlen(trim($name)) > 120) {
            throw new InvalidArgumentException('Invalid account.');
        }

        return DB::transaction(function () use ($name, $subtype): int {
            $this->lockState();
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Accounts are created through opening setup first.');
            }
            $id = DB::table('accounts')->insertGetId([
                'code' => 'pending-'.Str::uuid(), 'name' => trim($name),
                'class' => $subtype === 'payable' ? 'liability' : 'asset', 'subtype' => $subtype,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('accounts')->where('id', $id)->update(['code' => 'account-'.$id]);
            if (in_array($subtype, ['receivable', 'payable'], true)) {
                DB::table('obligations')->insert([
                    'account_id' => $id, 'kind' => $subtype, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'account_created',
                'facts_hash' => hash('sha256', $subtype.'|'.$id.'|'.trim($name)), 'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public function createCategory(string $name, string $kind): int
    {
        Gate::authorize('access-app');
        $name = trim($name);
        if (! in_array($kind, ['income', 'expense'], true) || $name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Invalid category.');
        }

        return DB::transaction(function () use ($name, $kind): int {
            $this->lockState();
            if (DB::table('categories')->where('name', $name)->where('kind', $kind)->exists()) {
                throw new InvalidArgumentException('Category already exists.');
            }
            $id = DB::table('categories')->insertGetId([
                'name' => $name, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'category_created',
                'facts_hash' => hash('sha256', $kind.'|'.$name), 'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public function reverseLatest(int $operationId, string $reason, string $idempotencyKey): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 240 || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('A reason and unique reversal key are required.');
        }
        $hash = hash('sha256', json_encode([$operationId, $reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($operationId, $reason, $idempotencyKey, $hash): int {
            $this->lockState();
            $duplicate = DB::table('operations')->where('idempotency_key', $idempotencyKey)->first();
            if ($duplicate !== null) {
                if ($duplicate->type !== 'reversal' || ! hash_equals($duplicate->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $duplicate->id;
            }
            $original = DB::table('operations')->where('id', $operationId)->first();
            if ($original === null || ! in_array($original->type, ['income', 'accrue_income', 'expense', 'transfer', 'lend', 'collect', 'borrow', 'repay'], true)) {
                throw new InvalidArgumentException('This operation cannot be reversed here.');
            }
            if ((int) DB::table('operations')->max('id') !== $operationId
                || DB::table('operations')->where('reversal_of_id', $operationId)->exists()) {
                throw new FinanceInputException('Only the latest unreversed operation can be reversed in phase 3.');
            }
            $lineItems = DB::table('journal_lines')->where('operation_id', $operationId)->orderBy('line_no')->get();
            $events = DB::table('provenance_events')->where('operation_id', $operationId)->orderBy('id')->get();
            $nextSequence = (int) DB::table('operations')->where('effective_date', $original->effective_date)->max('sequence') + 1;
            $reversalId = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'reversal',
                'effective_date' => $original->effective_date, 'time_precision' => $original->time_precision,
                'boundary_relation' => $original->boundary_relation, 'sequence' => $nextSequence,
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'reversal_of_id' => $operationId, 'reason' => $reason,
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lineItems as $index => $line) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $reversalId, 'account_id' => $line->account_id,
                    'side' => $line->side === 'debit' ? 'credit' : 'debit',
                    'amount_rial' => $line->amount_rial, 'line_no' => $index + 1,
                ]);
            }
            foreach ($events as $event) {
                $account = $this->account((int) $event->account_id);
                if ((string) $account->balance_rial !== (string) $event->balance_after_rial) {
                    throw new FinanceInputException('Current account state differs from the operation being reversed.');
                }
                $prior = DB::table('provenance_events')->where('account_id', $event->account_id)
                    ->where('id', '<', $event->id)->orderByDesc('id')->first();
                $this->savePosition(
                    $account,
                    MoneyRial::fromRial((string) ($prior->balance_after_rial ?? '0')),
                    MoneyRial::fromRial((string) ($prior->protected_min_after_rial ?? '0')),
                    MoneyRial::fromRial((string) ($prior->protected_max_after_rial ?? '0')),
                    $reversalId, 'reversal', null,
                );
            }
            DB::table('audit_events')->insert([
                'operation_id' => $reversalId, 'actor_id' => auth()->id(), 'action' => 'cash_reversed',
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);

            return $reversalId;
        });
    }

    private function validateEndpoints(string $type, ?object $source, ?object $target): void
    {
        $cash = fn (?object $account): bool => $account !== null && $account->class === 'asset' && in_array($account->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true);
        $receivable = fn (?object $account): bool => $account !== null && $account->subtype === 'receivable' && $account->class === 'asset';
        $payable = fn (?object $account): bool => $account !== null && $account->subtype === 'payable' && $account->class === 'liability';
        $valid = match ($type) {
            'income' => $source === null && $cash($target),
            'accrue_income' => $source === null && $receivable($target),
            'expense' => $cash($source) && $target === null,
            'transfer' => $cash($source) && $cash($target) && $source->id !== $target->id,
            'lend' => $cash($source) && $receivable($target),
            'collect' => $receivable($source) && $cash($target),
            'borrow' => $payable($source) && $cash($target),
            'repay' => $cash($source) && $payable($target),
        };
        if (! $valid) {
            throw new InvalidArgumentException('Accounts do not match the operation.');
        }
    }

    private function account(int $id): object
    {
        return DB::table('accounts')->where('id', $id)->whereNull('archived_at')->first()
            ?? throw new InvalidArgumentException('Account does not exist or is archived.');
    }

    private function systemAccount(string $class, string $name): int
    {
        $code = 'system-'.$class;
        DB::table('accounts')->insertOrIgnore([
            'code' => $code, 'name' => $name, 'class' => $class, 'subtype' => 'general',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    private function savePosition(object $account, MoneyRial $balance, MoneyRial $minimum, MoneyRial $maximum, int $operationId, string $origin, ?int $from): void
    {
        if ($minimum->rial()[0] === '-' || $maximum->rial()[0] === '-'
            || MoneyRial::fromRial($maximum->rial())->subtract($minimum)->rial()[0] === '-'
            || MoneyRial::fromRial($balance->rial())->subtract($maximum)->rial()[0] === '-') {
            throw new FinanceInputException('Invalid provenance bounds.');
        }
        DB::table('accounts')->where('id', $account->id)->update([
            'balance_rial' => $balance->rial(), 'protected_min_rial' => $minimum->rial(),
            'protected_max_rial' => $maximum->rial(), 'updated_at' => now(),
        ]);
        DB::table('provenance_events')->insert([
            'operation_id' => $operationId, 'account_id' => $account->id,
            'source_account_id' => $from, 'origin_kind' => $origin,
            'balance_after_rial' => $balance->rial(),
            'protected_min_after_rial' => $minimum->rial(),
            'protected_max_after_rial' => $maximum->rial(),
            'evidence' => $from === null ? 'inferred' : 'mixed_unresolved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function maxZero(MoneyRial $value): MoneyRial
    {
        return $value->rial()[0] === '-' ? MoneyRial::fromRial('0') : $value;
    }

    private function lesser(MoneyRial $left, MoneyRial $right): MoneyRial
    {
        return $left->subtract($right)->rial()[0] === '-' ? $left : $right;
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update([
            'revision' => DB::raw('revision + 1'), 'updated_at' => now(),
        ]);
    }
}
