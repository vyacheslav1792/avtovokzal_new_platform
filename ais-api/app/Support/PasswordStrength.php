<?php

namespace App\Support;

/**
 * Итог оценки стойкости пароля (см. PasswordStrengthEstimator).
 */
class PasswordStrength
{
    /**
     * @param  int  $score  оценка 0–4: сколько стоит подбор пароля
     * @param  string|null  $warning  чем именно пароль плох
     * @param  list<string>  $suggestions  что с этим делать
     */
    public function __construct(
        public readonly int $score,
        public readonly ?string $warning,
        public readonly array $suggestions,
    ) {}

    public function isStrong(): bool
    {
        return $this->score >= PasswordStrengthEstimator::minScore();
    }
}
