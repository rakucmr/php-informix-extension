"""Shared SVG icons and the API reference table for module pages."""

import html

INFO = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>'
WARN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/></svg>'


def e(value):
    return html.escape(str(value), quote=True)


def api_table(lang):
    if lang == "ro":
        groups = [
            ("Conexiune", [
                ("ifx_connect($database, $userid, $password)", "Deschide o conexiune; cache după DSN + user + parolă."),
                ("ifx_pconnect($database, $userid, $password)", "Conexiune persistentă prin PDO::ATTR_PERSISTENT."),
                ("ifx_close($link = null)", "Închide link-ul dat sau conexiunea implicită."),
            ]),
            ("Interogări și rezultate", [
                ("ifx_query($query, $link, $cursor_type, $blobidarray)", "Prepare + execute; acceptă IFX_SCROLL/IFX_HOLD și blob-uri."),
                ("ifx_prepare($query, $link, $cursor_type, $blobidarray)", "Doar prepare; execuția se face cu ifx_do()."),
                ("ifx_do($result)", "Execută un statement pregătit; poate re-executa cursorul."),
                ("ifx_fetch_row($result, $position = null)", "Următorul rând sau rândul de la poziția dată (scroll)."),
                ("ifx_htmltbl_result($result, $options = null)", "Randează rezultatul ca tabel HTML."),
                ("ifx_free_result($result)", "Eliberează rezultatul și statement-ul."),
                ("ifx_num_rows($result)", "Numărul de rânduri citite până acum."),
                ("ifx_num_fields($result)", "Numărul de coloane."),
                ("ifx_affected_rows($result)", "Rândurile afectate de ultimul DML."),
            ]),
            ("Metadate și erori", [
                ("ifx_fieldtypes($result)", "Map nume coloană → tip SQL (ex. SQLCHAR)."),
                ("ifx_fieldproperties($result)", "Proprietăți: tip, lungime, precizie, scală, nullability."),
                ("ifx_getsqlca($result)", "Approximare a sqlca: sqlerrd1/sqlerrd2."),
                ("ifx_error($link = null)", "Codul de stare al ultimei operații: ' ', W, E sau N."),
                ("ifx_errormsg($errorcode = null)", "Mesajul de eroare pentru codul dat sau ultima eroare."),
            ]),
            ("BLOB / BYTE / TEXT", [
                ("ifx_create_blob($type, $mode, $param)", "Creează un blob: tip 0=BYTE, 1=TEXT; mod 0=memorie, 1=fișier."),
                ("ifx_get_blob($bid)", "Conținutul blob-ului sau calea fișierului în modul blobinfile."),
                ("ifx_update_blob($bid, $content)", "Înlocuiește conținutul unui blob."),
                ("ifx_copy_blob($bid)", "Copiază un blob și întoarce noul id."),
                ("ifx_free_blob($bid)", "Eliberează blob-ul."),
                ("ifx_blobinfile_mode($mode)", "Setează modul global blob: 0=memorie, 1=fișier."),
                ("ifx_textasvarchar($mode)", "TEXT/CLOB ca string, nu ca blob id."),
                ("ifx_byteasvarchar($mode)", "BYTE/BLOB ca string, nu ca blob id."),
                ("ifx_charasvarchar($mode)", "Taie spațiile finale din CHAR."),
                ("ifx_nullformat($mode)", 'NULL ca "" (0) sau "NULL" (1).'),
            ]),
            ("CHAR", [
                ("ifx_create_char($param)", "Creează un handle CHAR."),
                ("ifx_get_char($bid)", "Conținutul handle-ului CHAR."),
                ("ifx_update_char($bid, $content)", "Actualizează handle-ul CHAR."),
                ("ifx_free_char($bid)", "Eliberează handle-ul CHAR."),
            ]),
            ("SLOB (ifxus_*)", [
                ("ifxus_create_slob($mode)", "Creează un SLOB în memorie sau fișier."),
                ("ifxus_open_slob($bid, $mode)", "Deschide un SLOB existent."),
                ("ifxus_close_slob($bid)", "Închide handle-ul SLOB."),
                ("ifxus_tell_slob($bid)", "Poziția curentă în SLOB."),
                ("ifxus_seek_slob($bid, $mode, $offset)", "Deplasează poziția (SEEK_SET/CUR/END)."),
                ("ifxus_read_slob($bid, $nbytes)", "Citește n bytes din SLOB."),
                ("ifxus_write_slob($bid, $content)", "Scrie în SLOB."),
                ("ifxus_free_slob($bid)", "Eliberează SLOB-ul."),
            ]),
        ]
        h_func = "Funcție"
        h_desc = "Descriere"
    else:
        groups = [
            ("Connection", [
                ("ifx_connect($database, $userid, $password)", "Opens a connection; cached by DSN + user + password."),
                ("ifx_pconnect($database, $userid, $password)", "Persistent connection via PDO::ATTR_PERSISTENT."),
                ("ifx_close($link = null)", "Closes the given link or the default connection."),
            ]),
            ("Queries and results", [
                ("ifx_query($query, $link, $cursor_type, $blobidarray)", "Prepare + execute; accepts IFX_SCROLL/IFX_HOLD and blob values."),
                ("ifx_prepare($query, $link, $cursor_type, $blobidarray)", "Prepare only; execute with ifx_do()."),
                ("ifx_do($result)", "Executes a prepared statement; can re-execute a cursor."),
                ("ifx_fetch_row($result, $position = null)", "Next row, or the row at the given scroll position."),
                ("ifx_htmltbl_result($result, $options = null)", "Renders the result as an HTML table."),
                ("ifx_free_result($result)", "Frees the result and the statement."),
                ("ifx_num_rows($result)", "Number of rows fetched so far."),
                ("ifx_num_fields($result)", "Number of columns."),
                ("ifx_affected_rows($result)", "Rows affected by the last DML statement."),
            ]),
            ("Metadata and errors", [
                ("ifx_fieldtypes($result)", "Map column name → SQL type (e.g. SQLCHAR)."),
                ("ifx_fieldproperties($result)", "Properties: type, length, precision, scale, nullability."),
                ("ifx_getsqlca($result)", "Approximation of sqlca: sqlerrd1/sqlerrd2."),
                ("ifx_error($link = null)", "Status of the last operation: ' ', W, E or N."),
                ("ifx_errormsg($errorcode = null)", "Error message for the code, or the last error."),
            ]),
            ("BLOB / BYTE / TEXT", [
                ("ifx_create_blob($type, $mode, $param)", "Creates a blob: type 0=BYTE, 1=TEXT; mode 0=memory, 1=file."),
                ("ifx_get_blob($bid)", "Blob content, or the file path in blobinfile mode."),
                ("ifx_update_blob($bid, $content)", "Replaces blob content."),
                ("ifx_copy_blob($bid)", "Copies a blob and returns the new id."),
                ("ifx_free_blob($bid)", "Frees the blob."),
                ("ifx_blobinfile_mode($mode)", "Global blob mode: 0=memory, 1=file."),
                ("ifx_textasvarchar($mode)", "TEXT/CLOB as string, not a blob id."),
                ("ifx_byteasvarchar($mode)", "BYTE/BLOB as string, not a blob id."),
                ("ifx_charasvarchar($mode)", "Trim trailing spaces from CHAR."),
                ("ifx_nullformat($mode)", 'NULL as "" (0) or "NULL" (1).'),
            ]),
            ("CHAR", [
                ("ifx_create_char($param)", "Creates a CHAR handle."),
                ("ifx_get_char($bid)", "Content of the CHAR handle."),
                ("ifx_update_char($bid, $content)", "Updates the CHAR handle."),
                ("ifx_free_char($bid)", "Frees the CHAR handle."),
            ]),
            ("SLOB (ifxus_*)", [
                ("ifxus_create_slob($mode)", "Creates a SLOB in memory or a file."),
                ("ifxus_open_slob($bid, $mode)", "Opens an existing SLOB."),
                ("ifxus_close_slob($bid)", "Closes the SLOB handle."),
                ("ifxus_tell_slob($bid)", "Current position in the SLOB."),
                ("ifxus_seek_slob($bid, $mode, $offset)", "Moves the position (SEEK_SET/CUR/END)."),
                ("ifxus_read_slob($bid, $nbytes)", "Reads n bytes from the SLOB."),
                ("ifxus_write_slob($bid, $content)", "Writes to the SLOB."),
                ("ifxus_free_slob($bid)", "Frees the SLOB."),
            ]),
        ]
        h_func = "Function"
        h_desc = "Description"
    out = []
    for title, rows in groups:
        body = "".join(f"<tr><td><code>{e(sig)}</code></td><td>{e(desc)}</td></tr>" for sig, desc in rows)
        out.append(
            f'<h3>{e(title)}</h3><div class="table-wrap"><table><thead><tr><th>{e(h_func)}</th><th>{e(h_desc)}</th></tr></thead><tbody>{body}</tbody></table></div>'
        )
    return "".join(out)

