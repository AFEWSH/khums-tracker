<?php

namespace App\Support\Khums;

use App\Support\Numbers\MoneyRial;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Exact splitting of an already evidenced homogeneous lot; never determines religious eligibility. */
final class ProtectedLotBasis
{
    public static function exit(string $remainingAtoms, string $basisExactRial, string $exitAtoms, string $netProceedsRial): array
    {
        foreach ([$remainingAtoms, $exitAtoms] as $atoms) {
            if (! preg_match('/^[0-9]{1,78}$/D', $atoms) || BigInteger::of($atoms)->isZero()) {
                throw new InvalidArgumentException('Lot quantities must be positive whole atoms.');
            }
        }
        $remaining = BigInteger::of($remainingAtoms);
        $exit = BigInteger::of($exitAtoms);
        $basis = BigRational::of($basisExactRial);
        $proceeds = MoneyRial::fromRial($netProceedsRial);
        if ($exit->isGreaterThan($remaining) || $basis->isNegative() || str_starts_with($proceeds->rial(), '-')) {
            throw new InvalidArgumentException('Exit exceeds the lot or basis/proceeds are negative.');
        }
        $allocated = $basis->multipliedBy($exit)->dividedBy($remaining);
        $net = BigRational::of($proceeds->rial());
        $protectedProceeds = $allocated->isGreaterThan($net) ? $net : $allocated;
        $candidate = $net->minus($protectedProceeds);

        return [
            'allocated_basis_exact_rial' => (string) $allocated,
            'protected_proceeds_exact_rial' => (string) $protectedProceeds,
            'increase_candidate_exact_rial' => (string) $candidate,
            'remaining_atoms' => (string) $remaining->minus($exit),
            'remaining_basis_exact_rial' => (string) $basis->minus($allocated),
            'eligible_rial' => null,
        ];
    }
}
