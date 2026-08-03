declare variable Atp_Cod smallint; /* код АТП */
declare variable Martyp_Cod smallint;
declare variable Raicetyp_Cod smallint;
declare variable Last_N_Tick_9 varchar(9); /* последний оформленный № билета */
declare variable N_Ticket_9 varchar(9); /* № билета */
declare variable N_Ticket_12 varchar(12); /* серия билета */
declare variable Ii smallint = 1; /* служебный счетчик для цикла */
declare variable V_Withmoney smallint;
declare variable V_Nullmoney numeric(15,2);
declare variable V_Nullinteger smallint;
declare variable V_Strtmp varchar(300);
declare variable V_Documcod smallint;
declare variable V_Fio_Pas varchar(20);
declare variable V_Name1 varchar(14);
declare variable V_Name2 varchar(14);
declare variable V_Pasport_Ser varchar(10);
declare variable V_Pasport_N varchar(25);
declare variable V_Birth_Day date;
declare variable V_Birth_Place varchar(300);
declare variable V_Pasp_Vydan_Registr varchar(300);
declare variable V_N_Group_Ps smallint;
declare variable I_Count_Pl smallint;
declare variable V_Uni_N_Tick varchar(12);
declare variable Pin_Pad_Err smallint;
begin
/*
   attention = 1  можно купить, но нельзя заказать билеты: срок выкупа истек

   err_code = 1  нет продажи/заказа билетов на рейс
   err_code = 2  нет продажи/заказа: рейс транзитный
   err_code = 3  на указанный рейс продаются билеты указания без мест

   err_code = 4   Нельзя заказать билеты: срок заказа истек
   err_code = 5   место оформить нельзя: превышено время ожидания продажи/заказа билетов или место не доступно для оформления

   err_code = 10  нет данных. Возврат по списку билетов не выполнен
   err_code = 11  место выкуплено, аннулирование не выполнено;
   err_code = 12  заказ уже аннулирован
   err_code = 13  не задан № заказа  или не задана дата отправления
*/
  err_code = 0;
  comment_txt = '';
  ii = 0;
  v_nullmoney = 0;
  v_nullinteger = 0;
  v_withmoney = 1;
  Pin_Pad_Err = null;
  s_tickets = '';
  s_uni_n_tick = '';

  if (col_bag <= 0) then
    begin
      suspend;
      exit;
    end

  if (Char_Length(n_marsh_kvit) = 20) then v_n_group_ps = cast(Substring(n_marsh_kvit From 4 For 1) as smallint);
  else v_n_group_ps = 0;
  if (v_n_group_ps < 3) then v_n_group_ps = 0;
--********************************************************************************
-- v_n_group_ps - группа платежной системы: 0-электронный платеж, возврат только по безналу
--                                          3-наличные деньги, возврат в кассах автовокзала
  if (v_n_group_ps > 0) then v_withmoney = 1;
  else
    begin
      v_withmoney = 0;
      Pin_Pad_Err = 0;
    End
--********************************************************************************
  select evryraice.atpcod,
         evryraice.martypcod,
         evryraice.raicetypcod
    from evryraice
   where ((evryraice.datestart = :date_start) and (evryraice.awtcod = :awt_code)
     and (evryraice.napcod = :nap_code) and (evryraice.raicecod = :mar_code))
    into :atp_cod,
         :martyp_cod,
         :raicetyp_cod;

-- сформировать серию билета - 12-ти значный номер
  n_ticket_12 = extract(year from current_date);
  n_ticket_12 = cast(Substring(n_ticket_12 From 3 For 2) as varchar(12));
  n_ticket_12 = extract(month from current_date) || n_ticket_12 || :awt_code || agent_code;
  WHILE (12 > Char_Length(n_ticket_12)) DO n_ticket_12 = '0' || n_ticket_12;

-- сформировать номер билета - 9-ти значный номер
  select max(sale.n_ticket)  -- найти последний номер билета для кассира
  from sale
  where sale.datesale = current_date
    and sale.awtcodsale = :agent_code
    and sale.wrkcod = :agent_wrk
    and sale.type_oper = 0
    and sale.awtcod = :awt_code
  into :last_n_tick_9;

  if (last_n_tick_9 is null) then  -- если ничего не найдено, то считать последним № = ddccc0000
    begin
      last_n_tick_9 = agent_wrk ||  '0000';
      last_n_tick_9 = extract(day from current_date) || last_n_tick_9;
      WHILE (9 > Char_Length(last_n_tick_9)) DO last_n_tick_9 = '0' || last_n_tick_9;
    end
  n_ticket_9 = last_n_tick_9;
-- начинаем продавать багажные билеты в соответствии со сформированными списками данных

-- определить код документа пассажира  ***********************
  select param_smallint, string_out
    from sh_fun_from_str_2_smallint(:s_docum_cod,'@')
    into :v_documcod, :v_strtmp;
  s_docum_cod = v_strtmp;
  if (v_documcod = 0) then v_documcod = 21;  -- код документа: по умолчанию - паспорт
--*** определить фамилию пассажира ***********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_fio_pas,'@')
    into :v_fio_pas, :v_strtmp;
  s_fio_pas  = v_strtmp;
--*** определить имя пассажира ***********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_name1,'@')
    into :v_name1, :v_strtmp;
  s_name1 = v_strtmp;
--*** определить отчество пассажира ***********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_name2,'@')
    into :v_name2, :v_strtmp;
  s_name2 = v_strtmp;
--*** определить серию паспорта пассажира ***********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_pasport_ser,'@')
    into :v_pasport_ser, :v_strtmp;
  s_pasport_ser = v_strtmp;
--*** определить № паспорта пассажира ***********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_pasport_n,'@')
    into :v_pasport_n, :v_strtmp;
  s_pasport_n = v_strtmp;
--*** определить дату рождения пассажира ***********************
  select param_date, string_out
    from sh_fun_from_str_2_date(:s_birth_day,'@')
    into :v_birth_day, :v_strtmp;
  s_birth_day = v_strtmp;
--*** определить место рождения пассажира ***********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_birth_place,'@')
    into :v_birth_place, :v_strtmp;
  s_birth_place = v_strtmp;
--*** определить дату выдачи и место регистрации пассажира *********************
  select param_string, string_out
    from sh_fun_from_str_2_substring(:s_pasp_vydan_registr,'@')
    into :v_pasp_vydan_registr, :v_strtmp;
  s_pasp_vydan_registr = v_strtmp;
--*** определить категорию билета: 9 - багаж ***********************
  while (ii < col_bag) do
    begin

      n_ticket_9 = cast(n_ticket_9 as integer)+1;
      if (9 > Char_Length(n_ticket_9)) then n_ticket_9 = '0' || n_ticket_9;

      execute procedure sh0_insert_to_sale(:agent_code,agent_wrk, 0,:n_ticket_9,:n_ticket_12,
        :date_start,:awt_code,:nap_code,:mar_code,0,9,:start_station_cod,
        :station_cod,:v_nullmoney,:price_bag,:v_nullmoney,:v_nullmoney,:v_nullmoney,:v_nullmoney,:v_nullmoney,
        :v_nullmoney,:v_nullmoney,:v_nullmoney,:v_nullmoney,:v_nullmoney,:price_bag,
        :v_nullinteger,'','',
        :nap_code,:mar_code,:atp_cod,
        :v_nullinteger,1,:v_nullinteger,:v_withmoney,:v_fio_pas,:v_name1,:v_name2,
        :v_documcod,:v_pasp_vydan_registr,:v_pasport_n,:v_pasport_ser,:v_birth_day,:v_birth_place,
        :v_nullmoney,:price_bag,:v_nullinteger,777,:martyp_cod,:raicetyp_cod,0,0,0,0,'','','',0,0,:Pin_Pad_Err);
      select ksr_select_uni_n_tick.uni_n_tick
        from ksr_select_uni_n_tick(:awt_code,:n_ticket_9,:n_ticket_12)
        into :v_uni_n_tick;

--** записать данные о маршрутной квитанции в протокол по оформлению маршрутных квитанций - аналог sale **
      if (Char_Length(n_marsh_kvit) = 20) then
        begin
          insert into marsh_kvit (n_markvi,  n_tick_9,      n_tick_12,    status,
                                  cod_rc,    datetime_oper, markvi_back,  group_ps)
          values (:n_marsh_kvit, :n_ticket_9,  :n_ticket_12,
                  0,       -- статус квитанции:0 - покупка билетов;1 - разрешен возврат билетов;2 - возврат билетов;...
                  :agent_code,   current_timestamp,
                  0,              -- 1-был выполнен возврат 0- билеты не возвращались
                  :v_n_group_ps); -- № группы платежной системы - 4-ая цифра в № маршрутной квитанции
        end
      if (9 = Char_Length(n_ticket_9)) then i_count_pl = i_count_pl + 1;
      s_tickets = s_tickets || '0@Б@' || n_ticket_12 || '@'|| n_ticket_9 || '@';
      s_uni_n_tick = s_uni_n_tick || v_uni_n_tick || '@';
      ii = ii + 1;
    end
  suspend;
end