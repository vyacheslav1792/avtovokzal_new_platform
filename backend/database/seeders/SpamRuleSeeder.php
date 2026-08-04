<?php

namespace Database\Seeders;

use App\Models\SpamRule;
use Illuminate\Database\Seeder;

class SpamRuleSeeder extends Seeder
{
    /**
     * Первичное наполнение антиспам-словаря правилами legacy
     * (~60 preg_match в теле avtovokzal57/url/contact.php).
     * Идемпотентен: если правила уже заведены — не трогаем, дальше словарь
     * живёт в админке АИС.
     */
    public function run(): void
    {
        if (SpamRule::query()->exists()) {
            $this->command?->info('Антиспам-словарь уже заполнен — сид пропущен.');

            return;
        }

        $raw = json_decode(
            file_get_contents(database_path('data/spam_rules.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach (array_values($raw) as $position => $rule) {
            SpamRule::create([
                'position' => $position,
                'kind' => $rule['kind'],
                'scope' => $rule['scope'],
                'pattern' => $rule['pattern'] ?? null,
                'note' => $rule['note'] ?? null,
                'is_active' => $rule['is_active'] ?? true,
            ]);
        }

        $this->command?->info('Антиспам-словарь: '.count($raw).' правил.');
    }
}
