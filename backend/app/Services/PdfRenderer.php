<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Рендер PDF из готовой HTML-страницы через Gotenberg (Chromium).
 *
 * Тот же приём, что в legacy `pdf.php`: рендерим не отдельный шаблон, а саму
 * печатную форму (`/print/{id}`) — вёрстка не расходится с тем, что пользователь
 * видит при печати из браузера. Место wkhtmltopdf.exe занял Gotenberg.
 */
class PdfRenderer
{
    /**
     * @param  string  $url  адрес страницы во ВНУТРЕННЕЙ сети (Gotenberg ходит сам)
     * @return string тело PDF
     */
    public function fromUrl(string $url): string
    {
        $response = Http::timeout(config('services.gotenberg.timeout'))
            ->asMultipart()
            ->post(rtrim(config('services.gotenberg.url'), '/').'/forms/chromium/convert/url', [
                'url' => $url,
                // Размер страницы и поля берём из @page самой формы (A4, margin 0),
                // чтобы PDF совпал с печатью из браузера.
                'preferCssPageSize' => 'true',
                'printBackground' => 'true',
                'emulatedMediaType' => 'print',
                // Nuxt отдаёт форму уже с сервера, задержка — запас на гидратацию.
                'waitDelay' => '1s',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Gotenberg вернул '.$response->status().': '.mb_substr($response->body(), 0, 500),
            );
        }

        return $response->body();
    }
}
