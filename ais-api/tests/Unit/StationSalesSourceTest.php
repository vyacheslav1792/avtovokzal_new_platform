<?php

namespace Tests\Unit;

use App\Services\StationSalesSource;
use PHPUnit\Framework\TestCase;

class StationSalesSourceTest extends TestCase
{
    public function test_full_key_cursor_keeps_time_precision_and_quotes_ticket_series(): void
    {
        $cursor = array_combine(StationSalesSource::KEY, ['2026-11-19', '10:15:20.1234', 9999, 2, 0, '123', "А'Б", '2026-11-21']);
        [$sql, $bindings] = StationSalesSource::query('2026-11-19', $cursor, 250);
        $this->assertStringContainsString("s.TIMESALE > TIME '10:15:20.1234'", $sql);
        $this->assertStringContainsString("s.DOB_N_TICKET > 'А''Б'", $sql);
        $this->assertStringContainsString('s.DATESTART >', $sql);
        $this->assertStringContainsString('SELECT FIRST 250', $sql);
        $this->assertStringContainsString('CAST(s.TIMESALE AS VARCHAR(20)) AS TIMESALE', $sql);
        $this->assertStringNotContainsString('SKIP', $sql);
        $this->assertSame(['2026-11-19'], $bindings);
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
