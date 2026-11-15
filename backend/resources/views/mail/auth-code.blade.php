{{--
    Письмо с кодом подтверждения. Своя разметка, а не markdown-шаблон
    `mail::message`: в том шапка и подвал ведут на сайт ссылками, а здесь
    ссылок не должно быть ни одной — именно из-за них письма попадают
    в спам. По той же причине нет подписи `mail.partials.signature`
    (в ней mailto поддержки) — контакт оставлен телефоном.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#f5f5f5; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:520px; background-color:#ffffff; border-radius:8px;
                          border:1px solid #e5e5e5; font-family:Arial, Helvetica, sans-serif;
                          color:#1f1f1f;">
                <tr>
                    <td style="padding:24px 24px 8px 24px; font-size:18px; font-weight:bold;
                               color:#d0021b;">
                        {{ config('site.brand') }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px; font-size:20px; font-weight:bold; line-height:1.3;">
                        {{ $heading }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 24px 0 24px; font-size:15px; line-height:1.5;">
                        {{ $intro }}
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:20px 24px;">
                        {{-- Код моноширинным и с разрядкой: так его проще
                             перенести руками, в том числе с телефона. --}}
                        <div style="display:inline-block; padding:14px 24px; background-color:#f7f7f7;
                                    border:1px solid #e0e0e0; border-radius:8px;
                                    font-family:'Courier New', Courier, monospace;
                                    font-size:32px; font-weight:bold; letter-spacing:6px;
                                    color:#1f1f1f;">
                            {{ $code }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px; font-size:14px; line-height:1.5; color:#555555;">
                        Код действует {{ $ttlMinutes }} минут. Никому его не сообщайте:
                        он даёт доступ к личному кабинету.
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px 0 24px; font-size:14px; line-height:1.5;
                               color:#555555;">
                        {{ $ignore }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 24px 24px 24px; font-size:13px; line-height:1.5;
                               color:#777777; border-top:1px solid #eeeeee;">
                        Вопросы по билетам — справочная {{ config('site.support_phone') }}
                        или сотрудники автовокзала.<br>
                        {{ config('site.company') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
