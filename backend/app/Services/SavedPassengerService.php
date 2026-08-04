<?php

namespace App\Services;

use App\Models\SavedPassenger;
use App\Models\User;
use App\Support\PassengerName;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Пассажиры, сохранённые покупателем в профиле.
 *
 * Покупают обычно на себя и на одних и тех же людей, поэтому ФИО с
 * документом хранятся в кабинете и подставляются в форму покупки.
 * Нормализация та же, что у билета (PassengerName, PassengerDocument):
 * подставленные данные обязаны совпасть с тем, что уйдёт в SALE, иначе
 * покупатель увидел бы в билете не то, что выбрал.
 */
class SavedPassengerService
{
    /** Список: сначала те, с кем ездили недавно. */
    public function list(User $user): Collection
    {
        return $user->savedPassengers()
            ->orderByRaw('last_used_at desc nulls last')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Создать или обновить запись. Документ — ключ: тот же документ у того
     * же покупателя остаётся одной строкой.
     *
     * @param  array{last_name: string, first_name: string, middle_name?: ?string, birth_date: string, gender?: ?string, doc_type?: ?int, doc_number: string, doc_series?: ?string, citizenship?: ?int}  $data
     */
    public function save(User $user, array $data, ?SavedPassenger $target = null): SavedPassenger
    {
        $docType = (int) ($data['doc_type'] ?? 21);
        $document = PassengerDocument::parse(
            $docType,
            ($data['doc_series'] ?? '').($data['doc_number'] ?? ''),
        );

        $attributes = [
            'last_name' => PassengerName::normalize($data['last_name']),
            'first_name' => PassengerName::normalize($data['first_name']),
            'middle_name' => PassengerName::normalize($data['middle_name'] ?? '') ?: null,
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'] ?? 'М',
            'doc_type' => $docType,
            'doc_series' => $document['series'] ?: null,
            'doc_number' => $document['number'],
            'citizenship' => (int) ($data['citizenship'] ?? PassengerDocument::RUSSIA),
        ];

        $duplicate = $user->savedPassengers()
            ->where('doc_type', $attributes['doc_type'])
            ->where('doc_number', $attributes['doc_number'])
            ->when($target, fn ($q) => $q->whereKeyNot($target->getKey()))
            ->first();

        // Редактирование существующей записи не должно «съесть» другую:
        // документ у покупателя уникален.
        if ($target && $duplicate) {
            throw ValidationException::withMessages([
                'doc_number' => 'Пассажир с таким документом уже сохранён в профиле.',
            ]);
        }

        $passenger = $target ?? $duplicate ?? new SavedPassenger(['user_id' => $user->id]);
        $passenger->fill($attributes);
        $passenger->user_id = $user->id;
        $passenger->save();

        return $passenger;
    }

    /**
     * Запомнить пассажира по итогам покупки. Данные уже нормализованы
     * OrderService (ФИО в верхнем регистре, документ разложен), поэтому
     * пишем как есть и отмечаем дату последней поездки.
     */
    public function remember(User $user, array $passenger): void
    {
        $record = $user->savedPassengers()->firstOrNew([
            'doc_type' => (int) ($passenger['doc_type'] ?? 21),
            'doc_number' => (string) $passenger['doc_number'],
        ]);

        $record->fill([
            'last_name' => $passenger['last_name'],
            'first_name' => $passenger['first_name'],
            'middle_name' => ($passenger['middle_name'] ?? '') ?: null,
            'birth_date' => $passenger['birth_date'],
            'gender' => $passenger['gender'] ?? 'М',
            'doc_series' => ($passenger['doc_series'] ?? '') ?: null,
            'citizenship' => (int) ($passenger['citizenship'] ?? PassengerDocument::RUSSIA),
            'last_used_at' => now(),
        ]);

        $record->save();
    }

    /** Отметить поездку у уже сохранённых пассажиров (список по документам). */
    public function touchUsed(User $user, array $passengers): void
    {
        foreach ($passengers as $passenger) {
            $user->savedPassengers()
                ->where('doc_type', (int) ($passenger['doc_type'] ?? 21))
                ->where('doc_number', (string) ($passenger['doc_number'] ?? ''))
                ->update(['last_used_at' => now()]);
        }
    }

    public function find(User $user, int $id): SavedPassenger
    {
        return $user->savedPassengers()->findOrFail($id);
    }

    /** @return array<string, mixed> представление для фронта */
    public function present(SavedPassenger $passenger): array
    {
        return [
            'id' => $passenger->id,
            'last_name' => $passenger->last_name,
            'first_name' => $passenger->first_name,
            'middle_name' => $passenger->middle_name,
            'full_name' => $passenger->fullName(),
            'birth_date' => $passenger->birth_date?->format('Y-m-d'),
            'gender' => $passenger->gender,
            'doc_type' => $passenger->doc_type,
            'doc_series' => $passenger->doc_series,
            'doc_number' => $passenger->doc_number,
            // Форма покупки принимает документ одной строкой.
            'document' => $passenger->documentLine(),
            'citizenship' => $passenger->citizenship,
            'last_used_at' => $passenger->last_used_at?->toIso8601String(),
        ];
    }
}
