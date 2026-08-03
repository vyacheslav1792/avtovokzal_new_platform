declare variable Tak_Dir_Tmp smallint;
declare variable Ii smallint = 1;
declare variable Jj smallint = 1;
declare variable Curr_N_Pl smallint;
declare variable Curr_Lgt varchar(300);
declare variable Col_Bron_True smallint;
declare variable Col_Bron_False smallint;
declare variable Nn_Places_Bron_Avto varchar(300);
declare variable Nn_Places_Bron_False varchar(300);
declare variable Time_To_Bron_Time timestamp;
declare variable Bron_Time_In_Min_Inp integer;
declare variable Id_Time_Places_Tmp timestamp;
declare variable Raice_Datestart date;
declare variable Raice_Timestart time;
declare variable Tracetime_Cod smallint;
declare variable V_Strtmp varchar(300);
declare variable V_Time_To_Stat integer;
declare variable V_Time_Stop integer;
declare variable Time_Tmp time;
declare variable V_Date_Time_Arrival timestamp;
declare variable V_Date_Time_Start timestamp;
declare variable V_Soft smallint;
declare variable V_Kat_Code smallint;
declare variable V_Skid_Code smallint;
declare variable V_Lgtcod smallint;
declare variable V_Round_Money numeric(15,2);
declare variable V_Pay_All numeric(15,2);
declare variable V_Pay_Lgt numeric(15,2);
declare variable V_Pay_Chd numeric(15,2);
declare variable V_Pay_Bag numeric(15,2);
declare variable V_Price varchar(300);
declare variable V_Val_Percent integer;
declare variable V_Skid_Is_Proc smallint;
declare variable Tmp_Money numeric(15,2);
declare variable Raice_Available smallint;
declare variable Raice_Gone smallint;
declare variable V_Dinamoprice_Cod integer;
declare variable Setplbus smallint;
declare variable Setplsaled smallint;
declare variable Summscid numeric(15,2);
declare variable Summscid_Persent numeric(15,2);
declare variable I_Am_ varchar(5);
declare variable Reg_Paspdata smallint;
declare variable P_Name smallint;
declare variable P_Pol smallint;
declare variable P_Datebirth smallint;
declare variable P_Placebirth smallint;
declare variable P_N_Doc smallint;
declare variable P_Gragd smallint;
declare variable P_Phone smallint;
declare variable P_Tripsabroan smallint;
begin
  err_code = 0;
  err_text = '';
  nn_places_bron_true = '';
  nn_places_bron_false = '';
  col_bron_true = 0;
  col_bron_false = 0;
  id_time_places_tmp = null;
  if (start_stat_code is null) then start_stat_code = 0;
  bron_time_in_min_inp = bron_time_in_min;
  if (bron_time_in_min <> 0) then bron_time_in_min = bron_time_in_min - 30;
  else bron_time_in_min = 0;

  reg_data_pass = '';  --'FDBPCG'
/*  F – ФИО пассажира
    D – документ, серия, номер
    B – дата рождения
    P – место рождения
    C – гражданство (страна)
    G – пол (м/ж)
    I - регистрация визы, второго гражданства, второго паспорта
    T - телефон
*/

-- *****************************************************************************
  select
    evryraice.available,
    evryraice.Gone
  from evryraice
  where ((evryraice.datestart = :Date_Start) and (evryraice.awtcod = :Awt_Code)
     and (evryraice.napcod = :Nap_Code) and (evryraice.raicecod = :Raice_Code)
        )
    into :raice_available,
         :raice_Gone;
  if ((raice_available = 0) or (Raice_Gone = 1))  then
    begin
      err_code = 2;
      err_text = 'Нет продажи билетов на рейс. В бронировании отказано.';
      nn_places_bron_true = '';
      col_bron_true = 0;
      suspend;
      exit;
    end
-- *****************************************************************************
  if (bron_time_in_min <> 0) then time_to_bron_time = dateadd(minute, bron_time_in_min, current_timestamp);
  else time_to_bron_time = current_timestamp;
  id_time_places_tmp = time_to_bron_time;
  ID_TIME_places = cast(id_time_places_tmp as varchar(25));
--******************************************************************************
-- начинаем резервировать места в соответствии со сформированными списками данных
--******************************************************************************
  while (ii <= Char_Length(nn_places)) do
    begin
      if ('@' = Substring(nn_places From ii For 1)) then
        begin
          curr_n_pl = cast(Substring(nn_places From jj For (ii - jj)) as smallint);

          tak_dir_tmp = -1;
          /* проверить свободно место или нет */
          select takdir
          from ks_find_free_pl(:date_start, :nap_code, :awt_code, :raice_code, :curr_n_pl,
                                :pl_fromintbron, :stat_code,:agent_code)
          into :tak_dir_tmp;

          if ((tak_dir_tmp <= 0) or (tak_dir_tmp is null))  then
            begin
-- задать общие параметры для списка заказанных билетов
              tak_dir_tmp = 0;
              execute procedure ks_saloninfo_ins_up(:date_start,
                                                    :awt_code,
                                                    :nap_code,
                                                    :raice_code,
                                                    :curr_n_pl,
                                                    :tak_dir_tmp,
                                                     0,
                                                     null,
                                                    :agent_code,
                                                    :agent_wrk,
                                                     0,
                                                     null,
                                                     null,
                                                    :time_to_bron_time,
                                                     null);
              nn_places_bron_true = nn_places_bron_true || curr_n_pl || '@';
              col_bron_true = col_bron_true + 1;
            end
          else
            begin
              nn_places_bron_false = nn_places_bron_false || curr_n_pl || ' ';
              col_bron_false = col_bron_false + 1;
            end
          ii = ii + 1;
          jj = ii;
        end
      ii = ii + 1;
    end
--*** если не удалось забронировать все заявленные места, **********************
--*** то недостающее количество мест забронировать в автоматическом режиме *****
  if (col_bron_false > 0) then
    begin
      select ks_abo_brontime_places.col_abo_bron
      from ks_abo_brontime_places(:date_start,:awt_code,:nap_code,:raice_code,:agent_code,:agent_wrk,:nn_places_bron_true)
      into :col_bron_true;
      err_code = 1;
      err_text = 'не удалось забронировать места: ' || nn_places_bron_false ||'. В бронировании отказано.';
      nn_places_bron_true = '';
      col_bron_true = 0;
      suspend;
      exit;
    end
--******************************************************************************
--******************************************************************************

  if (col_places > 0) then
    begin
      select ks_brontime_place_col.nn_places_bron
      from ks_brontime_place_col(:col_places,:awt_code,:nap_code,:raice_code,:stat_code,:date_start,
                                  :agent_code,:agent_wrk,:pl_fromintbron,:bron_time_in_min_inp,:time_to_bron_time)
      into :nn_places_bron_avto;
      nn_places_bron_true = nn_places_bron_true || nn_places_bron_avto;
      if (nn_places = '') then nn_places_bron_avto = '';
    end
--******************************************************************************
  if (col_bags > 0) then
    begin
      execute procedure ks_stand_info_ins_up(:agent_code, :agent_wrk, :date_start,
                                             :awt_code, :nap_code, :raice_code,
                                             :time_to_bron_time, :col_bags);
      ii = 0;
      while (ii < col_bags) do
        begin
          nn_places_bron_true = nn_places_bron_true || 'Б@';
          ii = ii + 1;
        end
    end

  select evryraice.datestart,
         evryraice.timestart,
         evryraice.tracetimecod,
         evryraice.soft,
         evryraice.Nomkat,
         evryraice.Skidcode,
         evryraice.Round_Money,
         buslst.comment_on_tickets,
         evryraice.Dinamoprice_Cod,
         evryraice.Setplbus,
         (evryraice.setpl_all + evryraice.setpl_otl_all + evryraice.setpl_mark_all) setplsaled,
         evryraice.Passenger_Data_Items_Id, --
         Passenger_Data_Items.Pass_Name,
         Passenger_Data_Items.Pass_Pol,
         Passenger_Data_Items.Date_Of_Birth,
         Passenger_Data_Items.Place_Of_Birth,
         Passenger_Data_Items.N_Doc,
         Passenger_Data_Items.Gragd,
         Passenger_Data_Items.Phone,
         Passenger_Data_Items.Trips_Abroan
    from evryraice
  left join buslst on (evryraice.buscod = buslst.buscod)    --********* для марки автобуса ***********
  left join Passenger_Data_Items on (evryraice.Passenger_Data_Items_Id = Passenger_Data_Items.Passenger_Data_Items_Id)
   where ((evryraice.datestart = :date_start) and (evryraice.awtcod = :awt_code)
     and (evryraice.napcod = :nap_code) and (evryraice.raicecod = :raice_code))
    into :raice_datestart,
         :raice_timestart,
         :tracetime_cod,
         :v_soft,
         :V_Kat_Code,
         :V_Skid_code,
         :V_Round_Money,
         :bus_kat,
         :V_Dinamoprice_Cod,
         :Setplbus,
         :Setplsaled,
         :reg_paspdata,
         :P_Name,
         :P_Pol,
         :P_Datebirth,
         :P_PlaceBirth,
         :P_N_Doc,
         :P_Gragd,
         :P_Phone,
         :P_Tripsabroan;
  if ((bus_kat is null) or (bus_kat = '')) then
    begin
      if (v_soft = 1) then bus_kat = 'Автобус МЯГКИЙ';
      else bus_kat = 'Автобус ЖЕСТКИЙ';
    end
  if ((V_Round_Money is null) or (V_Round_Money <= 0))  then V_Round_Money = 0.01;
--******************************************************************************
  if ((V_Dinamoprice_Cod is null) or (V_Dinamoprice_Cod = 0))  then
    begin
      Summscid= 0;
      Summscid_Persent=1;
--*** если нет динамических тарифов, то определить скидки на пред. продажу , если они есть *************
      if (Awt_Code = Agent_Code) then i_am_ = 'awt_s';
      else i_am_ = 'awt_i'; -- точка-точка

      select sh_cs_read_scid_pred_prod.skid_pred, sh_cs_read_scid_pred_prod.skid_in_proc
      from sh_cs_read_scid_pred_prod(:Date_Start, :Nap_Code, :Awt_Code, :raice_code,:i_am_)
      into :Summscid,
           :Summscid_Persent;
      if (Summscid_Persent> 0) then
        begin
          Summscid_Persent = 1+(-Summscid / 100); --100-Summscid;
          Summscid = 0;
        end
      else
        Begin
          Summscid_Persent = 1;  Summscid = -Summscid;
        End
    End
  Else
    begin
      select Ks_Dinamo_Addtoprice.Summscid, Ks_Dinamo_Addtoprice.Summscid_Persent
      from Ks_Dinamo_Addtoprice(:V_Dinamoprice_Cod,:Date_Start,:raice_timestart,:Setplbus,:Setplsaled)
      into :Summscid, :Summscid_Persent;
      if (Summscid is Null) then  Summscid = 0;
      Summscid_Persent = 1+(Summscid_Persent / 100);
    end
--******************************************************************************
  reg_data_pass = '';
  if (reg_paspdata > 0) then
    begin
      if (P_Name =1) then reg_data_pass = reg_data_pass || 'F'; -- ФИО пассажира
      if (P_N_Doc =1) then reg_data_pass = reg_data_pass || 'D';  -- документ, серия, номер
      if (P_Datebirth =1) then reg_data_pass = reg_data_pass || 'B';  -- дата рождения
      if (P_Placebirth =1) then reg_data_pass = reg_data_pass || 'P';  -- место рождения
      if (P_Gragd =1) then reg_data_pass = reg_data_pass || 'C';  -- гражданство (страна)
      if (P_Pol =1) then reg_data_pass = reg_data_pass || 'G';  -- пол (м/ж)
      if (P_Phone =1) then reg_data_pass = reg_data_pass || 'T'; -- телефон
      if (P_Tripsabroan =1) then reg_data_pass = reg_data_pass || 'I';  -- регистрация визы, второго гражданства, второго паспорта
    end
/*** время отправления и время прибытия ***************************************/
  if (start_stat_code > 0) then
    begin --время отправления
      select tracetime.time_to_stat,
             tracetime.time_of_stop
        from tracetime
       where tracetime.traicetimecod = :tracetime_cod
         and tracetime.awtcod = :awt_code
         and tracetime.napcod = :nap_code
         and tracetime.statcod = :start_stat_code
      into : v_time_to_stat,
           : v_time_stop;
      if (v_time_to_stat is null) then v_date_time_start = null;
      else
        begin
          time_tmp = raice_timestart + (v_time_to_stat + v_time_stop)*60;
          v_date_time_start = raice_datestart + time_tmp;
        end
    end
  else
    begin
      v_date_time_start = raice_datestart+raice_timestart;
    end

  v_strtmp = extract(minute from v_date_time_start);
  if (Char_length(v_strtmp) < 2) then v_strtmp = '0'|| v_strtmp;

  v_strtmp = extract(hour from v_date_time_start)||':' || v_strtmp;
  if (Char_length(v_strtmp) < 5) then v_strtmp = '0'|| v_strtmp;

  v_strtmp = extract(year from v_date_time_start)||' ' || v_strtmp;

  v_strtmp = extract(month from v_date_time_start) || '.' || v_strtmp;

  if (Char_length(v_strtmp) < 13) then v_strtmp = '0'|| v_strtmp;

  v_strtmp = extract(day from v_date_time_start) || '.'|| v_strtmp;
  if (Char_length(v_strtmp) < 16) then v_strtmp = '0'|| v_strtmp;

  datetime_start = cast(Substring(v_strtmp From 1 For 16) as varchar(20));
-- время прибытия
  select tracetime.time_to_stat,
         tracetime.time_of_stop
    from tracetime
   where tracetime.traicetimecod = :tracetime_cod
     and tracetime.awtcod = :awt_code
     and tracetime.napcod = :nap_code
     and tracetime.statcod = :stat_code
  into : v_time_to_stat,
       : v_time_stop;
  if (v_time_to_stat is null) then v_date_time_arrival = null;
  else
    begin
      time_tmp = raice_timestart + v_time_to_stat*60;
      v_date_time_arrival = raice_datestart + time_tmp; -- 1 мин = 0.0006943333
    end


  v_strtmp = extract(minute from v_date_time_arrival);
  if (Char_length(v_strtmp) < 2) then v_strtmp = '0'|| v_strtmp;

  v_strtmp = extract(hour from v_date_time_arrival)||':' || v_strtmp;
  if (Char_length(v_strtmp) < 5) then v_strtmp = '0'|| v_strtmp;

  v_strtmp = extract(year from v_date_time_arrival)||' ' || v_strtmp;

  v_strtmp = extract(month from v_date_time_arrival) || '.' || v_strtmp;

  if (Char_length(v_strtmp) < 13) then v_strtmp = '0'|| v_strtmp;

  v_strtmp = extract(day from v_date_time_arrival) || '.'|| v_strtmp;
  if (Char_length(v_strtmp) < 16) then v_strtmp = '0'|| v_strtmp;

  datetime_arrival = cast(Substring(v_strtmp From 1 For 16) as varchar(20));

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
  from ks_par_for_curr_tickets(:awt_code,:date_start,:nap_code,:raice_code)
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
--*** параметры для полиса добровольного страхования **********************************
  Select First 1 Strcomplst.Strcompcod,
                 Strcomplst.Strcompname,
                 Strcomplst.Location,
                 Strcomplst.Mailing_Address,
                 Strcomplst.Telephone,
                 Strcomplst.Inn
  From Strcomplst
  where Strcomplst.Isdel = 0
  order by Strcomplst.N_Pp
  Into :ForPolis_strco_Cod,
       :ForPolis_strco_name,
       :ForPolis_strco_location,
       :ForPolis_strco_mailaddress,
       :ForPolis_strco_phone,
       :ForPolis_strco_Inn;
--******************************************************************************
  ii = 1; jj = 1;
  Price_Lgt = '';
  Cod_Lgt   = '';
  if (V_Soft = 1) then -- мягкий
      select tarifs2d.paysoftall,
             tarifs2d.paysoftlgt,
             tarifs2d.paysoftchd,
             tarifs2d.paysoftbag
      from tarifs2d
      where (tarifs2d.napcode = :Nap_Code)
        and (tarifs2d.statcode = :Stat_Code)
        and (tarifs2d.awtcode = :Awt_Code)
        and (tarifs2d.katcode = :V_Kat_Code)
        and (tarifs2d.prim = 1)
        and (tarifs2d.startstatcode = :Start_Stat_Code)
      into :V_Pay_all,
           :V_Pay_Lgt,
           :V_Pay_Chd,
           :V_Pay_Bag;
  else                -- жесткий
      select tarifs2d.payhardall,
             tarifs2d.payhardlgt,
             tarifs2d.payhardchd,
             tarifs2d.payhardbag
      from tarifs2d
      where (tarifs2d.napcode = :Nap_Code)
        and (tarifs2d.statcode = :Stat_Code)
        and (tarifs2d.awtcode = :Awt_Code)
        and (tarifs2d.katcode = :V_Kat_Code)
        and (tarifs2d.prim = 1)
        and (tarifs2d.startstatcode = :Start_Stat_Code)
      into :V_Pay_All,
           :V_Pay_Lgt,
           :V_Pay_Chd,
           :V_Pay_Bag;


  if (not (List_Lgt Is Null)) then
    Begin
      while (ii <= Char_Length(List_Lgt)) do
        begin
          if ('@' = Substring(List_Lgt From ii For 1)) then
            begin
              Curr_Lgt = cast(Substring(List_Lgt From jj For (ii - jj)) as Varchar(300));
              Curr_Lgt = Upper(Curr_Lgt);
--**********************
              V_lgtcod = 0;
              if (Curr_Lgt='П') then
                begin
                  V_Price = (V_Pay_All+ Summscid)*Summscid_Persent;
                end
              Else
                if (Curr_Lgt='Д') then
                  begin
                    V_Price = (V_Pay_Chd+ Summscid/2)*Summscid_Persent;
                  End
                Else
                  if (Curr_Lgt='Б') then V_Price = V_Pay_Bag;
                  Else
                    Begin
                      if (Curr_Lgt='Л') Then
                        begin
                          V_Price = (V_Pay_Lgt+ Summscid/2)*Summscid_Persent;
                        end
                      Else
                        begin
                          V_val_percent = 0;
                          select skidbd.val_percent,
                                 skidbd.skid_is_proc,
                                 skidbd.Lgtcod
                          from skidbd
                          left join lgtlst on (skidbd.lgtcod = lgtlst.lgtcod)
                          where skidbd.skidcod = :V_Skid_Code
                            and Upper(lgtlst.lgtname) =:Curr_Lgt
                          Into :V_val_percent,
                               :V_skid_is_proc,
                               :V_lgtcod;
                          if (V_val_percent = 0) then V_Price = V_Pay_All;
                          Else 
                            begin
                              if ((V_val_percent = 100) and (V_skid_is_proc = 1)) Then V_Price = 0;
                              Else
                                begin
                                  if ((V_val_percent = 50) and (V_skid_is_proc = 1)) Then V_Price = V_Pay_Lgt;
                                  else
                                    begin
                                      if (V_skid_is_proc = 0) then V_Price = V_Pay_All - V_val_percent*0.01;
                                      if (V_skid_is_proc = 1) then
                                        begin
                                          tmp_money = (V_Pay_All / 100) * (100 - V_val_percent);
                                          V_Price = tmp_money;
                                        End
                                    End
                                end
                            End
                        End
                    end
              if (V_Price < 0) then V_Price = 0;
              else
                Select Sh_Fun_Round.Money_Resalt
                From Sh_Fun_Round(:V_Price,:V_Round_Money)
                into:V_Price;

              Price_Lgt = Price_Lgt || cast(V_Price as Varchar(300)) || '@';
              Cod_Lgt   = Cod_Lgt || cast(V_lgtcod as Varchar(300)) || '@';
--**********************
              ii = ii + 1;
              jj = ii;
            end
          ii = ii + 1;
        end
    end
  Else 
    begin
      V_Price = (V_Pay_All+ Summscid)*Summscid_Persent;
      if (V_Price < 0 ) then V_Price = 0;
      else
        Select Sh_Fun_Round.Money_Resalt
        From Sh_Fun_Round(:V_Price,:V_Round_Money)
        into:V_Price;
      Price_Lgt = Price_Lgt || cast(V_Price as Varchar(300)) || '@';
      Cod_Lgt   = Cod_Lgt || '0' || '@';
    End
--******************************************************************************
--******************************************************************************
  suspend;
end