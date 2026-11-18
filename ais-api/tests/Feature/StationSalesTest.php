<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Reports\StationSalesReport;
use App\Services\EmployeeAuth;
use App\Services\ModuleRegistry;
use App\Services\SiteSettings;
use App\Services\StationCatalog;
use App\Services\StationRegistry;
use App\Services\StationSalesSource;
use App\Services\StationSalesSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StationSalesTest extends TestCase
{
    private int $lookback = 2;

    protected function setUp(): void
    {
        parent::setUp();
        // Явно изолируем обе БД, в том числе от DB_* контейнера compose.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.site' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
            ], 'cache.default' => 'array', 'station_sales.page_size' => 2]);
        DB::purge('sqlite');
        DB::purge('site');
        (require database_path('migrations/2026_11_19_000000_create_station_sales_tables.php'))->up();
        Schema::connection('site')->create('orders', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('awtcod');
            $t->date('date_start');
            $t->string('created_at');
            $t->string('status');
            $t->decimal('total_price', 12, 2);
            $t->json('legacy')->nullable();
        });
        Schema::connection('site')->create('refunds', function (Blueprint $t) {
            $t->string('order_id');
            $t->string('money_refunded_at')->nullable();
            $t->decimal('amount', 12, 2);
            $t->decimal('ticket_price', 12, 2);
            $t->decimal('baggage_price', 12, 2);
            $t->json('legacy')->nullable();
        });
        Schema::connection('site')->create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
        });
        $catalog = Mockery::mock(StationCatalog::class);
        $catalog->shouldReceive('entries')->andReturn([
            '5710' => ['name' => 'Первая'], '5711' => ['name' => 'Вторая'],
        ]);
        $this->app->instance(StationRegistry::class, new StationRegistry($catalog));
        $settings = Mockery::mock(SiteSettings::class);
        $settings->shouldReceive('stationSalesLookbackDays')->andReturnUsing(fn () => $this->lookback);
        $this->app->instance(SiteSettings::class, $settings);
        CarbonImmutable::setTestNow('2026-11-19 12:00:00 Europe/Moscow');
        Carbon::setTestNow('2026-11-19 12:00:00 Europe/Moscow');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sync(): StationSalesSync
    {
        return $this->app->make(StationSalesSync::class);
    }

    private function sale(string $ticket = '1', array $fields = []): array
    {
        return $fields + [
            'DATESALE' => '2026-11-19', 'TIMESALE' => '09:00:00.1234', 'AWTCODSALE' => 9999,
            'WRKCOD' => 1, 'TYPE_OPER' => 0, 'N_TICKET' => $ticket, 'DOB_N_TICKET' => 'АБ',
            'DATESTART' => '2026-11-20', 'AWTCOD' => 5710, 'KKM_OK' => 1, 'TICK_BACK' => 0,
            'PRICE_PLACE' => '100.00', 'PRICE_BAG' => '10.00', 'PRICE_PL_ISX' => '100.00', 'PRICE_BAG_ISX' => '10.00',
        ];
    }

    private function complete(): void
    {
        while ($run = $this->sync()->claim()) {
            $this->sync()->acceptPage($run, []);
        }
    }

    public function test_settings_change_current_window_and_new_runs_without_changing_queued_period(): void
    {
        $sync = $this->sync();
        $first = $sync->start([5710]);
        $this->assertSame('2026-11-17', $first['date1']);
        $this->lookback = 7;
        $second = $sync->start([5710, 5711]);
        $this->assertSame('2026-11-12', $second['date1']);
        $this->assertSame([5710], $second['busy_stations']);
        $this->assertSame('2026-11-17', DB::table('station_sales_sync_runs')->find($first['run_ids'][0])->date_from);
        $this->assertCount(2, DB::table('station_sales_sync_runs')->get());
    }

    public function test_real_settings_reader_uses_saved_value_and_clamps_it(): void
    {
        DB::connection('site')->table('settings')->insert(['key' => 'station_sales_lookback_days', 'value' => '14']);
        $this->assertSame(14, (new SiteSettings)->stationSalesLookbackDays());
        Cache::flush();
        DB::connection('site')->table('settings')->update(['value' => '99999']);
        $this->assertSame(3650, (new SiteSettings)->stationSalesLookbackDays());
    }

    public function test_pages_are_staged_until_day_is_complete_and_repeated_collection_has_no_duplicates(): void
    {
        $sync = $this->sync();
        $sync->start([5710], '2026-11-19', '2026-11-19');
        $run = $sync->claim();
        $sync->acceptPage($run, [$this->sale('1'), $this->sale('2')]);
        $this->assertSame(0, DB::table('station_sales')->count());
        $this->assertSame(2, DB::table('station_sales_staging')->count());
        $this->assertNotNull(DB::table('station_sales_sync_runs')->find($run->id)->cursor);
        $sync->acceptPage($sync->claim(), [$this->sale('3')]);
        $this->assertSame(3, DB::table('station_sales')->count());
        $this->assertSame(0, DB::table('station_sales_staging')->count());
        $sync->start([5710], '2026-11-19', '2026-11-19');
        $sync->acceptPage($sync->claim(), [$this->sale('3', ['TICK_BACK' => 1])]);
        $this->assertSame(1, DB::table('station_sales')->count());
        $this->assertSame(1, DB::table('station_sales')->value('tick_back'));
    }

    public function test_past_period_does_not_change_current_freshness_and_failed_station_does_not_stop_others(): void
    {
        $sync = $this->sync();
        $sync->start([5710]);
        $this->complete();
        $freshness = DB::table('station_sales_sync_states')->where('station_code', 5710)->first();
        $this->assertNotNull($freshness->current_updated_at);
        $sync->start([5710], '2026-10-01', '2026-10-01');
        $this->complete();
        $this->assertEquals($freshness, DB::table('station_sales_sync_states')->where('station_code', 5710)->first());
        $sync->start([5710, 5711]);
        $run = $sync->claim();
        $sync->fail($run, new RuntimeException('Нет связи'));
        $this->complete();
        $states = collect($sync->status()['stations'])->keyBy('code');
        $this->assertSame('failed', $states[5710]['run']['status']);
        $this->assertSame('completed', $states[5711]['run']['status']);
        $this->assertSame($freshness->current_updated_at, $states[5710]['current_updated_at']);
    }

    public function test_restart_resumes_cursor_and_late_worker_cannot_publish_an_expired_claim(): void
    {
        $sync = $this->sync();
        $sync->start([5710], '2026-11-19', '2026-11-19');
        $first = $sync->claim();
        $sync->acceptPage($first, [$this->sale('1'), $this->sale('2')]);
        $lost = $sync->claim();
        $this->assertNull($sync->claim());
        DB::table('station_sales_sync_runs')->where('id', $lost->id)->update(['lease_until' => now()->subMinute()]);
        $resumed = $sync->claim();
        $this->assertSame($lost->cursor, $resumed->cursor);
        $this->assertNotSame($lost->lease_token, $resumed->lease_token);
        $sync->acceptPage($lost, []);
        $this->assertSame(0, DB::table('station_sales')->count());
        $sync->acceptPage($resumed, []);
        $this->assertSame(2, DB::table('station_sales')->count());
    }

    public function test_real_process_timeout_preserves_published_data_and_does_not_mark_station_fresh(): void
    {
        config(['station_sales.page_timeout' => 1]);
        $sync = new class(app(StationRegistry::class), app(SiteSettings::class)) extends StationSalesSync
        {
            protected function pageProcess(object $run): Process
            {
                return new Process([PHP_BINARY, '-r', 'sleep(30);']);
            }
        };
        DB::table('station_sales')->insert(StationSalesSource::record($this->sale(), 5710, now()->toDateTimeString()));
        $sync->start([5710]);
        $run = $sync->claim();
        $start = microtime(true);
        try {
            $sync->readPage($run);
            $this->fail('Процесс должен быть прерван дедлайном.');
        } catch (RuntimeException $e) {
            $sync->fail($run, $e);
        }
        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertSame(1, DB::table('station_sales')->count());
        $this->assertNull(DB::table('station_sales_sync_states')->value('current_updated_at'));
        $this->assertSame('failed', DB::table('station_sales_sync_runs')->value('status'));
    }

    public function test_empty_selection_is_rejected_instead_of_updating_every_station(): void
    {
        $this->expectException(ValidationException::class);
        $this->sync()->start([]);
    }

    public function test_all_legacy_columns_formulas_filters_and_totals(): void
    {
        $site = DB::connection('site');
        foreach ([['a', 5710, 1000, '2026-11-20'], ['b', 5710, 500, '2026-11-30'], ['c', 5711, 200, '2026-11-20']] as [$id, $station, $price, $trip]) {
            $site->table('orders')->insert(['id' => $id, 'awtcod' => $station, 'total_price' => $price, 'date_start' => $trip,
                'created_at' => '2026-11-19 00:00:00 Europe/Moscow', 'status' => 'completed']);
        }
        $site->table('orders')->insert([
            ['id' => 'legacy5', 'awtcod' => 5710, 'total_price' => 9999, 'date_start' => '2026-11-20',
                'created_at' => '2026-11-19 00:00:00 Europe/Moscow', 'status' => 'completed', 'legacy' => '{"status":5}'],
            ['id' => 'not-issued', 'awtcod' => 5710, 'total_price' => 9999, 'date_start' => '2026-11-20',
                'created_at' => '2026-11-19 00:00:00 Europe/Moscow', 'status' => 'paid', 'legacy' => null],
        ]);
        $site->table('refunds')->insert([
            ['order_id' => 'a', 'amount' => 80, 'ticket_price' => 100, 'baggage_price' => 10, 'legacy' => '{"strah":5}', 'money_refunded_at' => '2026-11-19 01:00:00 Europe/Moscow'],
            ['order_id' => 'b', 'amount' => 5, 'ticket_price' => 0, 'baggage_price' => 10, 'legacy' => null, 'money_refunded_at' => '2026-11-19 02:00:00 Europe/Moscow'],
            // Возврат удержания влияет на деньги, стоимость не повторяет.
            ['order_id' => 'a', 'amount' => 3, 'ticket_price' => 0, 'baggage_price' => 0, 'legacy' => null, 'money_refunded_at' => '2026-11-19 03:00:00 Europe/Moscow'],
            // Не возвращённые на карту деньги не входят в сводную.
            ['order_id' => 'a', 'amount' => 99, 'ticket_price' => 100, 'baggage_price' => 0, 'legacy' => null, 'money_refunded_at' => null],
        ]);
        $sales = [
            $this->sale('s', ['AWTCODSALE' => 9984, 'DATESTART' => '2026-11-20', 'PRICE_PLACE' => 200, 'PRICE_BAG' => 20]),
            $this->sale('v', ['DATESTART' => '2026-11-20']),
            $this->sale('p', ['AWTCODSALE' => 9900, 'DATESTART' => '2026-11-30']),
            $this->sale('r', ['TYPE_OPER' => 1, 'DATESTART' => '2026-11-30', 'PRICE_PLACE' => 70, 'PRICE_BAG' => 5, 'PRICE_PL_ISX' => 100, 'PRICE_BAG_ISX' => 10]),
            // KKM_OK=0 всё ещё в ведомости, но не в агентских продажах.
            $this->sale('k', ['KKM_OK' => 0, 'DATESTART' => '2026-11-20']),
            // Возвращённый билет не в ведомости, но входит в продажи.
            $this->sale('t', ['TICK_BACK' => 1, 'DATESTART' => '2026-11-20']),
        ];
        foreach ($sales as $row) {
            DB::table('station_sales')->insert(StationSalesSource::record($row, 5710, now()->toDateTimeString()));
        }
        $report = app(StationSalesReport::class)->run(['date1' => '2026-11-19', 'date2' => '2026-11-20']);
        $section = $report['sections'][0];
        $this->assertCount(20, $section['columns']);
        $this->assertEquals([
            'station' => 'Первая', 'summ' => 1500, 'summ_back2' => 88, 'prihod' => 1412,
            'price_back' => 125, 'krz' => 37, 'ezda' => 885,
            'inved' => 220, 'inved2' => 220, 'inved3' => 440,
            'preprod' => 500, 'prepback' => 10, 'preprodchist' => 490,
            'agentsale' => 330, 'agentback' => 75, 'agentback2' => 100, 'agentkrz' => 25,
            'agentpredsale' => 110, 'agentpredback' => 110, 'agentpredit' => 0,
        ], $section['rows'][0]);
        $this->assertEquals(1700, $section['totals']['summ']);
        $filtered = app(StationSalesReport::class)->run(['date1' => '2026-11-19', 'date2' => '2026-11-20', 'from' => '5711']);
        $this->assertCount(1, $filtered['sections'][0]['rows']);
        $this->assertEquals(200, $filtered['sections'][0]['totals']['summ']);
    }

    public function test_refresh_permission_and_impersonation_are_enforced_on_server(): void
    {
        $employee = Mockery::mock(Employee::class)->makePartial();
        $employee->shouldReceive('can')->with('report:station-sales.view')->andReturnTrue();
        $employee->shouldReceive('can')->with('report:station-sales.refresh')->andReturnFalse();
        $employee->shouldReceive('isImpersonated')->andReturnFalse();
        $auth = Mockery::mock(EmployeeAuth::class);
        $auth->shouldReceive('resolve')->andReturn($employee);
        $this->app->instance(EmployeeAuth::class, $auth);
        $this->getJson('/api/v1/station-sales-sync')->assertOk();
        $this->postJson('/api/v1/station-sales-sync', ['stations' => [5710]])->assertForbidden();
        $this->assertSame(0, DB::table('station_sales_sync_runs')->count());
        $this->assertContains('report:station-sales.refresh', app(ModuleRegistry::class)->permissionKeys());

        $impersonated = Mockery::mock(Employee::class)->makePartial();
        $impersonated->shouldReceive('can')->andReturnTrue();
        $impersonated->shouldReceive('isImpersonated')->andReturnTrue();
        $otherAuth = Mockery::mock(EmployeeAuth::class);
        $otherAuth->shouldReceive('resolve')->andReturn($impersonated);
        $this->app->instance(EmployeeAuth::class, $otherAuth);
        $this->postJson('/api/v1/station-sales-sync', ['stations' => [5710]])->assertForbidden();
    }
}
