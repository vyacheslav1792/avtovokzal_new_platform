--DECLARE VARIABLE S_SQL VARCHAR(2000);
DECLARE VARIABLE N_TICK VARCHAR(9);
DECLARE VARIABLE SER_TICK VARCHAR(12);
DECLARE VARIABLE STR_TMP VARCHAR(600);
DECLARE VARIABLE V_STRTMP VARCHAR(600);
DECLARE VARIABLE SUMMA_BACK NUMERIC(15,2);
DECLARE VARIABLE SUMMA_SHTRAF NUMERIC(15,2);
DECLARE VARIABLE COMMENT_S VARCHAR(300);
DECLARE VARIABLE N_PLACE SMALLINT;
DECLARE VARIABLE DATE_START DATE;
DECLARE VARIABLE TIME_START TIME;
DECLARE VARIABLE KAT_PLACE SMALLINT;
DECLARE VARIABLE PRICE_TICKET NUMERIC(15,2);
DECLARE VARIABLE PRICE_COM NUMERIC(15,2);
DECLARE VARIABLE PRICE_WSTRAH NUMERIC(15,2);
DECLARE VARIABLE PRICE_CASSBOR NUMERIC(15,2);
DECLARE VARIABLE PROC_SHTRAF SMALLINT;
DECLARE VARIABLE NAP_NAME VARCHAR(20);
DECLARE VARIABLE RAICE_NAME VARCHAR(20);
DECLARE VARIABLE START_STAT_NAME VARCHAR(20);
DECLARE VARIABLE ARIVAL_STAT_NAME VARCHAR(20);
DECLARE VARIABLE FAMILY_PAS VARCHAR(20);
DECLARE VARIABLE NAME1_PAS VARCHAR(14);
DECLARE VARIABLE NAME2_PAS VARCHAR(14);
DECLARE VARIABLE DOCUM_TYP VARCHAR(20);
DECLARE VARIABLE DOCUM_SER VARCHAR(20);
DECLARE VARIABLE DOCUM_NOM VARCHAR(25);
DECLARE VARIABLE ERR_CODE SMALLINT;
BEGIN
  DATE_TIME_START = '';
  MARSH_NAME = '';
  START_ARIVAL_STAT_NAME = '';-- от остановки@до остановки@

  --  fam_name1_name2_pas = ''; -- фамилия@имя@отчество@
  tick_fam = '';-- фамилия@
  tick_name1 = '';-- имя@
  tick_name2 = '';-- отчество@

  docum_typ_ser_nom = '';-- документ@серия@номер@
  o_n_ticket = '';
  o_dob_n_ticket = '';
  n_places = '';

  --  KAT_PRICE_sbor = ''; --П@за место@ком@страх@касссб@%удержания@
  tick_kat = '';--П@
  tick_price = '';--за место@
  tick_csbor = '';--ком@
  tick_wstrah = '';--страх@
  tick_cassbor = '';--касссб@
  tick_shtrafproc = '';--%удержания@

  summa_back_full = 0;
  summa_shtraf_full = 0;
  comment_full = '';
  err_code_s = '';

  WHILE (I_N_TICKET <> '') DO
  BEGIN
    SELECT PARAM_STRING,
           STRING_OUT
    FROM SH_FUN_FROM_STR_2_SUBSTRING(:I_N_TICKET, '@')
    INTO :N_TICK,
         :V_STRTMP;
    I_N_TICKET = V_STRTMP;

    SELECT PARAM_STRING,
           STRING_OUT
    FROM SH_FUN_FROM_STR_2_SUBSTRING(:I_DOB_N_TICKET, '@')
    INTO :SER_TICK,
         :V_STRTMP;
    I_DOB_N_TICKET = V_STRTMP;

    SELECT KS_ANN_TICKET.DATE_START,
           KS_ANN_TICKET.TIME_START,
           KS_ANN_TICKET.N_PLACE,
           KS_ANN_TICKET.KAT_PLACE,
           KS_ANN_TICKET.PRICE_TICKET,
           KS_ANN_TICKET.PRICE_COM,
           KS_ANN_TICKET.PRICE_WSTRAH,
           KS_ANN_TICKET.PRICE_CASSBOR,
           KS_ANN_TICKET.O_NOM_TICKET,
           KS_ANN_TICKET.O_SER_TICKET,
           KS_ANN_TICKET.O_SUMM_BACK,
           KS_ANN_TICKET.O_SUMMA_SHTRAF,
           KS_ANN_TICKET.O_PROC_SHTRAF,
           KS_ANN_TICKET.NAP_NAME,
           KS_ANN_TICKET.RAICE_NAME,
           KS_ANN_TICKET.START_STAT_NAME,
           KS_ANN_TICKET.ARIVAL_STAT_NAME,
           KS_ANN_TICKET.FAMILY_PAS,
           KS_ANN_TICKET.NAME1_PAS,
           KS_ANN_TICKET.NAME2_PAS,
           KS_ANN_TICKET.DOCUM_TYP,
           KS_ANN_TICKET.DOCUM_SER,
           KS_ANN_TICKET.DOCUM_NOM,
           KS_ANN_TICKET.O_COMMENT,
           KS_ANN_TICKET.ERR_CODE
    FROM KS_ANN_TICKET(:N_TICK, :SER_TICK, :ONLY_SHOW, :I_TYPE_OPER, :AGENT_COD, :AGENT_WRK, :STRONG_RETURN)
    INTO :DATE_START,
         :TIME_START,
         :N_PLACE,
         :KAT_PLACE,
         :PRICE_TICKET,
         :PRICE_COM,
         :PRICE_WSTRAH,
         :PRICE_CASSBOR,
         :N_TICK,
         :SER_TICK,
         :SUMMA_BACK,
         :SUMMA_SHTRAF,
         :PROC_SHTRAF,
         :NAP_NAME,
         :RAICE_NAME,
         :START_STAT_NAME,
         :ARIVAL_STAT_NAME,
         :FAMILY_PAS,
         :NAME1_PAS,
         :NAME2_PAS,
         :DOCUM_TYP,
         :DOCUM_SER,
         :DOCUM_NOM,
         :COMMENT_S,
         :ERR_CODE;
    IF (ERR_CODE > 0) THEN
    BEGIN
      DATE_TIME_START = DATE_TIME_START || '#@';
      MARSH_NAME = MARSH_NAME || '#@#@';
      START_ARIVAL_STAT_NAME = START_ARIVAL_STAT_NAME || '#@#@';-- от остановки@до остановки@
      --          fam_name1_name2_pas = fam_name1_name2_pas || '#@#@#@'; -- фамилия@имя@отчество@
      TICK_FAM = TICK_FAM || '#@';-- фамилия@
      TICK_NAME1 = TICK_NAME1 || '#@';-- имя@
      TICK_NAME2 = TICK_NAME2 || '#@';-- отчество@

      DOCUM_TYP_SER_NOM = DOCUM_TYP_SER_NOM || '#@#@#@';-- документ@серия@номер@
      N_PLACES = N_PLACES || '#@';

      --          kat_price_sbor = kat_price_sbor || '#@#@#@#@#@#@'; --П@за место@ком@страх@касссб@%удержания@
      TICK_KAT = TICK_KAT || '#@';--П@
      TICK_PRICE = TICK_PRICE || '#@';--за место@
      TICK_CSBOR = TICK_CSBOR || '#@';--ком@
      TICK_WSTRAH = TICK_WSTRAH || '#@';--страх@
      TICK_CASSBOR = TICK_CASSBOR || '#@';--касссб@
      TICK_SHTRAFPROC = TICK_SHTRAFPROC || '#@';--%удержания@

      O_DOB_N_TICKET = O_DOB_N_TICKET || '#@';
      O_N_TICKET = O_N_TICKET || '#@';
    END
    ELSE
    BEGIN
      -- Дата и время отправления рейса **********************************************
      -- Дата отправления рейса ***
      STR_TMP = CAST(EXTRACT(DAY FROM DATE_START) AS CHAR(2));
      STR_TMP = REPLACE(STR_TMP, ' ', '');
      IF (CHAR_LENGTH(STR_TMP) < 2) THEN
        STR_TMP = '0' || STR_TMP;
      DATE_TIME_START = DATE_TIME_START || STR_TMP || '.';

      STR_TMP = CAST(EXTRACT(MONTH FROM DATE_START) AS CHAR(2));
      STR_TMP = REPLACE(STR_TMP, ' ', '');
      IF (CHAR_LENGTH(STR_TMP) < 2) THEN
        STR_TMP = '0' || STR_TMP;
      DATE_TIME_START = DATE_TIME_START || STR_TMP || '.';

      STR_TMP = CAST(EXTRACT(YEAR FROM DATE_START) AS CHAR(4));
      DATE_TIME_START = DATE_TIME_START || STR_TMP || ' ';
      -- Время  отправления рейса ***
      STR_TMP = CAST(EXTRACT(HOUR FROM TIME_START) AS CHAR(2));
      STR_TMP = REPLACE(STR_TMP, ' ', '');
      IF (CHAR_LENGTH(STR_TMP) < 2) THEN
        STR_TMP = '0' || STR_TMP;
      DATE_TIME_START = DATE_TIME_START || STR_TMP || ':';

      STR_TMP = CAST(EXTRACT(MINUTE FROM TIME_START) AS CHAR(2));
      STR_TMP = REPLACE(STR_TMP, ' ', '');
      IF (CHAR_LENGTH(STR_TMP) < 2) THEN
        STR_TMP = '0' || STR_TMP;
      DATE_TIME_START = DATE_TIME_START || STR_TMP || '@';
      --********************************
      --******************************************************************************
      MARSH_NAME = MARSH_NAME || RAICE_NAME || '@' || NAP_NAME || '@';
      START_ARIVAL_STAT_NAME = START_ARIVAL_STAT_NAME || START_STAT_NAME || '@' || ARIVAL_STAT_NAME || '@';-- от остановки@до остановки@
      --          fam_name1_name2_pas = fam_name1_name2_pas || family_pas ||'@'|| name1_pas ||'@'|| name2_pas ||'@'; -- фамилия@имя@отчество@
      TICK_FAM = TICK_FAM || FAMILY_PAS || '@';-- фамилия@
      TICK_NAME1 = TICK_NAME1 || NAME1_PAS || '@';-- имя@
      TICK_NAME2 = TICK_NAME2 || NAME2_PAS || '@';-- отчество@

      DOCUM_TYP_SER_NOM = DOCUM_TYP_SER_NOM || DOCUM_TYP || '@' || DOCUM_SER || '@' || DOCUM_NOM || '@';-- документ@серия@номер@
      N_PLACES = N_PLACES || N_PLACE || '@';
      IF (KAT_PLACE = 0) THEN
        V_STRTMP = 'П';
      ELSE
      IF (KAT_PLACE = 2) THEN
        V_STRTMP = 'Д';
      ELSE
      IF (KAT_PLACE = 3) THEN
        V_STRTMP = 'Л';
      ELSE
      IF ((KAT_PLACE = 5) OR (KAT_PLACE = 6)) THEN
        V_STRTMP = 'В';
      ELSE
      IF (KAT_PLACE = 9) THEN
        V_STRTMP = 'Б';
      ELSE
        V_STRTMP = 'П';

      --          kat_price_sbor = kat_price_sbor || v_strtmp ||'@'||price_ticket ||'@'||
      --                           price_com ||'@'||price_wstrah ||'@'||price_cassbor||'@'||proc_shtraf||'@'; --П@за место@ком@страх@касссб@%удержания@

      TICK_KAT = TICK_KAT || V_STRTMP || '@';--П@
      TICK_PRICE = TICK_PRICE || PRICE_TICKET || '@';--за место@
      TICK_CSBOR = TICK_CSBOR || PRICE_COM || '@';--ком@
      TICK_WSTRAH = TICK_WSTRAH || PRICE_WSTRAH || '@';--страх@
      TICK_CASSBOR = TICK_CASSBOR || PRICE_CASSBOR || '@';--касссб@
      TICK_SHTRAFPROC = TICK_SHTRAFPROC || PROC_SHTRAF || '@';--%удержания@

      O_DOB_N_TICKET = O_DOB_N_TICKET || SER_TICK || '@';
      O_N_TICKET = O_N_TICKET || N_TICK || '@';

      SUMMA_BACK_FULL = SUMMA_BACK_FULL + SUMMA_BACK;
      SUMMA_SHTRAF_FULL = SUMMA_SHTRAF_FULL + SUMMA_SHTRAF;
    END
    COMMENT_FULL = COMMENT_FULL || COMMENT_S || '@';
    ERR_CODE_S = ERR_CODE_S || ERR_CODE || '@';
  END
  SUSPEND;
END