{{-- Шапка и подвал всех писем сайта.

     Переопределяет vendor-шаблон Laravel (подключается через
     config/mail.php → markdown.paths): там в шапке и подвале стоит
     `config('app.name')` («Laravel») и английское «All rights reserved».

     **Ссылок в письмах нет** — ни в шапке, ни в подвале, ни в тексте:
     письма со ссылками чаще попадают в спам, а билеты и коды должны
     доходить (решение заказчика, 15.11.2026). Поэтому и собственный
     html/header.blade.php без `<a href>`. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header>
{{ config('site.brand') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('site.company') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
