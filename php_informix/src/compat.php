<?php

declare(strict_types=1);

/*
 * Stratul de compatibilitate: funcțiile globale ifx_* / ifxus_* cu aceleași
 * nume și semnături ca extensia ext/informix din PHP 5.1.
 *
 * Totul este definit condiționat, ca includerea fișierului să nu intre în
 * conflict cu o extensie nativă încărcată (sau cu o altă copie a acestui
 * fișier). Funcțiile apelează doar \Informix\Api.
 */

use Informix\Api;

// Valorile din ext/informix (IFX_SCROLL/IFX_HOLD) și din <locator.h> (LO_*).
if (!defined('IFX_SCROLL')) {
    define('IFX_SCROLL', 1);
}
if (!defined('IFX_HOLD')) {
    define('IFX_HOLD', 2);
}
if (!defined('IFX_LO_RDONLY')) {
    define('IFX_LO_RDONLY', 0x4);
}
if (!defined('IFX_LO_WRONLY')) {
    define('IFX_LO_WRONLY', 0x2);
}
if (!defined('IFX_LO_APPEND')) {
    define('IFX_LO_APPEND', 0x1);
}
if (!defined('IFX_LO_RDWR')) {
    define('IFX_LO_RDWR', 0x8);
}
if (!defined('IFX_LO_BUFFER')) {
    define('IFX_LO_BUFFER', 0x200);
}
if (!defined('IFX_LO_NOBUFFER')) {
    define('IFX_LO_NOBUFFER', 0x400);
}

if (!function_exists('ifx_connect')) {
    // ------------------------------------------------------------ conexiune

    function ifx_connect($database = null, $userid = null, $password = null)
    {
        return Api::connect($database, $userid, $password, false, 'ifx_connect') ?? false;
    }

    function ifx_pconnect($database = null, $userid = null, $password = null)
    {
        return Api::connect($database, $userid, $password, true, 'ifx_pconnect') ?? false;
    }

    function ifx_close($link = null)
    {
        return Api::close($link);
    }

    // -------------------------------------------------------------- interogări

    function ifx_query($query, $link = null, $cursor_type = 0, $blobidarray = null)
    {
        return Api::query($query, $link, $cursor_type, $blobidarray, 'ifx_query');
    }

    function ifx_prepare($query, $link = null, $cursor_type = 0, $blobidarray = null)
    {
        return Api::prepare($query, $link, $cursor_type, $blobidarray, 'ifx_prepare');
    }

    function ifx_do($result)
    {
        return Api::do($result, 'ifx_do');
    }

    function ifx_fetch_row($result, $position = null)
    {
        return Api::fetchRow($result, $position, 'ifx_fetch_row');
    }

    function ifx_htmltbl_result($result, $htmltableoptions = null)
    {
        return Api::htmlTable($result, $htmltableoptions, 'ifx_htmltbl_result');
    }

    function ifx_fieldtypes($result)
    {
        return Api::fieldTypes($result, 'ifx_fieldtypes');
    }

    function ifx_fieldproperties($result)
    {
        return Api::fieldProperties($result, 'ifx_fieldproperties');
    }

    function ifx_num_rows($result)
    {
        return Api::numRows($result, 'ifx_num_rows');
    }

    function ifx_num_fields($result)
    {
        return Api::numFields($result, 'ifx_num_fields');
    }

    function ifx_affected_rows($result)
    {
        return Api::affectedRows($result, 'ifx_affected_rows');
    }

    function ifx_free_result($result)
    {
        return Api::freeResult($result, 'ifx_free_result');
    }

    function ifx_getsqlca($result)
    {
        return Api::sqlca($result, 'ifx_getsqlca');
    }

    // ------------------------------------------------------------------ erori

    function ifx_error($link = null)
    {
        return Api::error($link);
    }

    function ifx_errormsg($errorcode = null)
    {
        return Api::errorMessage($errorcode);
    }

    // ------------------------------------------------------------------ BLOB

    function ifx_create_blob($type, $mode, $param)
    {
        return Api::createBlob($type, $mode, $param, 'ifx_create_blob');
    }

    function ifx_copy_blob($bid)
    {
        return Api::copyBlob($bid, 'ifx_copy_blob');
    }

    function ifx_free_blob($bid)
    {
        return Api::freeBlob($bid, 'ifx_free_blob');
    }

    function ifx_get_blob($bid)
    {
        return Api::getBlob($bid, 'ifx_get_blob');
    }

    function ifx_update_blob($bid, $content)
    {
        return Api::updateBlob($bid, $content, 'ifx_update_blob');
    }

    function ifx_blobinfile_mode($mode)
    {
        return Api::blobinfileMode($mode, 'ifx_blobinfile_mode');
    }

    function ifx_textasvarchar($mode)
    {
        return Api::textAsVarchar($mode, 'ifx_textasvarchar');
    }

    function ifx_byteasvarchar($mode)
    {
        return Api::byteAsVarchar($mode, 'ifx_byteasvarchar');
    }

    function ifx_charasvarchar($mode)
    {
        return Api::charAsVarchar($mode, 'ifx_charasvarchar');
    }

    function ifx_nullformat($mode)
    {
        return Api::nullFormat($mode, 'ifx_nullformat');
    }

    // ------------------------------------------------------------------ CHAR

    function ifx_create_char($param)
    {
        return Api::createChar($param, 'ifx_create_char');
    }

    function ifx_get_char($bid)
    {
        return Api::getChar($bid, 'ifx_get_char');
    }

    function ifx_free_char($bid)
    {
        return Api::freeChar($bid, 'ifx_free_char');
    }

    function ifx_update_char($bid, $content)
    {
        return Api::updateChar($bid, $content, 'ifx_update_char');
    }

    // ------------------------------------------------------------------ SLOB

    function ifxus_create_slob($mode)
    {
        return Api::createSlob($mode, 'ifxus_create_slob');
    }

    function ifxus_open_slob($bid, $mode)
    {
        return Api::openSlob($bid, $mode, 'ifxus_open_slob');
    }

    function ifxus_close_slob($bid)
    {
        return Api::closeSlob($bid, 'ifxus_close_slob');
    }

    function ifxus_free_slob($bid)
    {
        return Api::freeSlob($bid, 'ifxus_free_slob');
    }

    function ifxus_tell_slob($bid)
    {
        return Api::tellSlob($bid, 'ifxus_tell_slob');
    }

    function ifxus_seek_slob($bid, $mode, $offset)
    {
        return Api::seekSlob($bid, $mode, $offset, 'ifxus_seek_slob');
    }

    function ifxus_read_slob($bid, $nbytes)
    {
        return Api::readSlob($bid, $nbytes, 'ifxus_read_slob');
    }

    function ifxus_write_slob($bid, $content)
    {
        return Api::writeSlob($bid, $content, 'ifxus_write_slob');
    }
}
