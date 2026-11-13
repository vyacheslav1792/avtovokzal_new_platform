{{-- Текстовая версия письма: та же шапка и подвал, что в html-варианте
     (см. соседний html/message.blade.php). --}}
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('site.url')">
            {{ config('site.brand') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ config('site.company') }} · {{ config('site.url') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
