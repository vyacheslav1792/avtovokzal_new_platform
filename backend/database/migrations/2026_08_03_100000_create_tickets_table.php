<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Билеты — отдельная таблица вместо orders.tickets jsonb:
        // при 30–50 тыс. покупок в месяц по jsonb не поискать ни по номеру
        // билета, ни по пассажиру, а отчёты вынуждены разбирать JSON в PHP.
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // orders.id — varchar(36) (см. миграцию заказов), не uuid.
            $table->string('order_id', 36);
            $table->foreign('order_id')->references('id')->on('orders');
            $table->unsignedSmallInteger('position');     // порядок в заказе

            // Номер бланка (присваивает станция при оформлении)
            $table->string('serial', 20)->nullable();
            $table->string('number', 20)->nullable();

            $table->unsignedSmallInteger('place');
            $table->char('type', 1)->default('П');        // П — полный, Д — детский

            // Деньги
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('baggage')->default(0);
            $table->decimal('price_bag', 12, 2)->default(0);

            // Пассажир
            $table->string('last_name', 40);
            $table->string('first_name', 30);
            $table->string('middle_name', 30)->nullable();
            // Дата рождения обязательна при покупке, но у части импортированных
            // из legacy билетов её нет — колонка nullable.
            $table->date('birth_date')->nullable();
            $table->char('gender', 1)->nullable();
            $table->unsignedSmallInteger('doc_type')->default(21);
            $table->string('doc_series', 10)->nullable();
            $table->string('doc_number', 25);
            $table->unsignedSmallInteger('citizenship')->default(185);
            $table->string('comment', 40)->nullable();

            // Багажные квитанции (≤5 шт., по номерам не ищем)
            $table->jsonb('baggage_tickets')->nullable();

            // Ключи legacy-билета, которым нет места в схеме: grj (гражданство
            // строкой, до появления кодов), doc_orig, dobrstr/price_dobrstr
            // (добровольное страхование — на новом сайте его нет).
            $table->jsonb('legacy')->nullable();

            $table->timestampsTz();

            $table->unique(['order_id', 'serial', 'number']);
            $table->index(['serial', 'number']);
        });

        $this->copyTicketsFromJson();

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tickets');
            // Отчёты фильтруют по дате заказа, дате поездки и станции.
            $table->index('created_at');
            $table->index('date_start');
            $table->index('awtcod');
        });

        Schema::table('refunds', function (Blueprint $table) {
            // FK в Postgres индекс не создаёт — join отчётов идёт по order_id.
            $table->index('order_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['date_start']);
            $table->dropIndex(['awtcod']);
            $table->jsonb('tickets')->nullable();
        });

        // Обратно в jsonb (без потерь: набор полей тот же).
        foreach (DB::table('tickets')->orderBy('order_id')->orderBy('position')->get() as $t) {
            $json = [
                'place' => (string) $t->place,
                'type' => $t->type,
                'price' => (float) $t->price,
                'serial' => $t->serial,
                'number' => $t->number,
                'baggage' => (int) $t->baggage,
                'price_bag' => (float) $t->price_bag,
                'last_name' => $t->last_name,
                'first_name' => $t->first_name,
                'middle_name' => $t->middle_name,
                'birth_date' => $t->birth_date,
                'doc_type' => (int) $t->doc_type,
                'doc_series' => $t->doc_series,
                'doc_number' => $t->doc_number,
                'citizenship' => (int) $t->citizenship,
                'comment' => $t->comment,
                'baggage_tickets' => json_decode($t->baggage_tickets ?? '[]', true),
            ];
            DB::table('orders')->where('id', $t->order_id)->update([
                'tickets' => DB::raw("coalesce(tickets, '[]'::jsonb) || ".DB::getPdo()->quote(json_encode([$json], JSON_UNESCAPED_UNICODE)).'::jsonb'),
            ]);
        }

        Schema::dropIfExists('tickets');
    }

    /** Разложить существующие orders.tickets (jsonb) в строки таблицы. */
    private function copyTicketsFromJson(): void
    {
        $orders = DB::table('orders')->whereNotNull('tickets')
            ->get(['id', 'tickets', 'passengers', 'created_at']);

        foreach ($orders as $order) {
            $tickets = json_decode($order->tickets, true) ?: [];
            $passengers = json_decode($order->passengers, true) ?: [];

            // gender в JSON билета не сохранялся — сшиваем из passengers по месту.
            $genders = [];
            foreach ($passengers as $p) {
                $genders[(string) ($p['place'] ?? '')] = $p['gender'] ?? null;
            }

            foreach (array_values($tickets) as $i => $t) {
                DB::table('tickets')->insert([
                    'id' => (string) Str::uuid7(),
                    'order_id' => $order->id,
                    'position' => $i,
                    'serial' => $t['serial'] ?? null,
                    'number' => $t['number'] ?? null,
                    'place' => (int) $t['place'],
                    'type' => $t['type'] ?? 'П',
                    'price' => $t['price'],
                    'baggage' => (int) ($t['baggage'] ?? 0),
                    'price_bag' => $t['price_bag'] ?? 0,
                    'last_name' => $t['last_name'],
                    'first_name' => $t['first_name'],
                    'middle_name' => $t['middle_name'] ?? null,
                    'birth_date' => $t['birth_date'],
                    'gender' => $genders[(string) $t['place']] ?? null,
                    'doc_type' => (int) ($t['doc_type'] ?? 21),
                    'doc_series' => $t['doc_series'] ?? null,
                    'doc_number' => $t['doc_number'],
                    'citizenship' => (int) ($t['citizenship'] ?? 185),
                    'comment' => $t['comment'] ?? null,
                    'baggage_tickets' => json_encode($t['baggage_tickets'] ?? [], JSON_UNESCAPED_UNICODE),
                    'created_at' => $order->created_at,
                    'updated_at' => $order->created_at,
                ]);
            }
        }
    }
};
