declare variable V_Bag_Count smallint;
declare variable May_Back_S smallint;
declare variable Tick_Back_S smallint;
declare variable V_Val_Percent integer;
declare variable V_Lgottype smallint;
declare variable V_Skid_Is_Proc smallint;
declare variable V_Show_Full_Price smallint;
declare variable V_Child_Ticket smallint;
declare variable V_Soft smallint;
declare variable V_Kat_Code smallint;
declare variable V_Pay_All numeric(15,2);
declare variable V_Pay_Chd numeric(15,2);
declare variable V_Skidka numeric(15,2);
declare variable V_price_pl_basic numeric(15,2);
begin
  if (go_date_start is null) then go_date_start = DATE_START;
  if (:price_bag > 0) then v_bag_count = 1;
  else v_bag_count = 0;
  may_back_s = 0;
  tick_BACK_S = 0;
  if (type_oper > 0) then
    begin
      select first 1 may_back
      from sale
      where (n_ticket = :n_ticket) and (dob_n_ticket = :dob_n_ticket)
        and (type_oper = 0) and (datestart <> '30.12.1899')
      into :may_back_s;
      Tick_Back_S = 1;
    end

  V_Skidka = 0;
  V_val_percent = 0;
  V_show_full_price = 0;
--************
  select evryraice.Nomkat from evryraice
  where evryraice.datestart =:Date_Start
    and evryraice.napcod =:Nap_Cod
    and evryraice.raicecod =:Raice_Cod
    and evryraice.awtcod =:Awt_Cod
  Into :V_Kat_Code;
  if (V_Soft = 1) then -- мягкий
    select tarifs2d.paysoftall, tarifs2d.paysoftchd
    from tarifs2d
    where (tarifs2d.napcode = :Nap_Cod) and (tarifs2d.statcode = :Nom_Stat)
      and (tarifs2d.awtcode = :Awt_Cod) and (tarifs2d.katcode = :V_Kat_Code)
      and (tarifs2d.prim = 1) and (tarifs2d.startstatcode = :Start_Nomstat)
    into :V_Pay_all, :V_Pay_Chd;
  else                -- жесткий
    select tarifs2d.payhardall, tarifs2d.payhardchd
    from tarifs2d
    where (tarifs2d.napcode = :Nap_Cod) and (tarifs2d.statcode = :Nom_Stat)
      and (tarifs2d.awtcode = :Awt_Cod) and (tarifs2d.katcode = :V_Kat_Code)
      and (tarifs2d.prim = 1) and (tarifs2d.startstatcode = :Start_Nomstat)
    into :V_Pay_All, :V_Pay_Chd;
  if (Kat_Pas=0) then V_Price_Pl_Basic = V_Pay_All;
  else if (Kat_Pas In (2,3)) then V_Price_Pl_Basic = V_Pay_Chd;
       else V_Price_Pl_Basic = 0;
--***************
  if (Lgot_Cod > 0) then
    begin
      dotatsia = 0;
      select skidbd.val_percent,
             skidbd.lgottype,
             skidbd.skid_is_proc,
             skidbd.show_full_price,
             skidbd.child_ticket,
             evryraice.Soft,
             evryraice.Nomkat
        from skidbd
      right join evryraice on (skidbd.skidcod = evryraice.skidcode)
      where evryraice.datestart =:Date_Start
        and evryraice.napcod =:Nap_Cod
        and evryraice.raicecod =:Raice_Cod
        and evryraice.awtcod =:Awt_Cod
        and skidbd.lgtcod =:Lgot_Cod
      Into :V_val_percent,
           :V_lgottype,
           :V_skid_is_proc,
           :V_show_full_price,
           :V_child_ticket,
           :V_Soft,
           :V_Kat_Code;
      if ((V_val_percent = 0) or (V_val_percent Is null)) then
        begin
          dotatsia = 0;
          V_val_percent = 0;
          V_show_full_price = 0;
        End
      else
        begin
/*
          if (V_Soft = 1) then -- мягкий
            select tarifs2d.paysoftall,
                   tarifs2d.paysoftchd
            from tarifs2d
            where (tarifs2d.napcode = :Nap_Cod)
              and (tarifs2d.statcode = :Nom_Stat)
              and (tarifs2d.awtcode = :Awt_Cod)
              and (tarifs2d.katcode = :V_Kat_Code)
              and (tarifs2d.prim = 1)
              and (tarifs2d.startstatcode = :Start_Nomstat)
            into :V_Pay_all,
                 :V_Pay_Chd;
          else                -- жесткий
            select tarifs2d.payhardall,
                   tarifs2d.payhardchd
            from tarifs2d
            where (tarifs2d.napcode = :Nap_Cod)
              and (tarifs2d.statcode = :Nom_Stat)
              and (tarifs2d.awtcode = :Awt_Cod)
              and (tarifs2d.katcode = :V_Kat_Code)
              and (tarifs2d.prim = 1)
              and (tarifs2d.startstatcode = :Start_Nomstat)
            into :V_Pay_All,
                 :V_Pay_Chd;
*/
          if (V_child_ticket = 0) then dotatsia = V_Pay_All - price_place;
          else dotatsia = V_Pay_Chd - price_place;
        End
      V_Skidka = V_val_percent;
    End
  insert into sale (
    datesale,    timesale,    awtcodsale,  wrkcod,       type_oper,    n_ticket,    dob_n_ticket,
    datestart,   awtcod,      napcod,      raicecod,     n_place,      katpas,      is_prn,
    inter_tick_opendate, mytick_opendate, start_nomstat,nomstat, skidka, bagcount, price_place, price_bag,
    com_sb,      com_sb_dop,  strall,      strwant,      dotatsia,
    dot_strall,  price_usl,
    zakaz,       zakaz_int,   bron,        price_pl_isx, price_bag_isx,lgotcod,     SHOW_FULL_PRICE, shtrih_cod,
    valutecod,   go_datestart,go_napcod,   go_raicecod,  go_atpcod,    cod_spr,     proc_back,
    type_tick,   kkm_ok,      comment_tick,with_money,   may_back,     tick_back,   nom_udost,
    ser_doc_lgot,fio_pas,     name1_pass,  name2_pass,   visa,         gragd,       documcod_pass,
    pasport_data,pasp_nom,    pasp_ser,    price_spr,    price_abn,    dob_pass,    pl_birth_pass,
    internet_sale,recmove,    talontypecod,talonnum,     talonseria,   rf_priceplace, rf_pricebag,
    n_stand_pl,  n_polis,     martypcod,   raicetypcod,
    strwant_cod_comp, n_zakaz_tour,
    its_group_ticket, tour_oper_cod, pol_pass, comments, gragd_id, com_bn, regtype, Pinpad_Err, price_pl_basic)
  values (
    current_date,    current_time,    :awtsale_cod,    :wrksale_cod,    :type_oper,    :n_ticket,    :dob_n_ticket,
    :date_start,     :awt_cod,        :nap_cod,        :raice_cod,      :n_place,      :kat_pas,   :is_prn_ved,
    0,               0,               :start_nomstat,  :nom_stat,       :V_Skidka,     :v_bag_count,  :price_place, :price_bag,
    :com_sb,         0,               :strall,         :strwant,
    :dotatsia,
    :dot_strall,  :price_usl,
    :zakaz,          :zakaz_int,      :bron,           :price_pl_isx,     :price_bag_isx,:lgot_cod,    :V_Show_Full_Price, '',
    0,               :go_date_start,  :go_nap_cod,     :go_raice_cod,     :go_atpcod,    0,            :proc_back,
    :type_tick,      1,               :comment_tick,   :with_money,       :may_back_s,   :Tick_Back_S, :n_lgot_doc,
    :ser_lgot_doc,   :fio_pas,        :name1_pass,     :name2_pass,       :visa,         :gragd,       :Pass_Doc_Cod,
    :pasport_data,   :pasp_nom,       :pasp_ser,       0,                 0,             :dob_pass,    :pl_birth_pass,
    1,               1,               0,               0,                 '',            :rf_priceplace,:rf_pricebag,
    :n_stand_pl,    :n_polis,         :martypcod,      :raicetypcod,
    :strwant_cod_comp,:n_zakaz_tour,
    :its_group_ticket, :tour_oper_cod, :pol_pas, :comments, :gragd_id, :com_bn, :regtype, :Pin_pad_Err,:V_Price_Pl_Basic);
end