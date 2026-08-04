@component('mail::message')
# Новое сообщение с сайта

@if($topic)
**Тема:** {{ $topic }}
@endif

**От:** {{ $message->name }} &lt;{{ $message->email }}&gt;
@if($message->phone)

**Телефон:** {{ $message->phone }}
@endif

**Получено:** {{ $message->created_at->format('d.m.Y H:i:s') }}

---

{{ $message->body }}

---

Обращение сохранено в АИС — там же отмечается ответ.
Ответить можно прямо из почты: письмо уйдёт на адрес отправителя.

{{ config('app.name') }}
@endcomponent
