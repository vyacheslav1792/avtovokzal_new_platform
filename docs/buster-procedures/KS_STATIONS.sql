declare variable Sql_Text varchar(2000);
declare variable Sql_Add1 varchar(2000);
declare variable Sql_Add2 varchar(2000);
declare variable Sql_Add3 varchar(2000);
declare variable Sql_Add4 varchar(2000);
declare variable Martypday smallint;
declare variable Napday smallint;
declare variable Str_Len smallint;
declare variable Str_Txt varchar(300);
declare variable Except_Nap smallint;
declare variable I_Tmp smallint;
declare variable Nap_Ok smallint;
declare variable Len_Str_Tmp smallint;
declare variable Pos_Char_Tmp smallint;
BEGIN
  Except_nap = 1; -- 1-только на напр, -1 кроме напр.
  if (Atp_Code is null) then Atp_Code = '';
  else
    begin
      len_str_tmp = char_length(Atp_Code);
      if (len_str_tmp > 1) then
        begin
          pos_char_tmp = position(',', Atp_Code,len_str_tmp-1);
          if (pos_char_tmp > 0) then  -- убрать запятую в конце списка АТП, если она там есть
            Atp_Code = cast(Substring(Atp_Code From 1 For (len_str_tmp-1)) as varchar(300));
        end
    End
  if ((agent_napcode is null) or (agent_napcode= '') or (agent_napcode = '0')) then agent_napcode = '';
  if (agent_napcode <> '') then
    begin
      agent_code = 0;
      agent_id = 0;
      str_len = Char_Length(agent_napcode);
      if (',' = Substring(agent_napcode From str_len For 1)) then
        begin
          str_txt = cast(Substring(agent_napcode From 1 For (str_len - 1)) as varchar(300));
          agent_napcode = str_txt;
        end
      if ('-' = Substring(agent_napcode From 1 For 1)) then
        begin
          Except_nap = -1;
          agent_napcode = cast(Substring(agent_napcode From 2 For (Char_Length(agent_napcode) - 1)) as varchar(300));
        end
    end

  err_cod = 0; err_text = '';
  if ((nap_code > 0) and (AGENT_ID > 1)) then
    begin
      nap_code = 0;
      err_cod = -1; --предупреждение
      err_text = 'Исправлено: код направления=0';
    end
  if (not (agent_code is null ) and (AGENT_ID is null)) then
    begin                                                      
      err_cod = 1; --ошибка
      err_text = 'Не определен ID агента: -1,0,1,2,3';
      suspend;
      exit;
    end

  if (((agent_code is null) and ((agent_id is null) or (agent_id = 3)))
   or (agent_code = awt_code)) then
    begin   --мой вокзал
      agent_code = 0;
      if (agent_id <> 3) then agent_id  = 0;
    end
  else
    begin
      if (agent_id < 2) then agent_code = agent_code * agent_id; -- точка-точка
      else agent_code = 0; -- site или терминал - работаем по всем направлениям по признакам на рейсах
    end

/**/
  if (Atp_Code = '') then Sql_Add4 = '';
--  Else Sql_Add4 = ' and (raicelst.Atpcod ='''||:Atp_Code||''')';
  Else Sql_Add4 = ' and (raicelst.Atpcod in('||Atp_Code||'))';
  if (nap_code = 0) then
    begin
      sql_add1 = ''; sql_add2 = ''; sql_add3 = '';
--****      not (naplst.napcod in (32,15))  and ****
      if (agent_code = 0) then
        begin --без ограничений по направлениям и без исключений направлений - вокзалы, агенты, сайты, терминалы
          if ((agent_id = 0) and (agent_napcode <> '')) then
            begin
              if (except_nap = 1) then sql_add3 = ' and (naplst.napcod in ('||:agent_napcode||'))';
              else sql_add3 = ' and not (naplst.napcod in ('||:agent_napcode||'))';
            end
          if (agent_id = 2) then sql_add3 = ' and (raicelst.internet_sale_available = 1)';
          if (agent_id = 3) then sql_add3 = ' and (raicelst.terminal_sale_available = 1)';
        end
      if (agent_code < 0) then
        begin --по выделенным направлениям - только чужие вокзалы, агенты
          agent_code = -agent_code;
          sql_add1 = ' left join agent_nap on (traces.awtcod = agent_nap.awtcod) and (traces.napcod = agent_nap.napcod) ';

          sql_add2 = ' and  (((agent_nap.endday is null) and (agent_nap.beginday is null)) or' ||
                          ' (((agent_nap.agentcod ='''||:agent_code||''') and (agent_nap.awtcod = '''||:awt_code||''')) and' ||
                          ' ( not (agent_nap.beginday is null) and not (agent_nap.endday is null) and (agent_nap.endday>=current_date)) or' ||
                          ' (not (agent_nap.beginday is null) and (agent_nap.endday is null) and (agent_nap.beginday<=current_date+90)))) ';
        end
      if (agent_code > 0) then
        begin --за исключением указанных направлений - чужие вокзалы, агенты
          sql_add1 = ' left join agent_nap on (traces.awtcod = agent_nap.awtcod) and (traces.napcod = agent_nap.napcod) ';
          sql_add2 = ' and  (((agent_nap.agentcod = '''||:agent_code||''') and (agent_nap.awtcod = '''||:awt_code||''')) and not (agent_nap.beginday is null)) and' ||
                          ' ((not (agent_nap.endday is null) and (agent_nap.endday>=current_date)) or' ||
                          ' ((agent_nap.endday is null) and (agent_nap.beginday<=current_date+90))) ';
        end

      sql_text = ' select distinct traces.statname from traces' ||
                 ' join naplst on (traces.napcod = naplst.napcod) AND (naplst.awtcod ='''||:awt_code||''') '||
                 ' join tarifs2d on (traces.awtcod = tarifs2d.awtcode) and (traces.napcod = tarifs2d.napcode) and' ||
                                        ' (traces.statcod = tarifs2d.statcode) and (tarifs2d.startstatcode = 0)' ||
                 ' join raicelst on (traces.awtcod = raicelst.awtcod) and (traces.napcod = raicelst.napcod)' ||
                   sql_add1 ||
                 ' where (traces.awtcod ='''||:awt_code||''') and (traces.isdel=0) and (NAPLST.isdel =0)' ||
                   ' and ((raicelst.reg_fixed = 1) or ' ||
                       '  ((((raicelst.reg_stop is null and raicelst.reg_start is null) or' ||
                         ' (raicelst.reg_start <= (current_date+90) and (raicelst.reg_stop is null or raicelst.reg_stop >= current_date)) or' ||
                        ' (raicelst.reg_start is null and (raicelst.reg_stop >= current_date))))' ||
                   ' and (raicelst.reg_monday = 1 or raicelst.reg_tuesday = 1 or raicelst.reg_wednesday = 1 or raicelst.reg_thursday = 1 or' ||
                        ' raicelst.reg_friday = 1 or raicelst.reg_saturday = 1 or raicelst.reg_sunday = 1)))' ||
                     sql_add2 ||
                     sql_add3 || Sql_Add4;
      for execute statement(sql_text)
      into :stat_name
      do begin
           select max(predv_prod_srok_on_martyp.colday) martypday,
                  max(predv_prod_srok_on_nap.colday) napday
           from traces
           left join raicelst on (traces.awtcod = raicelst.awtcod) and (traces.napcod = raicelst.napcod)
           left join predv_prod_srok_on_martyp on (raicelst.awtcod = predv_prod_srok_on_martyp.awtcod)
                                              and (raicelst.martypcod = predv_prod_srok_on_martyp.martypcod)
           left join predv_prod_srok_on_nap on (raicelst.awtcod = predv_prod_srok_on_nap.awtcod)
                                           and (raicelst.napcod = predv_prod_srok_on_nap.napcod)
           where traces.statname =:stat_name
           into :martypday,
                :napday;
           if ((martypday is null) and (napday is null)) then pre_sale_days = 0;
           else
             begin
               if (not (martypday is null) and not (napday is null)) then
                 begin
                   if (martypday > napday) then pre_sale_days = martypday;
                   else pre_sale_days = napday;
                 end
               else
                 if (martypday is null) then pre_sale_days = napday;
                 else pre_sale_days = martypday;
             end
           suspend;
         end
    end
  else
    begin
      Nap_ok = 1;

      if (agent_napcode <> '') then
        begin
          str_txt = nap_code;
          i_tmp = position(str_txt,agent_napcode);
          if ((Except_nap = 1) and (i_tmp > 0)) then Nap_ok = 1;
          else if ((Except_nap = -1) and (i_tmp = 0)) then Nap_ok = 1;
               else Nap_ok = 0;
        end
      if (Nap_ok = 0) then
        begin
          suspend;
          exit;
        end
--********* на направлении ... ***************************************************************
      for select traces.napcod,
                 traces.statcod,
                 traces.statname,
                 traces.rayoncod,
                 traces.kilom,
                 traces.timerstat
          from traces
          where (traces.napcod =:nap_code) and (traces.isdel=0) and (traces.awtcod =:awt_code)
          order by traces.kilom
          into :nap_code_r,
               :stat_code,
               :stat_name,
               :rayon_code,
               :kilom,
               :timer_stat
          do begin
           select max(predv_prod_srok_on_martyp.colday) martypday,
                  max(predv_prod_srok_on_nap.colday) napday
           from traces
           left join raicelst on (traces.awtcod = raicelst.awtcod) and (traces.napcod = raicelst.napcod)
           left join predv_prod_srok_on_martyp on (raicelst.awtcod = predv_prod_srok_on_martyp.awtcod) and (raicelst.martypcod = predv_prod_srok_on_martyp.martypcod)
           left join predv_prod_srok_on_nap on (raicelst.awtcod = predv_prod_srok_on_nap.awtcod) and (raicelst.napcod = predv_prod_srok_on_nap.napcod)
           where traces.statname =:stat_name
           into :martypday,
                :napday;
           if ((martypday is null) and (napday is null)) then pre_sale_days = 0;
           else
             begin
               if (not (martypday is null) and not (napday is null)) then
                 begin
                   if (martypday > napday) then pre_sale_days = martypday;
                   else pre_sale_days = napday;
                 end
               else
                 if (martypday is null) then pre_sale_days = napday;
                 else pre_sale_days = martypday;
             end
               suspend;
             end
    end
end