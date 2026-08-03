declare variable Atp_Cod smallint; /* TICKETS_FALSE */
declare variable Martyp_Cod smallint;
declare variable Raicetyp_Cod smallint;
declare variable Curr_N_Pl smallint; /* текущий №  места */
declare variable V_Price_Pl numeric(15,2); /* цена текущего места */
declare variable V_Strah_Pl numeric(15,2); /* обязательная страховка */
declare variable Last_N_Tick_9 varchar(9); /* последний оформленный № билета */
declare variable N_Ticket_9 varchar(9); /* № билета */
declare variable N_Ticket_12 varchar(12); /* серия билета */
declare variable V_Uni_N_Tick varchar(12); /* универсальный № билета */
declare variable Ii smallint = 1; /* служебный счетчик для цикла */
declare variable Jj smallint = 1; /* служебный счетчик для цикла */
declare variable V_Start_Stat smallint;
declare variable V_Price_Bag numeric(15,2);
declare variable V_Withmoney smallint;
declare variable V_Typtick smallint;
declare variable V_Nullmoney numeric(15,2);
declare variable V_Nullinteger smallint;
declare variable V_Strtmp varchar(300);
declare variable V_Strtmp_S varchar(300);
declare variable V_Katpas smallint;
declare variable V_Kat_Str varchar(2);
declare variable V_Documcod smallint;
declare variable V_Fio_Pas varchar(20);
declare variable V_Name1 varchar(14);
declare variable V_Name2 varchar(14);
declare variable V_Pol_Pas varchar(2);
declare variable V_Visa varchar(10);
declare variable V_Pasport_Ser varchar(12);
declare variable V_Pasport_N varchar(25);
declare variable V_Birth_Day date;
declare variable V_Birth_Place varchar(300);
declare variable V_Pasp_Vydan_Registr varchar(300);
declare variable V_N_Pl_With_Child smallint;
declare variable V_N_Group_Ps smallint;
declare variable V_Lgot_Code smallint;
declare variable V_User_Lgot_Code smallint;
declare variable V_Lgotdoc_Ser varchar(10);
declare variable V_Lgotdoc_N varchar(10);
declare variable I_Count_Pl smallint;
declare variable Bag_Tickets varchar(1000);
declare variable Bag_Uni_Tickets varchar(1000);
declare variable V_Gragd_Id smallint;
declare variable V_N_Tick_4 smallint;
declare variable V_Comment_Pas varchar(20);
declare variable Com_Bn numeric(15,2);
declare variable Host_Code smallint;
declare variable Reg_Type smallint;
declare variable Raice_Available smallint;
declare variable Raice_Gone smallint;
declare variable Pin_Pad_Err smallint;
declare variable Cod_Strcomp smallint;
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
/*
  Строка с данными о детях до 5-ти лет передается в формате №места@КодДок@Фамилия@Имя@Отч-во@СерияДок@№Док@ДатаРожд@
  если оформляются билеты, например, для 3-х пассажиров и двоих детей, то строка будет:
  №места@КодДок@Фамилия@Имя@Отч-во@СерияДок@№Док@ДатаРожд@№места@КодДок@Фамилия@Имя@Отч-во@СерияДок@№Док@ДатаРожд@
  тогда для первого и второго пассажира будут оформлены билеты еще и на ребенка, паспортные данные на которых будут определены по этой записи
*/
  err_code = 0;
  comment_txt = '';
--******************************************************************************
  select result_code, result_comment
  from Ks_Control_Data_Pas(:S_Gragd_Id,:S_Docum_Cod,:S_Pasport_Ser,:S_Pasport_N)
  into :err_code, :comment_txt;
  if (err_code > 0) then
    begin
      comment_txt = 'место оформить нельзя: ' || comment_txt;
      suspend;
      exit;
    end
--******************************************************************************
  Cod_Strcomp = 0;
  if (Strax_Sbor > 0) then
    select first 1 Strcomplst.Strcompcod from Strcomplst
        where Strcomplst.Isdel = 0
    order by Strcomplst.N_Pp
    Into :Cod_Strcomp;
  v_start_stat = 0;
  ii = 1;
  jj = 1;
  v_price_pl = 0;
  v_price_bag = 0;
  v_nullmoney = 0;
  v_nullinteger = 0;
  v_withmoney = 1;
  Pin_Pad_Err = null;
  s_tickets = '';
  s_uni_n_tick = '';
  tickets_false = '';
  v_visa = '';
  Com_BN = 0;
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
         evryraice.raicetypcod,
         evryraice.Available,
         evryraice.Gone
    from evryraice
   where ((evryraice.datestart = :date_start) and (evryraice.awtcod = :awt_code)
     and (evryraice.napcod = :nap_code) and (evryraice.raicecod = :mar_code))
    into :atp_cod,
         :martyp_cod,
         :raicetyp_cod,
         :Raice_Available,
         :Raice_Gone;
  If ((Raice_Available = 0) or (Raice_Gone = 1)) then
    begin
      err_code = 1;
      comment_txt = 'место оформить нельзя: Нет продажи билетов на рейс.';
      suspend;
      exit;
    end


-- сформировать серию билета - 12-ти значный номер
  n_ticket_12 = extract(year from current_date);
  n_ticket_12 = cast(Substring(n_ticket_12 From 3 For 2) as varchar(12));
  n_ticket_12 = extract(month from current_date) || n_ticket_12 || :awt_code || agent_code;
  WHILE (12 > Char_Length(n_ticket_12)) DO n_ticket_12 = '0' || n_ticket_12;

-- сформировать номер билета - 9-ти значный номер
  last_n_tick_9 = agent_wrk;
  WHILE (3 > Char_Length(last_n_tick_9)) DO last_n_tick_9 = '0' || last_n_tick_9;
  last_n_tick_9 = extract(day from current_date) || last_n_tick_9;
  WHILE (5 > Char_Length(last_n_tick_9)) DO last_n_tick_9 = '0' || last_n_tick_9;

/*
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
      WHILE (9 > "strlen"(last_n_tick_9)) DO last_n_tick_9 = '0' || last_n_tick_9;
    end
  n_ticket_9 = last_n_tick_9;
*/
-- начинаем продавать места в соответствии со сформированными списками данных
  v_n_pl_with_child = 0;

--******************************************************************************
  while (ii <= Char_Length(nn_places)) do
    begin
      if ('@' = Substring(nn_places From ii For 1)) then
        begin
          curr_n_pl = cast(Substring(nn_places From jj For (ii - jj)) as smallint);
          if (exists(select saloninfo.n_place
                    from saloninfo
                    where saloninfo.datestart =:date_start
                      and saloninfo.awtcod =:awt_code
                      and saloninfo.napcod =:nap_code
                      and saloninfo.raicecod =:mar_code
                      and saloninfo.n_place =:curr_n_pl
                      and saloninfo.takdir = 0
                      and saloninfo.zkz = 0
                      and saloninfo.awtsalecod = :agent_code
                      and saloninfo.wrkcod = :agent_wrk
                      and ((not saloninfo.timemark is null and
                      (cast(saloninfo.timemark as varchar(25))=:id_time_places))
--                      (saloninfo.timemark = :id_time_places))
                      and  (saloninfo.timemark >= dateadd(minute, -30,Current_Timestamp)))
                   )) then
            begin
              select saloninfo.hostcod
              from saloninfo
              where saloninfo.datestart =:date_start
                and saloninfo.awtcod =:awt_code
                and saloninfo.napcod =:nap_code
                and saloninfo.raicecod =:mar_code
                and saloninfo.n_place =:curr_n_pl
              into :host_code;
              if (host_code is null) then host_code = 0;

-- определить код остановки отправления пассажира  ***********************
              select param_smallint, string_out
              from sh_fun_from_str_2_smallint(:s_start_station,'@')
              into :v_start_stat, :v_strtmp;
              s_start_station = v_strtmp;

    -- определить код документа пассажира  ***********************
              select param_smallint, string_out
              from sh_fun_from_str_2_smallint(:s_docum_cod,'@')
              into :v_documcod, :v_strtmp;
              s_docum_cod = v_strtmp;
              if (v_documcod = 0) then v_documcod = 21;  -- код документа: по умолчанию - паспорт
    --*** определить фамилию пассажира ***********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_fio_pas,'@')
              into :V_Strtmp_S, :v_strtmp;
              if (Char_Length(V_Strtmp_S) > 20) then v_fio_pas = Substring(V_Strtmp_S From 1 For 20);
              else v_fio_pas = V_Strtmp_S;
--              into :v_fio_pas, :v_strtmp;
              s_fio_pas  = v_strtmp;
    --*** определить имя пассажира ***********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_name1,'@')
              into :V_Strtmp_S, :v_strtmp;
              if (Char_Length(V_Strtmp_S) > 14) then V_Name1 = Substring(V_Strtmp_S From 1 For 14);
              else V_Name1 = V_Strtmp_S;
--              into :v_name1, :v_strtmp;
              s_name1 = v_strtmp;
    --*** определить отчество пассажира ***********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_name2,'@')
              into :V_Strtmp_S, :v_strtmp;
              if (Char_Length(V_Strtmp_S) > 14) then V_Name2 = Substring(V_Strtmp_S From 1 For 14);
              else V_Name2 = V_Strtmp_S;
--              into :v_name2, :v_strtmp;
              s_name2 = v_strtmp;
    --*** определить пол пассажира *********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_pol_pass,'@')
              into :v_pol_pas, :v_strtmp;
              s_pol_pass = v_strtmp;
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
    --*** определить категорию билета: полный - детский ***********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_kat_pricepl,'@')
              into :v_kat_str, :v_strtmp;
              s_kat_pricepl = v_strtmp;
              if (v_kat_str = 'Д') then v_katpas = 2;
              else if (v_kat_str = 'Л') then v_katpas = 3;
                   else v_katpas = 0;
    --*** определить стоимость билета ***********************
              select param_num, string_out
              from sh_fun_from_str_2_numeric(:s_kat_pricepl,'@')
              into :v_price_pl, :v_strtmp;
              s_kat_pricepl = v_strtmp;
    --***********************************
              v_typtick = 0;  -- 0 - тип билета - обычный (не багажный, не проездной ...)
          if (host_code > 0) then v_typtick = 13;

          select gen_n_ticket.n_ticket
          from gen_n_ticket(:agent_code,:agent_wrk)
          into :V_N_tick_4;
          n_ticket_9 = '' || V_N_tick_4;
          WHILE (4 > Char_Length(n_ticket_9)) DO n_ticket_9 = '0' || n_ticket_9;
          n_ticket_9 =  last_n_tick_9 || n_ticket_9;

          v_strah_pl = 0;
--              if (strax_sbor > v_price_pl) then v_strah_pl = 0;
--              else v_strah_pl = strax_sbor;
-- определить код льготы пассажира  ***********************
              select param_smallint, string_out
              from sh_fun_from_str_2_smallint(:s_cod_lgot,'@')
              into :v_user_lgot_code, :v_strtmp;
              s_cod_lgot = v_strtmp;
              if (v_user_lgot_code = 0) then v_lgot_code = 0;  -- код документа: по умолчанию - паспорт
              else select lgtlst.lgtcod
                   from lgtlst
                   where lgtlst.usercode =:v_user_lgot_code
                   into:v_lgot_code;
-- определить ID гражданства пассажира  ***********************
              select param_smallint, string_out
              from sh_fun_from_str_2_smallint(:s_gragd_id,'@')
              into :v_gragd_id, :v_strtmp;
              s_gragd_id = v_strtmp;
--*** определить серию документа на льготу пассажира ***********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_lgotdoc_ser,'@')
              into :v_lgotdoc_ser, :v_strtmp;
              s_lgotdoc_ser = v_strtmp;
--*** определить № документа на льготу пассажира ***********************
              select param_string, string_out
              from sh_fun_from_str_2_substring(:s_lgotdoc_n,'@')
              into :v_lgotdoc_n, :v_strtmp;
              s_lgotdoc_n = v_strtmp;
--*** коментарий о пассажире: № телефона, доп.информация ...
              if (s_comment_pas is null) then v_comment_pas = '';
              else 
                begin
                  select param_string, string_out
                  from sh_fun_from_str_2_substring(:s_comment_pas,'@')
                  into :v_comment_pas, :v_strtmp;
                  s_comment_pas = v_strtmp;
                end
              reg_type = null;
              if (host_code > 0) then
                begin
                  if (host_code = agent_code) then reg_type = 2;
                  else
                    if (date_start <> current_date) then reg_type = 3;
                    else reg_type = 4;
                end
              execute procedure ks_insert_to_sale(:agent_code,agent_wrk, 0,:n_ticket_9,:n_ticket_12,
                :date_start,:awt_code,:nap_code,:mar_code,:curr_n_pl,:v_katpas,:v_start_stat,
    /**/        :station_cod,:v_price_pl,:v_price_bag,:com_sbor,:v_strah_pl,:Strax_Sbor,:v_nullmoney,:v_nullmoney,
                :v_nullmoney,:v_nullmoney,:v_nullmoney,:v_nullmoney,:v_price_pl,:v_price_bag,
                :v_lgot_code,:v_lgotdoc_ser,:v_lgotdoc_n,
                :nap_code,:mar_code,:atp_cod,
                :v_nullinteger,:v_typtick,:v_nullinteger,:v_withmoney,:v_fio_pas,:v_name1,:v_name2,
                :v_documcod,:v_pasp_vydan_registr,:v_pasport_n,:v_pasport_ser,:v_birth_day,:v_birth_place,
                :v_price_pl,:v_price_bag,:v_nullinteger,777,:martyp_cod,:raicetyp_cod,
      /**/      :Cod_StrComp,0,0,0,:v_pol_pas,v_comment_pas,'',:v_gragd_id, :date_start, :v_visa, :Com_BN, :reg_type,0, :pin_pad_err);
              select ksr_select_uni_n_tick.uni_n_tick
                from ksr_select_uni_n_tick(:awt_code,:n_ticket_9,:n_ticket_12)
                into :v_uni_n_tick;

              if (s_children <> '') then
                begin  -- s_children = N_PL@CodDoc@Family@Name1@Name2@SerDoc@NDoc@BirthDay@
                  if (v_n_pl_with_child = 0) then
                    begin
                      select param_smallint, string_out
                      from sh_fun_from_str_2_smallint(:s_children,'@')
                      into :v_n_pl_with_child, :v_strtmp;
                      s_children = v_strtmp;
                    end

                  if (v_n_pl_with_child = curr_n_pl) then
                    begin
    -- определить код документа пассажира  ***********************
                      select param_smallint, string_out
                      from sh_fun_from_str_2_smallint(:s_children,'@')
                      into :v_documcod, :v_strtmp;
                      s_children = v_strtmp;
                      if (v_documcod = 0) then v_documcod = 21;  -- код документа: по умолчанию - паспорт
        --*** определить фамилию пассажира ***********************
                      select param_string, string_out
                      from sh_fun_from_str_2_substring(:s_children,'@')
                      into :v_fio_pas, :v_strtmp;
                      s_children  = v_strtmp;
        --*** определить имя пассажира ***********************
                      select param_string, string_out
                      from sh_fun_from_str_2_substring(:s_children,'@')
                      into :v_name1, :v_strtmp;
                      s_children = v_strtmp;
        --*** определить отчество пассажира ***********************
                      select param_string, string_out
                      from sh_fun_from_str_2_substring(:s_children,'@')
                      into :v_name2, :v_strtmp;
                      s_children = v_strtmp;
        --*** определить серию паспорта пассажира ***********************
                      select param_string, string_out
                      from sh_fun_from_str_2_substring(:s_children,'@')
                      into :v_pasport_ser, :v_strtmp;
                      s_children = v_strtmp;
        --*** определить № паспорта пассажира ***********************
                      select param_string, string_out
                      from sh_fun_from_str_2_substring(:s_children,'@')
                      into :v_pasport_n, :v_strtmp;
                      s_children = v_strtmp;
        --*** определить дату рождения пассажира ***********************
                      select param_date, string_out
                      from sh_fun_from_str_2_date(:s_children,'@')
                      into :v_birth_day, :v_strtmp;
                      s_children = v_strtmp;
        
                      execute procedure sh0_sale_child_insert (:n_ticket_9,:n_ticket_12,
                              :v_documcod, :v_pasport_ser, :v_pasport_n, :v_birth_day, :v_fio_pas, :v_name1, v_name2);
                      v_n_pl_with_child = 0;
                    end
                end
--** записать данные о маршрутной квитанции в протокол по оформлению маршрутных квитанций - аналог sale **
              if (Char_Length(n_marsh_kvit) = 20) then
                begin
                  insert into marsh_kvit (n_markvi,
                                          n_tick_9,
                                          n_tick_12,
                                          status,
                                          cod_rc,
                                          datetime_oper,
                                          markvi_back,
                                          group_ps)
                  values (:n_marsh_kvit,
                          :n_ticket_9,
                          :n_ticket_12,
                          0,       -- статус квитанции:0 - покупка билетов;1 - разрешен возврат билетов;2 - возврат билетов;...
                          :agent_code,
                          current_timestamp,
                          0,              -- 1-был выполнен возврат 0- билеты не возвращались
                          :v_n_group_ps); -- № группы платежной системы - 4-ая цифра в № маршрутной квитанции
                end
              if (9 = Char_Length(n_ticket_9)) then i_count_pl = i_count_pl + 1;

              s_tickets = s_tickets || curr_n_pl || '@' || v_kat_str || '@' || n_ticket_12 || '@'|| n_ticket_9 || '@';
              s_uni_n_tick = s_uni_n_tick || v_uni_n_tick || '@';
            end
          else
            begin
              err_code = 5;
              comment_txt = 'место оформить нельзя: превышено время ожидания продажи/заказа билетов или место не доступно для оформления';
              tickets_false = tickets_false || curr_n_pl || '@';
            end
          ii = ii + 1;
          jj = ii;
        end
      ii = ii + 1;
    end
-- оформить багажные билеты
  if (col_bags > 0) then
    begin
      select sh0_sale_bag_on_raice.s_tickets,
             sh0_sale_bag_on_raice.s_uni_n_tick
        from sh0_sale_bag_on_raice(:col_bags,:awt_code, :date_start, :nap_code, :mar_code,
               :v_start_stat, :station_cod,
               :s_fio_pas, :s_name1, :s_name2, :s_pasport_ser, :s_pasport_n, :s_birth_day, :s_birth_place,
               :s_pasp_vydan_registr, :s_docum_cod,
               :agent_code, :agent_wrk, :n_marsh_kvit, :price_bag_1)
        into :bag_tickets,
             :bag_uni_tickets;
      s_tickets = s_tickets || bag_tickets;
      s_uni_n_tick = s_uni_n_tick || bag_uni_tickets;
    end
-- определить параметры для печати билетов
  select ks_par_for_curr_tickets.strco_name,
         ks_par_for_curr_tickets.strco_location,
         ks_par_for_curr_tickets.strco_mailaddress,
         ks_par_for_curr_tickets.strco_telephone,
         ks_par_for_curr_tickets.strco_dogovor_nom,
         ks_par_for_curr_tickets.strco_dogovor_begin,
         ks_par_for_curr_tickets.strco_dogovor_end,
         ks_par_for_curr_tickets.name_atp,
         ks_par_for_curr_tickets.n_vedomosti,
         ks_par_for_curr_tickets.title_ticket,
         ks_par_for_curr_tickets.title_ticketbag,
         ks_par_for_curr_tickets.bag_price_declare,
         ks_par_for_curr_tickets.marsh_nom,
         ks_par_for_curr_tickets.plat_nom
  from ks_par_for_curr_tickets(:awt_code,:date_start,:nap_code,:mar_code)
  into :prn_strco_name,
       :prn_strco_location,
       :prn_strco_mailaddress,
       :prn_strco_telephone,
       :prn_strco_dogovor_nom,
       :prn_strco_dogovor_begin,
       :prn_strco_dogovor_end,
       :prn_name_atp,
       :prn_n_vedomosti,
       :prn_title_ticket,
       :prn_title_ticketbag,
       :prn_bag_price_declare,
       :prn_marsh_nom,
       :prn_plat_nom;
  suspend;

end