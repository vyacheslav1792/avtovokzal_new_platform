{{-- Подпись писем сайта: перенос «хвоста» legacy emails/buy_success.php
     (АО «Орёлавтотранс», support_email, телефон справочной). Включается во
     ВСЕ письма, чтобы адрес поддержки не расходился по шаблонам. --}}
---

По всем вопросам пишите на [{{ config('site.support_email') }}](mailto:{{ config('site.support_email') }})
или звоните в справочную по телефону {{ config('site.support_phone') }} — также можно обратиться
к сотрудникам автовокзала.

{{ config('site.company') }}, сайт {{ config('site.brand') }}
