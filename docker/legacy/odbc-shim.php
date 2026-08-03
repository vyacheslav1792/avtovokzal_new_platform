<?php
/**
 * odbc-shim.php — эмуляция odbc_* через НАТИВНЫЙ Firebird (interbase/ibase_*).
 * Подключается глобально (auto_prepend_file), поэтому legacy-код и класс db_work,
 * написанные под ODBC, работают без изменений.
 *
 * Все подключения перенаправляются на локальный firebird-контейнер:
 *   host  -> $FB_HOST (env, по умолчанию "firebird")
 *   user/pass -> $FB_USER/$FB_PASSWORD (по умолчанию SYSDBA/masterkey)
 *   db    -> имя базы (алиас BUSTER/site/ais), извлечённое из ODBC-DSN
 *
 * Это ТОЛЬКО для локального окружения сверки. Боевой код на сервере не меняется.
 */

if (!function_exists('odbc_connect')) {

    if (!defined('SQL_CUR_USE_DRIVER'))  define('SQL_CUR_USE_DRIVER', 2);
    if (!defined('ODBC_BINMODE_RETURN')) define('ODBC_BINMODE_RETURN', 1);

    // Обёртки, чтобы отличать наши "ресурсы" от чужих
    class _FbConn { public $ib; public $trans; }
    class _FbStmt { public $conn; public $sql; public $query; public $result; public $eof = false; }

    // Явная транзакция на соединении: db_work работает в ручном режиме (autocommit=false)
    // и вызывает commitT()/rollbackT(). Без явной транзакции ibase откатывает изменения
    // при закрытии соединения (терялись резервы KS_BRONTIME_PLACE и т.п.).
    function _fb_begin($c) {
        $c->trans = @ibase_trans(IBASE_WRITE | IBASE_COMMITTED | IBASE_REC_VERSION | IBASE_WAIT, $c->ib);
        if (!$c->trans) $c->trans = @ibase_trans($c->ib);
    }

    $GLOBALS['_fb_err'] = '';

    // Из ODBC-DSN "...; DBNAME=host:db" достаём имя БД (после последнего ':').
    // host в legacy бывает вида "10.1.102.18/3050" (порт через '/'), поэтому берём strrpos.
    function _fb_db_from_dsn($dsn) {
        if (preg_match('/DBNAME=([^;]+)/i', $dsn, $m)) {
            $v = trim($m[1]);
            $p = strrpos($v, ':');
            return $p !== false ? substr($v, $p + 1) : $v;
        }
        return $dsn;
    }

    // charSet из DSN (как уважал боевой ODBC): "charSet=UTF-8" -> "UTF8" для Firebird.
    // Нет charSet -> '' (NONE, сырые байты WIN1251 — код сам делает iconv, напр. agents.php).
    function _fb_charset_from_dsn($dsn) {
        if (preg_match('/charSet\s*=\s*([^;]+)/i', $dsn, $m)) {
            return str_replace('-', '', strtoupper(trim($m[1]))); // UTF-8 -> UTF8
        }
        return '';
    }

    function odbc_connect($dsn, $user, $pass, $cur = 2) {
        $db      = _fb_db_from_dsn($dsn);
        $charset = _fb_charset_from_dsn($dsn);
        $host = getenv('FB_HOST') ?: 'firebird';
        $u    = getenv('FB_USER') ?: 'SYSDBA';
        $p    = getenv('FB_PASSWORD') ?: 'masterkey';
        // База BUSTER/наши — WIN1251; при charset=UTF8 Firebird сам конвертирует в UTF-8.
        $ib = @ibase_connect("$host:$db", $u, $p, $charset);
        if (!$ib) { $GLOBALS['_fb_err'] = ibase_errmsg(); return false; }
        $c = new _FbConn(); $c->ib = $ib;
        _fb_begin($c);
        return $c;
    }

    function odbc_close($c) { if ($c instanceof _FbConn) { @ibase_commit($c->trans); @ibase_close($c->ib); } }
    function odbc_autocommit($c, $on = true) { return true; }

    // prepare откладываем до execute: значения нужны, чтобы timestamp-с-миллисекундами
    // подставить в SQL инлайном (ibase при bind в TIMESTAMP-параметр теряет доли секунды —
    // боевой ODBC их сохранял; из-за этого не совпадали резерв timemark и id_time_places).
    function odbc_prepare($c, $sql) {
        $GLOBALS['_fb_err'] = '';
        if (!($c instanceof _FbConn)) return false;
        if (!$c->trans) _fb_begin($c);
        $s = new _FbStmt(); $s->conn = $c; $s->sql = $sql;
        return $s;
    }

    function odbc_execute($s, $values = array()) {
        $GLOBALS['_fb_err'] = '';
        if (!($s instanceof _FbStmt)) return false;
        $vals = array_values((array)$values);

        // Инлайним параметры-таймстампы с долями секунды прямо в SQL, остальные — через bind.
        $sql = $s->sql; $out = ''; $bind = array(); $vi = 0; $n = strlen($sql);
        $inStr = false;
        for ($i = 0; $i < $n; $i++) {
            $ch = $sql[$i];
            if ($ch === "'") $inStr = !$inStr;
            if ($ch === '?' && !$inStr) {
                $v = isset($vals[$vi]) ? $vals[$vi] : null;
                if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $v)) {
                    $out .= "'" . str_replace("'", "''", $v) . "'"; // timestamp-литерал сохраняет мс
                } else {
                    $out .= '?'; $bind[] = $v;
                }
                $vi++;
            } else {
                $out .= $ch;
            }
        }

        $s->query = @ibase_prepare($s->conn->ib, $s->conn->trans, $out);
        if (!$s->query) { $GLOBALS['_fb_err'] = ibase_errmsg(); return false; }
        $args = array_merge(array($s->query), $bind);
        $r = @call_user_func_array('ibase_execute', $args);
        if ($r === false) { $GLOBALS['_fb_err'] = ibase_errmsg(); return false; }
        $s->result = $r;
        $s->eof = false;
        return true;
    }

    function odbc_fetch_array($s, $row = 0) {
        if (!($s instanceof _FbStmt) || $s->eof) return false;
        if (!is_resource($s->result)) return false;
        $r = @ibase_fetch_assoc($s->result, IBASE_TEXT);
        if ($r === false) { $s->eof = true; return false; }
        return $r;
    }

    // commit/rollback явной транзакции + сразу открываем новую (соединение переиспользуется).
    function odbc_commit($c) {
        if (!($c instanceof _FbConn)) return false;
        $ok = @ibase_commit($c->trans);
        _fb_begin($c);
        return $ok !== false;
    }
    function odbc_rollback($c) {
        if (!($c instanceof _FbConn)) return false;
        $ok = @ibase_rollback($c->trans);
        _fb_begin($c);
        return $ok !== false;
    }

    function odbc_error($c = null)     { return $GLOBALS['_fb_err'] ? 'HY000' : ''; }
    function odbc_errormsg($c = null)  { $e = $GLOBALS['_fb_err']; $GLOBALS['_fb_err'] = ''; return $e; }

    function odbc_longreadlen($s, $len) { return true; }
    function odbc_binmode($s, $mode)    { return true; }
    function odbc_num_rows($s)          { return 0; }
    function odbc_free_result($s)       { if ($s instanceof _FbStmt && is_resource($s->result)) @ibase_free_result($s->result); return true; }
}
