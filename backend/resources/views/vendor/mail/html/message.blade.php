{{-- Шапка и подвал всех писем сайта.

     Переопределяет vendor-шаблон Laravel (подключается через
     config/mail.php → markdown.paths): там в шапке и подвале стоит
     `config('app.name')` («Laravel») и английское «All rights reserved».
     Ссылка ведёт на сайт (site.url), а не на app.url — последний у нас
     адрес API. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('site.url')">
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
© {{ date('Y') }} {{ config('site.company') }} · [{{ config('site.brand') }}]({{ config('site.url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
