/*
 * ТАРИФЫ ОТ ПРОМЕЖУТОЧНОЙ ОСТАНОВКИ — заполнение по трассе рейса.
 *
 * Запускать в базе СТАНЦИИ (Firebird, isql), подставив четыре значения:
 *   :AWT        — код станции (AWTCOD), например 5701;
 *   :NAP        — направление (NAPCOD), например 20 «Ливны»;
 *   :KAT        — действующая тарифная категория (EVRYRAICE.NOMKAT, он же
 *                 RAICELST.NEWNOMKAT при прошедшей CHANDATE), например 88;
 *   :FROM_STAT  — код остановки отправления в TRACES этого направления
 *                 (у «Дросково КП» в направлении «Ливны» это 12, а в
 *                 направлении «Орел» базы Ливен — 8: коды у остановки
 *                 РАЗНЫЕ в разных направлениях).
 *
 * Пример (Орёл, направление «Ливны», от Дросково):
 *   sed 's/:FROM_STAT/12/g; s/:AWT/5701/; s/:NAP/20/; s/:KAT/88/' \
 *     docs/sql/boarding-point-tariffs.sql | isql -user SYSDBA -password ... <база>
 *
 * ПЕРЕД запуском посмотрите расчёт тем же join'ом без INSERT и сверьте с
 * тем, что станция уже завела вручную. На тестовой базе Ливен так нашлась
 * ошибка: «Дросково КП → Орел АВ» стояло 387 ₽ при расчётных 487 ₽ — до
 * конечной выходило дешевле, чем до предыдущей остановки (482 ₽).
 *
 * ПОСЛЕ заполнения тарифов продажа ещё не откроется: в расписании
 * остановок рейса должна быть разрешена посадка — TRACETIME.SET_DISABLED
 * = 0 и заполненный TIME_OF_STOP для этой остановки в КАЖДОМ расписании
 * (traicetimecod), которым ходят рейсы. Разбор — docs/BOARDING_POINTS.md.
 */

/*
 * Тарифы ОТ ОСТАНОВКИ на трассе рейса (TARIFS2D.STARTSTATCODE).
 *
 * Цена считается РАЗНОСТЬЮ тарифов от автостанции: билет «Орёл → Ливны»
 * (669) минус «Орёл → Дросково» (487) даёт ровно 182 — именно столько
 * станция завела для «Дросково → Ливны» вручную. То же по детскому,
 * льготному и багажу. Разность предпочтительнее расчёта по ставке
 * (≈4,5 ₽/км): сумма двух билетов через остановку совпадает с одним
 * сквозным, то есть на округлении никто не теряет.
 *
 * Существующие строки НЕ перезаписываются: цену могли завести осознанно.
 */
insert into tarifs2d (awtcode, napcode, katcode, prim, startstatcode, statcode,
    paysoftall, payhardall, paysoftlgt, payhardlgt, paysoftchd, payhardchd,
    paysoftbag, payhardbag, payusl, obstr, dobrstr)
select t.awtcode, t.napcode, t.katcode, 1, :FROM_STAT, t.statcode,
       t.paysoftall - b.paysoftall, t.payhardall - b.payhardall,
       t.paysoftlgt - b.paysoftlgt, t.payhardlgt - b.payhardlgt,
       t.paysoftchd - b.paysoftchd, t.payhardchd - b.payhardchd,
       t.paysoftbag - b.paysoftbag, t.payhardbag - b.payhardbag,
       /* Услуги и страховка от расстояния не зависят — берём как есть. */
       t.payusl, t.obstr, t.dobrstr
  from tarifs2d t
  join tarifs2d b on b.awtcode = t.awtcode and b.napcode = t.napcode and b.katcode = t.katcode
       and b.prim = 1 and b.startstatcode = 0 and b.statcode = :FROM_STAT
  join traces trt on trt.awtcod = t.awtcode and trt.napcod = t.napcode
       and trt.statcod = t.statcode and trt.isdel = 0
  join traces trf on trf.awtcod = t.awtcode and trf.napcod = t.napcode
       and trf.statcod = :FROM_STAT and trf.isdel = 0
 where t.awtcode = :AWT and t.napcode = :NAP and t.katcode = :KAT
   and t.prim = 1 and t.startstatcode = 0
   /* Только вперёд по трассе и только если цена больше нуля. */
   and trt.kilom > trf.kilom
   and t.payhardall > b.payhardall
   and not exists (select 1 from tarifs2d x
                    where x.awtcode = t.awtcode and x.napcode = t.napcode
                      and x.katcode = t.katcode and x.prim = 1
                      and x.startstatcode = :FROM_STAT and x.statcode = t.statcode);
commit;
