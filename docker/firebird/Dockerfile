# Firebird 2.5 для разработки. Тот же образ, но с нашими алиасами баз ВНУТРИ
# скелета конфигов.
#
# ПОЧЕМУ НЕ bind mount aliases.conf: entrypoint образа раскладывает конфиги в
# /firebird/etc только если каталога ещё нет (`if [[ ! -e "${VOLUME}/etc/" ]]`),
# а монтирование одного файла создаёт этот каталог заранее. Пока контейнер жил с
# первого запуска, всё работало; как только его пересоздавали — в /firebird/etc
# оказывался единственный aliases.conf, без firebird.conf и SYSDBA.password, и
# контейнер падал: «/firebird/etc/SYSDBA.password: No such file or directory»,
# дальше «unable to open database» и выход с кодом 0.
#
# Кладём aliases.conf прямо в скелет: при создании контейнера он копируется
# вместе с остальными конфигами, состояние на хосте держать не нужно.
FROM jacobalberty/firebird:2.5-ss

COPY aliases.conf /usr/local/firebird/skel/etc/aliases.conf
