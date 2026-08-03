declare variable I smallint = 1; /* служебный счетчик для цикла */
declare variable J smallint = 1; /* служебный счетчик для цикла */
declare variable V_Strtmp varchar(300);
declare variable V_Documcod smallint;
declare variable V_Pasport_Ser varchar(12);
declare variable V_Pasport_N varchar(25);
declare variable V_Gragd_Id smallint;
declare variable Result_Tmp smallint;
declare variable Len_Ser smallint;
begin
  if (char_length(S_Pasport_Ser) > 1) then S_Pasport_Ser = Upper(S_Pasport_Ser);
  Result_Code = 0;
  result_comment = '';
  if (S_Gragd_Id is null) then
    begin
      result_comment = result_comment || 'не определено гражданство ';
      Result_Code = Result_Code + 1;
    end
  if (s_docum_cod is null) then
    begin
      result_comment = result_comment || 'не определен вид документа ';
      Result_Code = Result_Code + 10;
    End
  if (Result_Code > 0) then
    begin
      Result_Code = Result_Code + 2000;
      suspend;
      exit;
    End
-- определить ID гражданства пассажира  ***********************
  while (Char_Length(s_gragd_id) > 1) do
    begin
      select param_smallint, string_out
      from sh_fun_from_str_2_smallint(:s_gragd_id,'@')
      into :v_gragd_id, :v_strtmp;
      s_gragd_id = v_strtmp;

-- определить код документа пассажира  ***********************
      if (s_docum_cod is null) then v_documcod = 21;
      else
        begin
          select param_smallint, string_out
          from sh_fun_from_str_2_smallint(:s_docum_cod,'@')
          into :v_documcod, :v_strtmp;
          s_docum_cod = v_strtmp;
          if (v_documcod = 0) then v_documcod = 21;  -- код документа: по умолчанию - паспорт
        end
      if (((v_documcod = 10) and (v_gragd_id = 185)) or
--          ((v_documcod in (3,15,21,22)) and (v_gragd_id <> 185))) then
          ((v_documcod in (15,21,22)) and (v_gragd_id <> 185))) then
        begin
          if (v_gragd_id = 185) then v_strtmp = 'Указан иностранный паспорт и Российское гражданство ';
          else v_strtmp = 'Гражданство - не Россия, документ определен Российский ';
          result_comment = result_comment || v_strtmp || 'Не верно ';
          Result_Code = 2100;
          suspend;
          exit;
        End

    --*** определить серию паспорта пассажира ***********************
      if (s_pasport_ser is null) then v_pasport_ser = '';
      else
        begin
          select param_string, string_out
          from sh_fun_from_str_2_substring(:s_pasport_ser,'@')
          into :v_pasport_ser, :v_strtmp;
          s_pasport_ser = v_strtmp;
        end
     if (v_documcod = 21) then   -- паспорт РФ
       begin
         if (char_length(v_pasport_ser) =4) then
           Begin
             i=1; j=4; result_TMP = 0;
             while (i <= j) do
               begin
                 if (not (substring(v_pasport_ser from i for 1) between '0' and '9')) then Result_Tmp = 21;
                 i = i + 1;
               end
           end
         else Result_Tmp = 21;
       end
         Else if (v_documcod = 22) then    -- Загран Паспорт
                begin
                  if (char_length(v_pasport_ser) =2) then
                    begin
                      i=1; j=2; result_TMP = 0;
                      while (i <= j) do
                        begin
                          if (not (substring(v_pasport_ser from i for 1) between '0' and '9')) then Result_Tmp = 22;
                          i = i + 1;
                        end
                    End
                  Else Result_Tmp = 22;
                end
              Else if (v_documcod = 3) then  -- свидетельство о рождении
                     begin
                       if (v_gragd_id = 185) then
                         begin
                           v_pasport_ser = Replace(v_pasport_ser,'-','');
                           len_ser = char_length(v_pasport_ser);
                           if ((len_ser >2) and (len_ser <13)) then
                             Begin
                               Result_Tmp = 0; i=1;
                               j = len_ser - 2;
                               while (i <= j) do
                               begin
                                 if (not (substring(v_pasport_ser from i for 1) in ('I','X','L','V','C','D','M'))) then Result_Tmp = 3;
                                 i = i + 1;
                               end
                               if (Result_Tmp = 0) then
                                 Begin
                                   i=j+1; j=i+1;
                                   while (i <= j) do
                                     begin
                                       if (not (substring(v_pasport_ser from i for 1) between 'А' and 'Я')) then Result_Tmp = 3;
                                       i = i + 1;
                                     end
                                 end
                             end
                           else Result_Tmp = 3;
                         end
                       else Result_Tmp = 0;
                     end
                   Else if (v_documcod = 15) then  -- Военный билет
                          begin
                            if (char_length(v_pasport_ser) =2) then
                              Begin
                                i=1; j=2; Result_Tmp = 0;
                                while (i <= j) do
                                  begin
                                    if (Not (substring(v_pasport_ser from i for 1) between 'А' and 'Я')) then Result_Tmp = 15;
                                    i = i + 1;
                                  end
                              end
                            else Result_Tmp = 15;
                          end
                        Else if (v_documcod = 26) then  -- Паспорт моряка
                               begin
                                 if (char_length(v_pasport_ser) =2) then
                                   Begin
                                     i=1; j=2; Result_Tmp = 0;
                                     while (i <= j) do
                                       begin
                                         if (Not (substring(v_pasport_ser from i for 1) in ('I','X','L','V','C','D','M'))) then Result_Tmp = 26;
                                         i = i + 1;
                                       end
                                   end
                                 else Result_Tmp = 26;
                               end

      if (Result_Tmp > 0) then
        begin
          result_comment = result_comment || 'серия документа ' || v_pasport_ser || ' введена не верно';
          Result_Code = Result_Tmp+2000;
          suspend;
          exit;
        End


    --*** определить № паспорта пассажира ***********************
      if (s_pasport_n is null) then v_pasport_n = '';
      else
        begin
          select param_string, string_out
          from sh_fun_from_str_2_substring(:s_pasport_n,'@')
          into :v_pasport_n, :v_strtmp;
          s_pasport_n = v_strtmp;
        end
     if (v_documcod in (3,21)) then
       begin
         if (v_gragd_id = 185) then
           begin
             if (char_length(v_pasport_n) =6) then
               Begin
                 i=1; j=6; result_TMP = 0;
                 while (i <= j) do
                   begin
                     if (not (substring(v_pasport_n from i for 1) between '0' and '9')) then Result_Tmp = v_documcod;
                     i = i + 1;
                   end
               end
             else Result_Tmp = v_documcod;
           End
         else result_TMP = 0;
       end
     Else if (v_documcod = 22) then
       begin
         if (char_length(v_pasport_n) =7) then
           Begin
             i=1; j=7; result_TMP = 0;
             while (i <= j) do
               begin
                 if (not (substring(v_pasport_n from i for 1) between '0' and '9')) then Result_Tmp = 22;
                 i = i + 1;
               end
           end
         else Result_Tmp = 22;
       end
     Else if (v_documcod = 15) then
       begin
         if (char_length(v_pasport_n) =7) then
           Begin
             i=1; j=7; result_TMP = 0;
             while (i <= j) do
               begin
                 if (not (substring(v_pasport_n from i for 1) between '0' and '9')) then Result_Tmp = 15;
                 i = i + 1;
               end
           end
         else Result_Tmp = 15;
       end
     Else if (v_documcod = 26) then
       begin
         if (char_length(v_pasport_n) =7) then
           Begin
             i=1; j=7; result_TMP = 0;
             while (i <= j) do
               begin
                 if (not (substring(v_pasport_n from i for 1) between '0' and '9')) then Result_Tmp = 26;
                 i = i + 1;
               end
           end
         else Result_Tmp = 26;
       end

      if (Result_Tmp > 0) then
        begin
          result_comment = result_comment || 'номер документа ' || v_pasport_n || ' введен не верно';
          Result_Code = Result_Tmp+2000;
          suspend;
          exit;
        End
    End
  suspend;
end