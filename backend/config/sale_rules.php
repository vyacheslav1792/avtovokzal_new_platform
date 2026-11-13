<?php

return [

    /*
     * Детский тариф — только вместе с полным билетом.
     *
     * Перенос правила legacy: проверка стояла и на форме
     * (`avtovokzal57/js/passengers.js`: `adultCount < 1 || childCount / adultCount > 2`),
     * и на сервере (`url/passengers.php`: `if (!$full || ($child / $full) > 2)`).
     *
     * Правило станции, а не закон, поэтому выключается переменными окружения:
     * `CHILD_TARIFF_REQUIRES_ADULT=false` снимает ограничение целиком,
     * `MAX_CHILDREN_PER_ADULT` меняет норму детей на одного взрослого.
     */
    'child_requires_adult' => (bool) env('CHILD_TARIFF_REQUIRES_ADULT', true),

    'max_children_per_adult' => (int) env('MAX_CHILDREN_PER_ADULT', 2),

    /*
     * Текст отказа — слово в слово из legacy (`js/passengers.js`), чтобы
     * покупатель видел ту же формулировку, что и на старом сайте.
     */
    'child_requires_adult_message' => 'Детские билеты приобретаются только в связке с полным билетом. '
        .'Пассажир имеет право перевезти с собой 2х детей до 12 лет по детскому тарифу. '
        .'Для ребёнка без сопровождения взрослого необходимо приобрести билет по полному тарифу.',

];
