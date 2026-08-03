<?php

namespace App\Reports;

/**
 * Отчёт АИС. Контракт ответа run() тот же, что у legacy apiv2
 * (SPA рисует секции универсальным рендером):
 * ['filters'=>[...], 'errors'=>'', 'summary'=>[{label,value}],
 *  'sections'=>[{id,title,collapsed,columns:[{key,title,type}],rows,totals|null}]]
 * type колонки: string | number | money | date | time.
 */
interface Report
{
    /** Заголовок, группа и описание фильтров (контракт meta apiv2). */
    public function meta(): array;

    /** Выполнить отчёт с фильтрами → секции. */
    public function run(array $filters): array;
}
