<?php

namespace App\Services;

use App\Models\LgotExcludedAtp;
use App\Models\LgotExcludedTicket;
use App\Support\LgotKind;
use Illuminate\Support\Facades\DB;

/**
 * Списки исключений отчёта «Нулевые билеты» (перенос файлов legacy
 * `ATP_ISKLUCH_PRIG.txt`, `TICKET_ISKLUCH_PRIG.txt` и `ATP_ISKLUCH.txt`,
 * см. ais/lgot_prig_api.php и ais/lgot.php).
 *
 * Два списка про разное: АТП исключаются ЦЕЛИКОМ (с ними есть договор, и
 * их нулевые билеты считать незачем), отдельные билеты — поштучно, когда
 * билет разобрали и признали непригодным к возмещению.
 *
 * **Списки свои у каждого вида рейса** (`kind`): договор с пригородным
 * перевозчиком ничего не говорит о межобластном, и в legacy это были
 * разные файлы.
 */
class LgotExclusions
{
    /**
     * Названия АТП по договорам, в том порядке, в каком их набрал сотрудник.
     *
     * @return list<string>
     */
    public function atpNames(string $kind): array
    {
        return LgotExcludedAtp::where('kind', LgotKind::normalize($kind))
            ->orderBy('position')->orderBy('id')->pluck('name')->all();
    }

    /** Тот же список текстом — как он лежит в поле ввода (строка на АТП). */
    public function atpListText(string $kind): string
    {
        return implode("\n", $this->atpNames($kind));
    }

    /**
     * Разбор текста поля ввода: строка на АТП.
     *
     * Пустые строки отбрасываются. В legacy они оставались в списке
     * сравнения, и лишний перевод строки в файле исключал из расчёта
     * билеты, у которых АТП не определено вовсе (пустое ATPNAME
     * совпадало с пустой строкой списка) — это дефект, а не поведение.
     *
     * @return list<string>
     */
    public function parseAtpList(string $text): array
    {
        $names = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! in_array($line, $names, true)) {
                $names[] = $line;
            }
        }

        return $names;
    }

    /**
     * Сохранить список АТП целиком (как у меню и блоков сайта: PUT
     * заменяет список, порядок строк = порядок в списке).
     *
     * @return list<string>
     */
    public function saveAtpList(string $kind, string $text): array
    {
        $kind = LgotKind::normalize($kind);
        $names = $this->parseAtpList($text);

        DB::transaction(function () use ($kind, $names) {
            LgotExcludedAtp::where('kind', $kind)->delete();
            foreach ($names as $i => $name) {
                LgotExcludedAtp::create(['kind' => $kind, 'name' => $name, 'position' => $i + 1]);
            }
        });

        return $names;
    }

    /**
     * Исключённые билеты для показа: снимок строки + ключ.
     *
     * @return list<array<string, mixed>>
     */
    public function tickets(string $kind): array
    {
        return LgotExcludedTicket::where('kind', LgotKind::normalize($kind))
            ->orderBy('id')->get()
            ->map(fn (LgotExcludedTicket $t) => ($t->data ?? []) + [
                'tick_key' => $t->tick_key,
                'seria' => $t->serial,
                'n_ticket' => $t->number,
            ])
            ->all();
    }

    /**
     * Ключи исключённых билетов — ими отчёт и отбирает строки.
     *
     * @return array<string, true>
     */
    public function ticketKeys(string $kind): array
    {
        return array_fill_keys(
            LgotExcludedTicket::where('kind', LgotKind::normalize($kind))->pluck('tick_key')->all(),
            true,
        );
    }

    /**
     * Добавить билеты в список. Уже исключённый билет не перезаписываем:
     * снимок в списке — тот, что видел исключивший его сотрудник.
     *
     * @param  list<array<string, mixed>>  $rows  строки отчёта целиком
     * @return int сколько билетов добавилось
     */
    public function addTickets(string $kind, array $rows, ?string $addedBy = null): int
    {
        $kind = LgotKind::normalize($kind);
        $added = 0;

        foreach ($rows as $row) {
            $key = trim((string) ($row['tick_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $created = LgotExcludedTicket::firstOrCreate(
                ['kind' => $kind, 'tick_key' => $key],
                [
                    'serial' => mb_substr((string) ($row['seria'] ?? ''), 0, 40),
                    'number' => mb_substr((string) ($row['n_ticket'] ?? ''), 0, 40),
                    'data' => $row,
                    'added_by' => $addedBy,
                ],
            );

            if ($created->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Убрать билеты из списка исключаемых.
     *
     * @param  list<string>  $keys
     * @return int сколько билетов убрано
     */
    public function removeTickets(string $kind, array $keys): int
    {
        $keys = array_values(array_filter(array_map(
            fn ($key) => trim((string) $key),
            $keys,
        ), 'strlen'));

        if (! $keys) {
            return 0;
        }

        return LgotExcludedTicket::where('kind', LgotKind::normalize($kind))
            ->whereIn('tick_key', $keys)->delete();
    }

    /** Очистить список исключённых билетов этого вида рейса целиком. */
    public function clearTickets(string $kind): int
    {
        return LgotExcludedTicket::where('kind', LgotKind::normalize($kind))->delete();
    }
}
