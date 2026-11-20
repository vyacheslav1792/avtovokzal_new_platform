<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Догоняющая установка `auto_disabled` станциям, которые вели расписание
 * полностью вручную.
 *
 * Миграция `2026_11_21_000004` перенесла ручные маршруты в
 * `manual_routes` и СБРОСИЛА `is_manual` — тот самый признак, по которому
 * `schedule:sync` раньше пропускал такие места. Защиту сняли, а её
 * замену (`auto_disabled`) никому не проставили: записи, которые заводили
 * как «только руками», молча оказались открыты для автосборки.
 *
 * Сама ручная половина при этом не пострадала бы — синк пишет только
 * `routes`. Опасен другой случай: станция ДЕЙСТВУЮЩАЯ и с рабочим
 * профилем (в список `schedule:sync` попадают именно такие — см.
 * `StationCatalog::fromDatabase` в sales-api), а расписание ей набирали
 * руками. Для неё синк заполнит `routes` из базы, и сайт покажет оба
 * списка сразу: один рейс дважды, с разницей в минуты.
 *
 * ТОЛЬКО СТАНЦИИ (`boarding_point_slug is null`). Пунктам посадки
 * автосборку выключать нельзя: собирать их расписание из баз соседних
 * станций — и есть новая функция, ради которой всё это делалось
 * (docs/BOARDING_POINTS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update station_schedules
               set auto_disabled = true,
                   updated_at = now()
             where boarding_point_slug is null
               and jsonb_array_length(coalesce(manual_routes, '[]'::jsonb)) > 0
               and jsonb_array_length(coalesce(routes, '[]'::jsonb)) = 0
        SQL);
    }

    public function down(): void
    {
        // Тем же условием: это ровно те записи, которых касался up().
        // Галочки, выставленные сотрудником после миграции, под условие
        // не попадают — у таких мест автоматическая половина уже пуста
        // не из-за переноса, но отличить их от перенесённых нечем.
        DB::statement(<<<'SQL'
            update station_schedules
               set auto_disabled = false,
                   updated_at = now()
             where boarding_point_slug is null
               and jsonb_array_length(coalesce(manual_routes, '[]'::jsonb)) > 0
               and jsonb_array_length(coalesce(routes, '[]'::jsonb)) = 0
        SQL);
    }
};
