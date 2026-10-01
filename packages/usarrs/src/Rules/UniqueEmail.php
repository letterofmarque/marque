<?php

declare(strict_types=1);

namespace Marque\Usarrs\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No other account uses this address — in any case.
 *
 * Laravel's `unique` compares as the database does, and PostgreSQL and SQLite
 * compare case-sensitively: `Victim@example.com` passed beside
 * `victim@example.com`. Two accounts for one inbox, and the OAuth callback —
 * which matches addresses ignoring case — could route one owner's confirmation
 * to the other account (Build #124 CP #777). lower() is the same function on
 * all four engines.
 */
class UniqueEmail implements ValidationRule
{
    public function __construct(private readonly mixed $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $model = config('trove.user_model', 'App\\Models\\User');
        $instance = new $model;

        $taken = $model::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($value)])
            ->when($this->ignoreId !== null, fn ($q) => $q->where($instance->getKeyName(), '!=', $this->ignoreId))
            ->exists();

        if ($taken) {
            $fail(__('validation.unique', ['attribute' => $attribute]));
        }
    }
}
