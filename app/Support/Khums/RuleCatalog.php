<?php

namespace App\Support\Khums;

use LogicException;

final class RuleCatalog
{
    public function version(): string
    {
        return (string) config('khums_rules.version');
    }

    public function all(): array
    {
        return config('khums_rules.rules', []);
    }

    public function manifestHash(): string
    {
        return hash('sha256', json_encode(config('khums_rules'), JSON_THROW_ON_ERROR));
    }

    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (array $rule): bool => $rule['status'] === 'active' && $rule['review'] === 'validated'));
    }

    public function requireActive(string $id): array
    {
        foreach ($this->active() as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }

        throw new LogicException("Rule {$id} is not active and validated.");
    }
}
