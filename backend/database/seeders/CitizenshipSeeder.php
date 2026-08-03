<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitizenshipSeeder extends Seeder
{
    /**
     * Справочник из legacy url/grajd/grajd.txt (выгрузка COUNTRIES_TABLE
     * базы продаж, USE_OK=1). Россия (185) — первой в списке.
     */
    public function run(): void
    {
        $raw = json_decode(
            file_get_contents(database_path('data/citizenships.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $rows = [];
        foreach ($raw as $code => $name) {
            $lower = mb_strtolower(trim($name));
            $rows[] = [
                'code' => (int) $code,
                'name' => mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1),
                'active' => true,
                'sort' => (int) $code === 185 ? 0 : 500,
            ];
        }

        DB::table('citizenships')->upsert($rows, ['code'], ['name', 'active', 'sort']);
    }
}
