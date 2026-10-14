<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class AssetFacts
{
    public function record(int $lotId, string $kind, ?string $value, ?string $validFrom, string $evidenceNote, ?int $supersedesId = null): int
    {
        Gate::authorize('access-app');
        if ($validFrom !== null) {
            EffectiveTime::dateOnly($validFrom);
        }
        $evidenceNote = trim($evidenceNote);
        $value = $value === null ? null : trim($value);
        if (! in_array($kind, ['acquisition_date', 'acquisition_nature', 'trade_intent', 'ownership_note'], true)
            || $evidenceNote === '' || mb_strlen($evidenceNote) > 240 || ($value !== null && mb_strlen($value) > 160)) {
            throw new InvalidArgumentException('Invalid asset fact or evidence.');
        }
        if ($kind === 'acquisition_date') {
            if ($value === null) {
                throw new InvalidArgumentException('Acquisition date must be explicit.');
            }
            EffectiveTime::dateOnly($value);
            if ($value >= OpeningSetup::DATE) {
                throw new InvalidArgumentException('Pre-opening acquisition fact must predate opening.');
            }
        }

        return DB::transaction(function () use ($lotId, $kind, $value, $validFrom, $evidenceNote, $supersedesId): int {
            DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            $lot = DB::table('lots')->where('id', $lotId)->first();
            if ($lot === null || $lot->acquisition_method !== 'opening') {
                throw new InvalidArgumentException('Only an opening lot can receive a pre-opening fact here.');
            }
            if ($supersedesId !== null) {
                $previous = DB::table('asset_fact_versions')->where('id', $supersedesId)->first();
                if ($previous === null || (int) $previous->lot_id !== $lotId || $previous->kind !== $kind
                    || DB::table('asset_fact_versions')->where('supersedes_id', $supersedesId)->exists()) {
                    throw new InvalidArgumentException('Fact version to supersede is invalid.');
                }
            }
            $id = DB::table('asset_fact_versions')->insertGetId([
                'lot_id' => $lotId, 'valid_from' => $validFrom, 'kind' => $kind,
                'value' => $value, 'evidence_note' => $evidenceNote,
                'actor_id' => auth()->id(), 'supersedes_id' => $supersedesId,
                'recorded_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'asset_fact_recorded',
                'facts_hash' => hash('sha256', json_encode([$id, $lotId, $kind, $value, $validFrom, $evidenceNote, $supersedesId], JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }
}
