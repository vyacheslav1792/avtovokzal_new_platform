<?php

// Настройки, связанные с сайтом avtovokzal57 (новый стек).
return [
    // База фронта сайта — для ссылок «Печать» из отчётов (/print/{order}).
    'frontend_url' => rtrim(env('SITE_FRONTEND_URL', 'http://localhost:3000'), '/'),
];
