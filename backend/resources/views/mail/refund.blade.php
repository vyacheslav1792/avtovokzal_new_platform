{{-- Письмо покупателю о возврате билета.

     Перенос legacy `url/backbycode.php` / `ticketback.php` (одна строка текста:
     «Билет … аннулировн, денежные средства возвращены с учётом комиссии
     автовокзала. Возвращено: … руб.» + предупреждение про задержку банка).
     Сверх legacy: разбивка суммы (стоимость, багаж, удержание) и отдельная
     формулировка, когда банк платёж не принял и деньги возвращает
     бухгалтерия — legacy в этом случае писал то же самое письмо. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $withheld = (float) $refund->ticket_price + (float) $refund->baggage_price - (float) $refund->amount;
@endphp
@component('mail::message')
# Билет возвращён

Билет на рейс **{{ $order->from_name }} — {{ $order->to_name }}**,
отправление {{ $order->date_start->format('d.m.Y') }} в {{ substr((string) $order->time_start, 0, 5) }},
место {{ $refund->place }}@if($refund->serial || $refund->number) (бланк {{ trim($refund->serial.' '.$refund->number) }})@endif,
аннулирован.

@component('mail::table')
| Расчёт возврата | Сумма |
| :-------------- | ----: |
| Стоимость билета | {{ $money($refund->ticket_price) }} ₽ |
@if((float) $refund->baggage_price > 0)
| Багаж | {{ $money($refund->baggage_price) }} ₽ |
@endif
| Удержано автовокзалом ({{ $refund->penalty_percent }}%) | {{ $money($withheld) }} ₽ |
| **К возврату** | **{{ $money($refund->amount) }} ₽** |
@endcomponent

@if($refund->status === \App\Models\Refund::STATUS_BANK_FAILED)
@component('mail::panel')
Платёж не удалось вернуть автоматически. Билет уже аннулирован, деньги вернёт
бухгалтерия автовокзала — это может занять несколько рабочих дней. Если возврат
не придёт, напишите нам, указав код возврата заказа.
@endcomponent
@else
Деньги вернутся на ту же карту, которой вы оплачивали билет.
**Банк перечисляет возврат с задержкой до 7 дней, в отдельных случаях до 30** —
это срок банка, автовокзал на него не влияет.
@endif

@if($order->tickets->count() > 1)
Остальные билеты заказа действуют по-прежнему: они есть в личном кабинете на
сайте, в приложении на телефоне и в PDF, который приходил письмом при покупке.
@endif

@include('mail.partials.signature')
@endcomponent
