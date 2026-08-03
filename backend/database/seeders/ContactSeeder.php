<?php

namespace Database\Seeders;

use App\Models\ContactDetail;
use App\Models\ContactPoint;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    /**
     * Первичное наполнение страницы контактов данными legacy
     * (avtovokzal57/url/contacts.php). Идемпотентен: если в таблицах уже
     * что-то есть — не трогаем, дальше контакты живут в админке.
     */
    public function run(): void
    {
        if (ContactDetail::query()->exists() || ContactPoint::query()->exists()) {
            $this->command?->info('Контакты уже заполнены — сид пропущен.');

            return;
        }

        $raw = json_decode(
            file_get_contents(database_path('data/contacts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach (array_values($raw['details']) as $position => $detail) {
            ContactDetail::create([
                'position' => $position,
                'label' => $detail['label'],
                'kind' => $detail['kind'],
                'items' => $detail['items'],
                'is_active' => $detail['is_active'] ?? true,
            ]);
        }

        foreach (array_values($raw['points']) as $position => $point) {
            ContactPoint::create([
                'position' => $position,
                'name' => $point['name'],
                'work_hours' => $point['work_hours'],
                'phones' => $point['phones'],
                'address' => $point['address'],
                'breaks' => $point['breaks'],
                'is_active' => $point['is_active'],
            ]);
        }
    }
}
