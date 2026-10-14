<?php

namespace App\Actions\Finance;

use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OpeningSetup
{
    public const DATE = '2026-08-11';

    public function session(): object
    {
        Gate::authorize('access-app');
        DB::table('setup_sessions')->insertOrIgnore([
            'id' => 1, 'status' => 'draft', 'revision' => 0, 'step' => 'settings',
            'opening_date' => self::DATE, 'previous_khums_settled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('setup_sessions')->where('id', 1)->first();
    }

    public function draftItems(): array
    {
        Gate::authorize('access-app');

        return DB::table('opening_items')->where('setup_session_id', 1)->orderBy('id')->get()
            ->map(fn ($item): array => [
                'id' => (int) $item->id, 'kind' => $item->kind, 'name' => $item->name,
                'amount_toman' => MoneyRial::fromRial((string) $item->amount_rial)->toman(),
                'protected_toman' => MoneyRial::fromRial((string) $item->protected_rial)->toman(),
                'evidence' => $item->evidence,
            ])->all();
    }

    /** @return array{assets_toman:string, liabilities_toman:string, net_toman:string} */
    public function draftSummary(): array
    {
        Gate::authorize('access-app');
        $assets = MoneyRial::fromRial('0');
        $liabilities = MoneyRial::fromRial('0');
        foreach (DB::table('opening_items')->where('setup_session_id', 1)->get(['kind', 'amount_rial']) as $item) {
            if ($item->kind === 'payable') {
                $liabilities = $liabilities->add(MoneyRial::fromRial((string) $item->amount_rial));
            } else {
                $assets = $assets->add(MoneyRial::fromRial((string) $item->amount_rial));
            }
        }

        return [
            'assets_toman' => $assets->toman(),
            'liabilities_toman' => $liabilities->toman(),
            'net_toman' => $assets->subtract($liabilities)->toman(),
        ];
    }

    public function saveSettings(bool $previousKhumsSettled): void
    {
        Gate::authorize('access-app');
        DB::transaction(function () use ($previousKhumsSettled): void {
            $this->lockState();
            $session = $this->session();
            $this->requireDraft($session);
            DB::table('setup_sessions')->where('id', 1)->update([
                'previous_khums_settled' => $previousKhumsSettled,
                'step' => 'cash', 'revision' => $session->revision + 1, 'updated_at' => now(),
            ]);
        });
    }

    public function addItem(string $kind, string $name, string $toman, string $protectedToman, string $evidence): int
    {
        Gate::authorize('access-app');
        if (! in_array($kind, ['bank', 'cash', 'broker_cash', 'wallet_cash', 'receivable', 'payable'], true)) {
            throw new InvalidArgumentException('Unsupported opening item kind.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Invalid opening item name.');
        }
        if (! in_array($evidence, ['unknown', 'user_declared'], true)) {
            throw new InvalidArgumentException('Invalid opening evidence.');
        }
        $amount = MoneyRial::fromToman($toman);
        $protected = MoneyRial::fromToman($protectedToman);
        if ($amount->rial()[0] === '-' || $protected->rial()[0] === '-' || MoneyRial::fromRial($amount->rial())->subtract($protected)->rial()[0] === '-') {
            throw new InvalidArgumentException('Protected opening amount must be within the balance.');
        }
        if ($kind === 'payable' && $protected->rial() !== '0') {
            throw new InvalidArgumentException('Debt is not protected capital.');
        }
        if ($protected->rial() !== '0' && $evidence !== 'user_declared') {
            throw new InvalidArgumentException('Protected opening amount needs an explicit declaration.');
        }

        return DB::transaction(function () use ($kind, $name, $amount, $protected, $evidence): int {
            $this->lockState();
            $session = $this->session();
            $this->requireDraft($session);
            $id = DB::table('opening_items')->insertGetId([
                'setup_session_id' => 1, 'kind' => $kind, 'name' => $name,
                'amount_rial' => $amount->rial(), 'protected_rial' => $protected->rial(),
                'evidence' => $evidence, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('setup_sessions')->where('id', 1)->update([
                'step' => 'cash', 'revision' => $session->revision + 1, 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function removeDraftItem(int $id): void
    {
        Gate::authorize('access-app');
        DB::transaction(function () use ($id): void {
            $this->lockState();
            $session = $this->session();
            $this->requireDraft($session);
            DB::table('opening_items')->where('id', $id)->where('setup_session_id', 1)->delete();
            DB::table('setup_sessions')->where('id', 1)->update([
                'revision' => $session->revision + 1, 'updated_at' => now(),
            ]);
        });
    }

    /** Confirm only the phase-3 cash/IRR opening represented in this draft. */
    public function confirm(int $seenRevision, int $actorId, string $idempotencyKey, bool $confirmNoItems = false): ?int
    {
        Gate::authorize('access-app');
        if ($actorId !== 1 || auth()->id() !== $actorId || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid actor or idempotency key.');
        }

        return DB::transaction(function () use ($seenRevision, $actorId, $idempotencyKey, $confirmNoItems): ?int {
            $this->lockState();
            $session = $this->session();
            if ($session->status === 'confirmed') {
                if ($session->confirm_key === $idempotencyKey) {
                    return DB::table('operations')->where('idempotency_key', $idempotencyKey)->where('type', 'opening')->value('id');
                }
                $existing = DB::table('operations')->where('idempotency_key', $idempotencyKey)->where('type', 'opening')->value('id');
                if ($existing !== null) {
                    return (int) $existing;
                }
                throw new FinanceInputException('Opening was already confirmed.');
            }
            if ((int) $session->revision !== $seenRevision) {
                throw new FinanceInputException('Opening draft changed; review it again.');
            }
            $items = DB::table('opening_items')->where('setup_session_id', 1)->orderBy('id')->get();
            if ($items->isEmpty() && ! $confirmNoItems) {
                throw new FinanceInputException('Explicitly confirm that there are no cash opening items.');
            }
            $asset = MoneyRial::fromRial('0');
            $liability = MoneyRial::fromRial('0');
            foreach ($items as $item) {
                $amount = MoneyRial::fromRial((string) $item->amount_rial);
                if ($item->kind === 'payable') {
                    $liability = $liability->add($amount);
                } else {
                    $asset = $asset->add($amount);
                }
            }
            $net = $asset->subtract($liability);
            $operationId = null;
            if ($items->isNotEmpty() && ($asset->rial() !== '0' || $liability->rial() !== '0')) {
                $equityId = DB::table('accounts')->insertGetId([
                    'code' => 'opening-equity', 'name' => 'سرمایه افتتاحیه', 'class' => 'equity', 'subtype' => 'opening',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $payload = hash('sha256', json_encode($items->map(fn ($item) => [
                    (int) $item->id, $item->kind, $item->name, (string) $item->amount_rial,
                    (string) $item->protected_rial, $item->evidence,
                ])->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $operationId = DB::table('operations')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'type' => 'opening', 'effective_date' => self::DATE,
                    'time_precision' => 'date', 'boundary_relation' => 'unknown', 'sequence' => 1,
                    'idempotency_key' => $idempotencyKey, 'payload_hash' => $payload, 'actor_id' => $actorId,
                    'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $lineNo = 1;
                foreach ($items as $item) {
                    $amount = MoneyRial::fromRial((string) $item->amount_rial);
                    $protected = MoneyRial::fromRial((string) $item->protected_rial);
                    $accountId = DB::table('accounts')->insertGetId([
                        'code' => 'opening-'.$item->id, 'name' => $item->name,
                        'class' => $item->kind === 'payable' ? 'liability' : 'asset',
                        'subtype' => $item->kind, 'balance_rial' => $amount->rial(),
                        'protected_min_rial' => $item->kind === 'payable' ? '0' : $protected->rial(),
                        'protected_max_rial' => $item->kind === 'payable' ? '0' : $amount->rial(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (in_array($item->kind, ['receivable', 'payable'], true)) {
                        DB::table('obligations')->insert([
                            'account_id' => $accountId, 'kind' => $item->kind,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    if ($amount->rial() !== '0') {
                        DB::table('journal_lines')->insert([
                            'operation_id' => $operationId, 'account_id' => $accountId,
                            'side' => $item->kind === 'payable' ? 'credit' : 'debit',
                            'amount_rial' => $amount->rial(), 'line_no' => $lineNo++,
                        ]);
                    }
                    DB::table('provenance_events')->insert([
                        'operation_id' => $operationId, 'account_id' => $accountId,
                        'origin_kind' => 'opening', 'balance_after_rial' => $amount->rial(),
                        'protected_min_after_rial' => $item->kind === 'payable' ? '0' : $protected->rial(),
                        'protected_max_after_rial' => $item->kind === 'payable' ? '0' : $amount->rial(),
                        'evidence' => $item->evidence, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($protected->rial() !== '0') {
                        DB::table('user_decisions')->insert([
                            'kind' => 'opening_protected_declaration', 'target_type' => 'opening_item', 'target_id' => $item->id,
                            'payload' => json_encode(['amount_rial' => $protected->rial(), 'evidence' => $item->evidence], JSON_THROW_ON_ERROR),
                            'actor_id' => $actorId, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                if ($net->rial() !== '0') {
                    DB::table('journal_lines')->insert([
                        'operation_id' => $operationId, 'account_id' => $equityId,
                        'side' => $net->rial()[0] === '-' ? 'debit' : 'credit',
                        'amount_rial' => $net->rial()[0] === '-' ? substr($net->rial(), 1) : $net->rial(),
                        'line_no' => $lineNo,
                    ]);
                }
                DB::table('audit_events')->insert([
                    'operation_id' => $operationId, 'actor_id' => $actorId,
                    'action' => 'opening_confirmed', 'facts_hash' => $payload, 'recorded_at' => now(),
                ]);
            } elseif ($items->isNotEmpty()) {
                foreach ($items as $item) {
                    $accountId = DB::table('accounts')->insertGetId([
                        'code' => 'opening-'.$item->id, 'name' => $item->name,
                        'class' => $item->kind === 'payable' ? 'liability' : 'asset',
                        'subtype' => $item->kind, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (in_array($item->kind, ['receivable', 'payable'], true)) {
                        DB::table('obligations')->insert([
                            'account_id' => $accountId, 'kind' => $item->kind,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
            if ($operationId === null) {
                DB::table('audit_events')->insert([
                    'actor_id' => $actorId, 'action' => 'opening_empty_confirmed',
                    'facts_hash' => hash('sha256', json_encode([
                        'setup_revision' => $seenRevision,
                        'item_ids' => $items->pluck('id')->all(),
                        'previous_khums_settled' => (bool) $session->previous_khums_settled,
                    ], JSON_THROW_ON_ERROR)),
                    'recorded_at' => now(),
                ]);
            }
            if ($session->previous_khums_settled) {
                DB::table('user_decisions')->insert([
                    'kind' => 'previous_khums_settled', 'target_type' => 'setup_session', 'target_id' => 1,
                    'payload' => json_encode(['year' => 1405, 'scope' => 'general_declaration_only'], JSON_THROW_ON_ERROR),
                    'actor_id' => $actorId, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('setup_sessions')->where('id', 1)->update([
                'status' => 'confirmed', 'step' => 'cash_confirmed', 'confirmed_at' => now(),
                'confirm_key' => $idempotencyKey,
                'revision' => $session->revision + 1, 'updated_at' => now(),
            ]);
            DB::table('financial_state')->where('id', 1)->update(['opening_confirmed_at' => now(), 'updated_at' => now()]);

            return $operationId;
        });
    }

    private function requireDraft(object $session): void
    {
        if ($session->status !== 'draft') {
            throw new FinanceInputException('Opening was already confirmed.');
        }
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update([
            'revision' => DB::raw('revision + 1'), 'updated_at' => now(),
        ]);
    }
}
