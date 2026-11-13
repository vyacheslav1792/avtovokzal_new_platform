# Сертификат платёжного шлюза

`russian_trusted_root_ca.pem` — Russian Trusted Root CA, скачан по HTTPS
с официального CDN Госуслуг:
https://gu-st.ru/content/lending/russian_trusted_root_ca_pem.crt

SHA-256: `D26D2D0231B7C39F92CC738512BA54103519E4405D68B5BD703E9788CA8ECF31`.
Действует до 27 февраля 2032 года. Используется только HTTP-клиентом
эквайринга Сбера; системное хранилище доверия не меняется.

Для другого набора CA можно задать `SBERBANK_CA_BUNDLE` в `backend/.env`.
