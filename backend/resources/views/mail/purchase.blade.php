{{-- Письмо покупателю после оформления билетов.

     Перенос legacy `avtovokzal57/emails/buy_success.php` (оттуда — все
     формулировки про документ, возврат за 30 минут и письменное заявление).
     Сверх legacy: сам список купленных билетов, код возврата прямо в письме,
     прямая ссылка на страницу возврата с подставленным кодом и PDF билетов
     вложением — legacy давал только ссылку на `print.php?bilet=…`. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
@endphp
@component('mail::message')
# Билеты куплены

Уважаемый пассажир! Вы успешно приобрели билеты на сайте автовокзалов Орловской области.

**Рейс:** {{ $order->from_name }} — {{ $order->to_name }}
@if($order->trip_name)
**Маршрут:** {{ $order->trip_name }}
@endif
**Отправление:** {{ $order->date_start->format('d.m.Y') }} в {{ substr((string) $order->time_start, 0, 5) }}
@if($order->arrival_at)
**Прибытие:** {{ $order->arrival_at->format('d.m.Y H:i') }}
@endif
@if($order->carrier)
**Перевозчик:** {{ $order->carrier }}
@endif

@component('mail::table')
| Место | Пассажир | Билет | Багаж | Стоимость |
| :---- | :------- | :---- | :---- | --------: |
@foreach($order->tickets as $ticket)
| {{ $ticket->place }} | {{ $ticket->last_name }} {{ $ticket->first_name }} {{ $ticket->middle_name }} | {{ trim($ticket->serial.' '.$ticket->number) ?: '—' }}{{ $ticket->type === 'Д' ? ' (детский)' : '' }} | {{ $ticket->baggage ? $ticket->baggage.' шт. — '.$money($ticket->price_bag).' ₽' : '—' }} | {{ $money($ticket->price + $ticket->price_bag) }} ₽ |
@endforeach
@endcomponent

**Итого оплачено: {{ $money($order->total_price) }} ₽**

@component('mail::button', ['url' => $printUrl])
Открыть и распечатать билеты
@endcomponent

@if($pdfAttached)
Билеты также приложены к этому письму одним файлом PDF.
@endif
Они всегда доступны в [личном кабинете]({{ $cabinetUrl }}) — там же видна вся история поездок.

@component('mail::panel')
**Код для возврата: {{ $order->back_code }}**

Он нужен, чтобы вернуть билет без входа в кабинет. Никому его не сообщайте.
@endcomponent

**Возьмите с собой документ, удостоверяющий личность** — тот, который указан при покупке
билета. Посадка в автобус без этого документа невозможна.

Вернуть купленные онлайн билеты можно [по коду возврата]({{ $refundUrl }}), через
[личный кабинет]({{ $cabinetUrl }}) или в кассах автовокзала — **не позднее чем за 30 минут**
до отправления автобуса. Возврат позднее этого срока и после отправления рейса оформляется
**только по письменному заявлению**: обращайтесь к диспетчеру.

{{ config('site.company') }} желает вам приятной поездки.

@include('mail.partials.signature')
@endcomponent
