declare variable Name_Myawt varchar(100);
declare variable Col_Place_For_Raice smallint;
declare variable Setplsaled smallint;
declare variable Setplfree smallint;
declare variable Setplfree_Curr smallint;
declare variable Last_Sale_Place smallint;
declare variable Max_N_Place_For_Raice smallint;
declare variable I_Pl smallint;
declare variable Takdir_First smallint;
declare variable Col_Free_Pl_Minus_1 smallint;
declare variable Transit_Raice smallint;
declare variable R_Napcod smallint;
declare variable R_Raicecod smallint;
declare variable R_Datestart date;
declare variable Count_Place_Bron_Stat smallint;
declare variable Free_Dir smallint;
declare variable May_Back_Time timestamp;
declare variable Km_To_Stat_Gran integer;
declare variable Km_To_Stat integer;
declare variable Time_Sale_Bound_After integer;
declare variable Time_Start time;
declare variable Bron_To_Stat_True smallint;
declare variable Time_Bron_Dir integer;
declare variable Bron_Dir_True smallint;
declare variable Start_On_Way smallint;
begin
  err_code = 0;
  err_text = '';
  Bron_To_Stat_True = 0;
  Bron_Dir_True = 1;
  if (nap_code is null) then NAP_CODE = 0;
  if (stationname is null) then stationname = '';
  Else stationname = Upper(stationname);
  if (Napname is null) then Napname = '';
  else Napname = Upper(Napname);
  if ((not timestart_raice is null) and (stationname <> '')) then
    begin
      if (Napname <> '') then
        select evryraice.napcod,
               evryraice.raicecod
        from evryraice
        left join traces on ((evryraice.napcod = traces.napcod) and (traces.awtcod =:awt_code))
        left join Naplst on ((evryraice.napcod = Naplst.Napcod) and (Naplst.Awtcod =:awt_code))
        left join Tarifs2d on ((evryraice.napcod = Tarifs2d.napcode) and
                             (Tarifs2d.awtcode =:awt_code) and
                             (evryraice.nomkat = Tarifs2d.katcode) and
                             (traces.statcod = Tarifs2d.statcode) and
                             (Tarifs2d.prim = 1) and (Tarifs2d.Startstatcode = 0))
        where (Upper(traces.statname) = :stationname)
          and (Upper(Naplst.Napname) = :napname)
          and (traces.isdel = 0)
          and (evryraice.datestart =:date_start)
          and (evryraice.awtcod =:awt_code)
          and (evryraice.timestart =:timestart_raice)
          and (tarifs2d.paysoftall > 0 or tarifs2d.payhardall > 0)
          and (evryraice.setplbus + evryraice.standplbus >0)
          and (evryraice.available = 1)
          into :nap_code,
               :raice_code;
      Else
        select evryraice.napcod,
               evryraice.raicecod
        from evryraice
        left join traces on ((evryraice.napcod = traces.napcod) and (traces.awtcod =:awt_code))
        left join Tarifs2d on ((evryraice.napcod = Tarifs2d.napcode) and
                             (Tarifs2d.awtcode =:awt_code) and
                             (evryraice.nomkat = Tarifs2d.katcode) and
                             (traces.statcod = Tarifs2d.statcode) and
                             (Tarifs2d.prim = 1) and (Tarifs2d.Startstatcode = 0))
        where (Upper(traces.statname) = :stationname)
          and (traces.isdel = 0)
          and (evryraice.datestart =:date_start)
          and (evryraice.awtcod =:awt_code)
          and (evryraice.timestart =:timestart_raice)
          and (tarifs2d.paysoftall > 0 or tarifs2d.payhardall > 0)
          and (evryraice.setplbus + evryraice.standplbus >0)
          and (evryraice.available = 1)
          into :nap_code,
               :raice_code;
    end
  if (nap_code = 0) then
    begin
      err_code = 1;
      err_text = 'Не определены параметры рейса (направление, рейс)';

      exit;
    end
  delete from Tmp_Places_Raice where N_Place >=0;
--*** определить наименование моего вокзала ***
  select setini.paramvalue
  from setini
  where ((setini.awtcod =:awt_code) and (setini.paramcod ='Station'))
  into :Name_MyAwt;
  Name_MyAwt = Substring(Name_MyAwt From 1 For 20);
--*** определить расстояние до остановки прибытия пассажира ******
  Km_to_Stat = 0;
  if (stationname <> '') then
    select traces.Kilom from traces
    where traces.awtcod =:Awt_Code
      and traces.napcod =:Nap_Code
      and (Upper(traces.statname) = :stationname)
    into :Km_to_Stat;


  select 
    evryraice.Timestart,
    evryraice.setplbus,
    (evryraice.setpl_all + evryraice.setpl_otl_all + evryraice.setpl_mark_all) setplsaled,
--    evryraice.standplbus, (evryraice.standpl + evryraice.standpl_mark + evryraice.disrstandpl) standsaled,
--    evryraice.setpl,
    evryraice.transit
-- количество мест в автобусе
  from evryraice
  where (evryraice.datestart =:date_start) and
        (evryraice.awtcod =:awt_code) and
        (evryraice.napcod =:nap_code) and
        (evryraice.raicecod =:raice_code)
    into :Time_Start,
         :col_place_for_raice,
         :setplsaled,
         :transit_raice;
  setplfree = col_place_for_raice - setplsaled;
--*** определить срок ограничения продажи до ближайшей остановки ***
  time_sale_bound_after = 0;
  select setini.paramvalue
  from setini
  where ((setini.awtcod =:awt_code) and (setini.paramcod ='OFF_SALE_BOUND_AFTER'))
  into :time_sale_bound_after;
  if (time_sale_bound_after<> 0) then
    begin
      if ((Date_Start+time_start-0.0006943333 *time_sale_bound_after)>current_timestamp) then
        Bron_To_Stat_True = 1;
      Else Bron_To_Stat_True = 0;
    end
--*** определить срок брони директора ***
  time_bron_dir = 0;
  select setini.paramvalue
  from setini
  where ((setini.awtcod =:awt_code) and (setini.paramcod ='TimeMyBron'))
  into :Time_Bron_Dir;
  if (Time_Bron_Dir<> 0) then
    begin
      if ((Date_Start+time_start-0.0006943333 *Time_Bron_Dir)>current_timestamp) then
        Bron_Dir_True = 1;
      Else Bron_Dir_True = 0;
    end

-- максимальный № проданного места
  select max(sale.n_place) LastSalePlace
    from sale
    where (sale.go_datestart =:date_start) and
          (sale.go_napcod =:nap_code) and
          (sale.awtcod =:awt_code) and
          (sale.go_raicecod =:raice_code)
    into :Last_Sale_Place;
-- проданные билеты
  I_Pl = 0;
  for select sale.n_place,
             sale.n_ticket,
             sale.dob_n_ticket,
             sale.napcod,
             sale.raicecod,
             sale.datestart,
             sale.awtcodsale,
             sale.wrkcod,
             sale.start_nomstat,
             sale.nomstat,
             sale.may_back,
             sale.May_Back_Before_Gone,
             sale.type_tick,
             sale.Start_On_Way,
             saloninfo.bron_statnom,
             saloninfo.Plan_Bronadd_Statnom,
             saloninfo.hostcod,
             saloninfo.timemark
      from sale
      left outer join saloninfo on ((sale.awtcod = saloninfo.awtcod) and
                                    (sale.go_datestart = saloninfo.datestart) and
                                    (sale.go_napcod = saloninfo.napcod) and
                                    (sale.go_raicecod = saloninfo.raicecod) and
                                    (sale.datestart = saloninfo.datestart) and
                                    (sale.napcod = saloninfo.napcod) and
                                    (sale.raicecod = saloninfo.raicecod) and
                                    (sale.n_place = saloninfo.n_place)
                                   )
      where (sale.go_datestart =:date_start) and (sale.go_napcod =:nap_code) and
            (sale.awtcod =:awt_code) and (sale.go_raicecod =:raice_code) and
            (sale.tick_back=0) and (sale.kkm_ok=1)
      order by sale.n_place, sale.start_nomstat
      into :n_place,
           :n_ticket,
           :dob_n_ticket,
           :r_napcod,
           :r_raicecod,
           :r_datestart,
           :agent_code,
           :agent_wrk_code,
           :station_begin,
           :station_end,
           :may_back,
           :May_Back_Time,
           :type_tick,
           :Start_On_Way,
           :bron_statnom,
           :Bron_To_Statnom,
           :host_code,
           :timemark
do
  begin
    if (not (May_Back_Time is Null)) then may_back = 1;
    takdir = 1;
    zkz=0;
    n_zkz = null;
    brontype = 0;
    handhardbron = 0;
    brodisr = 0;
    abn = 0;
    timemark = null;
    datesalezak = null;
    if (Bron_To_Stat_True = 0) then Bron_To_Statnom = null;
    if (n_place <= col_place_for_raice) then place_exists = 1;
    else place_exists = 0;
    if (N_Place > 0) then
      begin
        if (I_Pl <> N_Place) then
          begin
            if (station_begin >0) then Takdir_First = -1; else Takdir_First = 1;
            I_Pl = N_Place;
            Col_Free_Pl_Minus_1 = 0;
          End
      end
    if ((r_datestart = date_start) and (r_napcod = nap_code) and (r_raicecod = raice_code)) then
      begin

        if ((station_begin >0) and (Start_On_Way=1)) then
          begin
            select traces.kilom, traces.statname from traces
            where traces.awtcod =:awt_code
              and traces.napcod =:nap_code
              and traces.statcod =:station_begin
            into :station_begin_km, :station_begin_name;
            if (Col_Free_Pl_Minus_1 = 0) then
              begin
                if (station_begin_km < Km_To_Stat) then
                  begin
                    if (Takdir_First = -1) then Setplfree = Setplfree - 1;
                    Takdir = 1; Takdir_First = 1;
                    Col_Free_Pl_Minus_1 = 1;
--                    Setplfree = Setplfree - 1;
                  end
                else
                  begin
                    if (Takdir_First < 0) then
                      begin
                        Takdir = 0; Takdir_First = 0;
                      end
                    else Takdir = Takdir_First;
                  end
              end
          end
        else
          begin
            station_begin_km = 0;
            station_begin_name = Name_MyAwt;
          end
        select traces.kilom, traces.statname from traces
        where traces.awtcod =:awt_code
          and traces.napcod =:nap_code
          and traces.statcod =:station_end
        into :station_end_km, :station_end_name;
      end
    else
      begin
        station_begin_km = null;
        station_end_km = null;
        station_begin_name = null;
        station_end_name = null;
      end
--************************************************
    insert into Tmp_Places_Raice (N_Place,       Takdir,            N_Ticket,         Dob_N_Ticket,        Zkz,
                                  N_Zkz,         Agent_Code,        Agent_Wrk_Code,   Host_Code,           Brontype,
                                  Handhardbron,  Bron_Statnom,      Bron_To_Statnom,  Brodisr,             Abn,
                                  Timemark,      Datesalezak,       May_Back,         Type_Tick,           Station_Begin,
                                  Station_End,   Station_Begin_Km,  Station_End_Km,   Station_Begin_Name,  Station_End_Name,
                                  Place_Exists)
                         values (:N_Place,      :Takdir,           :N_Ticket,        :Dob_N_Ticket,       :Zkz,
                                 :N_Zkz,        :Agent_Code,       :Agent_Wrk_Code,  :Host_Code,          :Brontype,
                                 :Handhardbron, :Bron_Statnom,     :Bron_To_Statnom, :Brodisr,            :Abn,
                                 :Timemark,     :Datesalezak,      :May_Back,        :Type_Tick,          :Station_Begin,
                                 :Station_End,  :Station_Begin_Km, :Station_End_Km,  :Station_Begin_Name, :Station_End_Name,
                                 :Place_Exists);
--******************************************************
--    suspend;
  end

--билеты, проданные транзитным вокзалом в режиме On-Line
  for select traces_beg.kilom km_begin,
             traces_end.kilom km_end,
             sale_bron_stat.statcod_beg,
             sale_bron_stat.statcod_end,
             sale_bron_stat.awtbron,
             sale_bron_stat.wrkbron,
             sale_bron_stat.n_place
      from sale_bron_stat
        left outer join traces traces_beg on (sale_bron_stat.awtcod = traces_beg.awtcod)
                                         and (sale_bron_stat.napcod = traces_beg.napcod)
                                         and (sale_bron_stat.statcod_beg = traces_beg.statcod)
        left outer join traces traces_end on (sale_bron_stat.awtcod = traces_end.awtcod)
                                         and (sale_bron_stat.napcod = traces_end.napcod)
                                         and (sale_bron_stat.statcod_end = traces_end.statcod)
      where sale_bron_stat.awtcod=:awt_code
        and sale_bron_stat.datestart=:date_start
        and sale_bron_stat.napcod=:nap_code
        and sale_bron_stat.raicecod=:raice_code
        and sale_bron_stat.statcod_beg > 0
        and sale_bron_stat.tick_back = 0
  into :station_begin_km,
       :station_end_km,
       :station_begin,
       :station_end,
       :agent_code,
       :agent_wrk_code,
       :n_place
  do
    begin
      select traces.kilom, traces.statname from traces
      where traces.awtcod =:awt_code
        and traces.napcod =:nap_code
        and traces.statcod =:station_begin
      into :station_begin_km, :station_begin_name;

      select traces.kilom, traces.statname from traces
      where traces.awtcod =:awt_code
        and traces.napcod =:nap_code
        and traces.statcod =:station_end
      into :station_end_km, :station_end_name;

      n_ticket = null;
      dob_n_ticket = null;
      may_back = null;
      type_tick = null;
      takdir = 1;
      zkz=0;
      n_zkz = null;
      brontype = 0;
      handhardbron = 0;
      brodisr = 0;
      abn = 0;
      timemark = null;
      datesalezak = null;
      place_exists = 1;
      bron_statnom = null;
      Bron_To_Statnom = null;
      host_code = null;
--************************************************
    insert into Tmp_Places_Raice (N_Place,       Takdir,            N_Ticket,         Dob_N_Ticket,        Zkz,
                                  N_Zkz,         Agent_Code,        Agent_Wrk_Code,   Host_Code,           Brontype,
                                  Handhardbron,  Bron_Statnom,      Bron_To_Statnom,  Brodisr,             Abn,
                                  Timemark,      Datesalezak,       May_Back,         Type_Tick,           Station_Begin,
                                  Station_End,   Station_Begin_Km,  Station_End_Km,   Station_Begin_Name,  Station_End_Name,
                                  Place_Exists)
                         values (:N_Place,      :Takdir,           :N_Ticket,        :Dob_N_Ticket,       :Zkz,
                                 :N_Zkz,        :Agent_Code,       :Agent_Wrk_Code,  :Host_Code,          :Brontype,
                                 :Handhardbron, :Bron_Statnom,     :Bron_To_Statnom, :Brodisr,            :Abn,
                                 :Timemark,     :Datesalezak,      :May_Back,        :Type_Tick,          :Station_Begin,
                                 :Station_End,  :Station_Begin_Km, :Station_End_Km,  :Station_Begin_Name, :Station_End_Name,
                                 :Place_Exists);
--******************************************************
--      suspend;
    end

-- места, каким-то образом отмеченные в saloninfo, но не проданные (заказ, бронь, освобожденные и т.д.)
  for select saloninfo.n_place,
             saloninfo.takdir,
             saloninfo.zkz,
             saloninfo.n_zkz,
             saloninfo.awtsalecod,
             saloninfo.wrkcod,
             saloninfo.brontype,
             saloninfo.handhardbron,
             saloninfo.bron_statnom,
             saloninfo.Plan_Bronadd_Statnom,
             saloninfo.brodisr,
             saloninfo.abn,
             saloninfo.timemark,
             saloninfo.datesalezak,
             saloninfo.freedir,
             saloninfo.hostcod
      from saloninfo
      where (saloninfo.datestart =:date_start) and
            (saloninfo.napcod =:nap_code) and
            (saloninfo.awtcod =:awt_code) and
            (saloninfo.raicecod =:raice_code) and
            (not (saloninfo.timemark is null) or
                 ((saloninfo.n_ticket is null) or (saloninfo.n_ticket = ''))
            )
      order by saloninfo.n_place
      into :n_place,
           :takdir,
           :zkz,
           :n_zkz,
           :agent_code,
           :agent_wrk_code,
           :brontype,
           :handhardbron,
           :bron_statnom,
           :Bron_To_Statnom,
           :brodisr,
           :abn,
           :timemark,
           :datesalezak,
           :free_dir,
           :host_code
  do
  begin
/*** проверить, какая это бронь от остановки: если в результате продажи транзитным вокзалом, то ее не учитывать ***/
/*** так как она уже учтена в предыдущем блоке  ***/
    if (brontype < 0) then brontype=0;
    if ((Brontype = 3) and (Bron_Dir_True = 0)) then Brontype = 0;
    if (free_dir = 0) then takdir = 1;
    else
      if (not ((host_code is null) or (host_code = 0)) ) then
        if (agent_cod_i is null) then -- если код агента/АВ-продавца не указан, то место, принадлежащее
          begin                       -- какому-то Host, считать занятым
            free_dir = 0; takdir = 1;
          end
        else    -- если код Host = коду агента/АВ-продавца или продавец-вокзал-отправитель, то место свободно,
          begin -- а иначе - занято.
            if (not((agent_cod_i = host_code) or (agent_cod_i = awt_code ))) then
              begin
                free_dir = 0; takdir = 1;
              end
          end

    count_place_bron_stat = 0;
    if ((not(bron_statnom is null)) and (bron_statnom > 0)) then
      select Count(sale_bron_stat.n_place) col_pl
        from sale_bron_stat
        where sale_bron_stat.datestart =:date_start
          and sale_bron_stat.awtcod=:awt_code
          and sale_bron_stat.napcod=:nap_code
          and sale_bron_stat.raicecod=:raice_code
          and sale_bron_stat.n_place=:n_place
          and sale_bron_stat.statcod_beg=:bron_statnom
        into :count_place_bron_stat;

    if (Bron_To_Stat_True = 0) then Bron_To_Statnom = null;

    if (count_place_bron_stat = 0) then
      begin
        for select sale.n_ticket,
                   sale.dob_n_ticket,
                   sale.awtcodsale,
                   sale.wrkcod,
                   sale.start_nomstat,
                   sale.nomstat
            from sale
            where (sale.awtcod =:awt_code) and
                  (sale.go_datestart =:date_start) and
                  (sale.go_napcod =:nap_code) and
                  (sale.go_raicecod =:raice_code) and
                  (sale.datestart =:date_start) and
                  (sale.napcod =:nap_code) and
                  (sale.raicecod =:raice_code) and  --*************************************
                  (sale.n_place =:n_place) and
                  (sale.tick_back=0) and (sale.kkm_ok=1)
        into :n_ticket,
             :dob_n_ticket,
             :agent_code,
             :agent_wrk_code,
             :station_begin,
             :station_end
        do begin
             count_place_bron_stat = count_place_bron_stat + 1;
           end
      end

    if ((count_place_bron_stat = 0) and
        ((not(bron_statnom is null)) and (bron_statnom > 0)) and
        (Km_to_Stat > 0) and
        (takdir = 0)
       ) then
      begin
        select traces.Kilom from traces
        where traces.awtcod =:Awt_Code
          and traces.napcod =:Nap_Code
          and (traces.Statcod = :bron_statnom)
--          and (Upper(traces.statname) = :stationname)
        into :Km_To_Stat_Gran;
        if (Km_To_Stat_Gran>= Km_To_Stat) then Takdir = 0; else  Takdir = 1;
      end 

    if (count_place_bron_stat = 0) then
      begin
        n_ticket = null;
        dob_n_ticket = null;
        may_back = null;
        type_tick = null;

        if (((transit_raice = 0) and (n_place <= col_place_for_raice)) or
--            ((transit_raice = 1) and ((brontype = 6) or (n_place <= col_place_for_raice)))
            ((transit_raice = 1) and (brontype = 6))
           ) then place_exists = 1;
        else place_exists = 0;
        if (place_exists = 0) then Takdir = 1;
--            if (n_place <= col_place_for_raice) then place_exists = 1;
--            else place_exists = 0;
        station_begin = null;
        station_end = null;
        station_begin_km = null;
        station_end_km = null;
        station_begin_name = null;
        station_end_name = null;
--************************************************
    insert into Tmp_Places_Raice (N_Place,       Takdir,            N_Ticket,         Dob_N_Ticket,        Zkz,
                                  N_Zkz,         Agent_Code,        Agent_Wrk_Code,   Host_Code,           Brontype,
                                  Handhardbron,  Bron_Statnom,      Bron_To_Statnom,  Brodisr,             Abn,
                                  Timemark,      Datesalezak,       May_Back,         Type_Tick,           Station_Begin,
                                  Station_End,   Station_Begin_Km,  Station_End_Km,   Station_Begin_Name,  Station_End_Name,
                                  Place_Exists)
                         values (:N_Place,      :Takdir,           :N_Ticket,        :Dob_N_Ticket,       :Zkz,
                                 :N_Zkz,        :Agent_Code,       :Agent_Wrk_Code,  :Host_Code,          :Brontype,
                                 :Handhardbron, :Bron_Statnom,     :Bron_To_Statnom, :Brodisr,            :Abn,
                                 :Timemark,     :Datesalezak,      :May_Back,        :Type_Tick,          :Station_Begin,
                                 :Station_End,  :Station_Begin_Km, :Station_End_Km,  :Station_Begin_Name, :Station_End_Name,
                                 :Place_Exists);
--******************************************************
--        suspend;
      end
  end

  if ((transit_raice = 1) and (col_place_for_raice > 0)) then
    begin
      select max(saloninfo.n_place)
      from saloninfo
      where (saloninfo.datestart =:date_start) and
            (saloninfo.napcod =:nap_code) and
            (saloninfo.awtcod =:awt_code) and
            (saloninfo.raicecod =:raice_code) and
            (saloninfo.brontype = 6)
      into :col_place_for_raice;
    end

  if (last_sale_place is null) then max_n_place_for_raice = col_place_for_raice;
  else
    begin
      if (col_place_for_raice > last_sale_place) then max_n_place_for_raice = col_place_for_raice;
      else max_n_place_for_raice = last_sale_place;
    end
  i_pl = 0;
  while (i_pl < max_n_place_for_raice) do
    begin
      i_pl = i_pl + 1;
      if (not (exists (select saloninfo.n_place
                       from saloninfo
                     where (saloninfo.datestart =:date_start) and
                           (saloninfo.napcod =:nap_code) and
                           (saloninfo.awtcod =:awt_code) and
                           (saloninfo.raicecod =:raice_code) and
                           (saloninfo.n_place =:i_pl)
                    ))) then
        begin
          n_place = i_pl;
          takdir  = 0;
          n_ticket = null;
          dob_n_ticket = null;
          zkz = 0;
          n_zkz = null;
          agent_code = null;
          agent_wrk_code = null;
          brontype = 0;
          handhardbron = 0;
          bron_statnom = null;
          Bron_To_Statnom = null;
          brodisr = 0;
          abn = 0;
          timemark = null;
          datesalezak = null;
          station_begin = null;
          station_end = null;
          station_begin_name = null;
          station_end_name = null;
          may_back = null;
          type_tick = null;
          if (transit_raice = 1) then place_exists = 0;
          else
            begin
              if (i_pl <= col_place_for_raice) then place_exists = 1;
              else place_exists = 0;
            end
          if (place_exists = 0) then Takdir = 1;
          station_begin_km = null;
          station_end_km = null;
          host_code = null;
--************************************************
    insert into Tmp_Places_Raice (N_Place,       Takdir,            N_Ticket,         Dob_N_Ticket,        Zkz,
                                  N_Zkz,         Agent_Code,        Agent_Wrk_Code,   Host_Code,           Brontype,
                                  Handhardbron,  Bron_Statnom,      Bron_To_Statnom,  Brodisr,             Abn,
                                  Timemark,      Datesalezak,       May_Back,         Type_Tick,           Station_Begin,
                                  Station_End,   Station_Begin_Km,  Station_End_Km,   Station_Begin_Name,  Station_End_Name,
                                  Place_Exists)
                         values (:N_Place,      :Takdir,           :N_Ticket,        :Dob_N_Ticket,       :Zkz,
                                 :N_Zkz,        :Agent_Code,       :Agent_Wrk_Code,  :Host_Code,          :Brontype,
                                 :Handhardbron, :Bron_Statnom,     :Bron_To_Statnom, :Brodisr,            :Abn,
                                 :Timemark,     :Datesalezak,      :May_Back,        :Type_Tick,          :Station_Begin,
                                 :Station_End,  :Station_Begin_Km, :Station_End_Km,  :Station_Begin_Name, :Station_End_Name,
                                 :Place_Exists);
--******************************************************
--          suspend;
        end

    end
  Setplfree_Curr = 0;

  For Select N_Place,       Takdir,            N_Ticket,         Dob_N_Ticket,        Zkz,
         N_Zkz,         Agent_Code,        Agent_Wrk_Code,   Host_Code,           Brontype,
         Handhardbron,  Bron_Statnom,      Bron_To_Statnom,  Brodisr,             Abn,
         Timemark,      Datesalezak,       May_Back,         Type_Tick,           Station_Begin,
         Station_End,   Station_Begin_Km,  Station_End_Km,   Station_Begin_Name,  Station_End_Name,
         Place_Exists  from Tmp_Places_Raice
  Order by N_Place
  into :N_Place,      :Takdir,           :N_Ticket,        :Dob_N_Ticket,       :Zkz,
                                 :N_Zkz,        :Agent_Code,       :Agent_Wrk_Code,  :Host_Code,          :Brontype,
                                 :Handhardbron, :Bron_Statnom,     :Bron_To_Statnom, :Brodisr,            :Abn,
                                 :Timemark,     :Datesalezak,      :May_Back,        :Type_Tick,          :Station_Begin,
                                 :Station_End,  :Station_Begin_Km, :Station_End_Km,  :Station_Begin_Name, :Station_End_Name,
                                 :Place_Exists
  do Begin
       if (setplfree > 0) then
         Begin
           if (Takdir = 0) then
             begin
               Setplfree_Curr = Setplfree_Curr+1;
               if (Setplfree_Curr > Setplfree) then Takdir = 1;
             end
         End
       Else Takdir = 1;
       Suspend;
     end
end