<?php

namespace Tests\Unit;

use App\Services\StationSalesSource;
use PHPUnit\Framework\TestCase;

class StationSalesSourceTest extends TestCase
{
    public function test_chunk_query_selects_the_date_range_and_linked_sales_without_row_pagination(): void
    {
        [$sql, $bindings] = StationSalesSource::query(['from' => '2026-11-10', 'to' => '2026-11-19', 'extra' => []]);
        $this->assertStringContainsString('s.DATESALE BETWEEN ? AND ?', $sql);
        $this->assertStringContainsString('r.DATESALE BETWEEN ? AND ?', $sql);
        $this->assertStringContainsString('CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE', $sql);
        $this->assertStringNotContainsString('SKIP', $sql);
        $this->assertStringNotContainsString('SELECT FIRST 1500', $sql);
        $this->assertSame(['2026-11-10', '2026-11-19', '2026-11-10', '2026-11-19'], $bindings);
    }

    public function test_zero_fraction_time_has_same_record_key_but_nonzero_fractions_stay_distinct(): void
    {
        $row = array_combine(StationSalesSource::KEY, ['2026-11-19', '10:15:20', 9999, 2, 0, '123', 'АБ', '2026-11-21']);
        $plain = StationSalesSource::record($row, 5710, '2026-11-19 12:00:00');
        $row['TIMESALE'] = '10:15:20.0000';
        $zero = StationSalesSource::record($row, 5710, '2026-11-19 12:00:00');
        $row['TIMESALE'] = '10:15:20.1234';
        $fraction = StationSalesSource::record($row, 5710, '2026-11-19 12:00:00');
        $this->assertSame($plain['record_key'], $zero['record_key']);
        $this->assertNotSame($plain['record_key'], $fraction['record_key']);
    }
}
