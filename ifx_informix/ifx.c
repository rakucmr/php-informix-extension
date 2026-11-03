/*
 * ifx - direct Informix extension for PHP 8.
 *
 * This module talks to Informix through the Informix Client SDK CLI
 * (SQLDriverConnect / SQLPrepare / SQLExecute / SQLFetchScroll /
 * SQLGetData).  It is independent of pdo_informix: the historical ifx_*
 * API is implemented here directly over CLI, including blobs, CHAR
 * handles, SLOB handles, scroll cursors, metadata, SQLCA and HTML output.
 *
 * Only one of ifx.dll and ifx_pdo.dll can be loaded in a process: both
 * export the same ifx_* symbols.  ifx.dll is the direct implementation;
 * ifx_pdo.dll is the PDO-backed implementation and needs pdo_informix.
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "main/php_open_temporary_file.h"
#include "main/php_streams.h"
#include "zend_exceptions.h"

#include "infxcli.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

/* PHP < 7.3 has no GC_ADDREF/GC_DELREF helpers. */
#if PHP_VERSION_ID < 70300
# define GC_ADDREF(p) (++GC_REFCOUNT(p))
# define GC_DELREF(p) (--GC_REFCOUNT(p))
#endif

#define PHP_IFX_VERSION "1.0.0"
#define IFX_BUF_SIZE 8192

#define IFX_HANDLE_BLOB 1
#define IFX_HANDLE_CHAR 2
#define IFX_HANDLE_SLOB 3

#define IFX_SCROLL_FLAG 1
#define IFX_HOLD_FLAG 2

#define IFX_SLOB_RDONLY 0x0004
#define IFX_SLOB_WRONLY 0x0002
#define IFX_SLOB_RDWR   0x0008
#define IFX_SLOB_APPEND 0x0001
#define IFX_SLOB_BUFFER 0x0200
#define IFX_SLOB_NOBUFFER 0x0400

ZEND_BEGIN_MODULE_GLOBALS(ifx)
    char last_error[1024];
    char last_sqlstate[6];
    int last_sqlcode;
    int blobinfile;
    int textasvarchar;
    int byteasvarchar;
    int charasvarchar;
    int nullformat;
    zend_resource *default_link;
    HashTable *handles;
    HashTable *persistent_links;
    zend_long next_handle;
ZEND_END_MODULE_GLOBALS(ifx)

ZEND_DECLARE_MODULE_GLOBALS(ifx)
#define IFX_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(ifx, v)

static int le_ifx_link;
static int le_ifx_result;

typedef struct {
    SQLHENV henv;
    SQLHDBC hdbc;
    int closed;
} ifx_link;

typedef struct {
    int is_null;
    int is_binary;
    int is_lob;
    char *data;
    size_t len;
    SQLLEN ind;
} ifx_param;

typedef struct {
    SQLHSTMT hstmt;
    ifx_link *link;
    char *sql;
    int cursor_flags;
    int prepared;
    int executed;
    int fetched;
    SQLLEN affected;
    zend_long serial;
    int num_cols;
    char **col_names;
    char **type_names;
    SQLSMALLINT *data_types;
    SQLULEN *col_sizes;
    SQLULEN *precisions;
    SQLSMALLINT *dec_digits;
    SQLSMALLINT *nullable;
    ifx_param *params;
    int param_count;
} ifx_result;

typedef struct {
    int type;
    int subtype;            /* blob: 1 = TEXT, 0 = BYTE */
    int mode;               /* blob: 0 = memory, 1 = temp file */
    int open;
    int flags;
    char *data;
    size_t len;
    size_t cap;
    char *path;
    size_t pos;
} ifx_handle;

static void ifx_clear_error(void)
{
    IFX_G(last_error)[0] = '\0';
    memcpy(IFX_G(last_sqlstate), "00000", 6);
    IFX_G(last_sqlcode) = 0;
}

static void ifx_set_error(const char *state, int code, const char *message)
{
    if (state != NULL && strlen(state) >= 5) {
        memcpy(IFX_G(last_sqlstate), state, 5);
    } else {
        memcpy(IFX_G(last_sqlstate), "HY000", 6);
    }
    IFX_G(last_sqlstate)[5] = '\0';
    IFX_G(last_sqlcode) = code;
    snprintf(IFX_G(last_error), sizeof(IFX_G(last_error)), "%s",
             message != NULL ? message : "");
}

static void ifx_set_end_of_data(void)
{
    ifx_set_error("02000", 100, "No rows found");
}

static void ifx_capture_diag(SQLSMALLINT handle_type, SQLHANDLE handle,
                             const char *where)
{
    SQLCHAR state[6];
    SQLCHAR message[SQL_MAX_MESSAGE_LENGTH];
    SQLINTEGER native = 0;
    SQLSMALLINT message_len = 0;
    SQLRETURN rc;

    memset(state, 0, sizeof(state));
    memset(message, 0, sizeof(message));

    rc = SQLGetDiagRec(handle_type, handle, 1, state, &native, message,
                       (SQLSMALLINT) sizeof(message), &message_len);
    if (rc == SQL_SUCCESS || rc == SQL_SUCCESS_WITH_INFO) {
        if (message_len > 0 && message_len < (SQLSMALLINT) sizeof(message)) {
            message[message_len] = '\0';
        } else {
            message[sizeof(message) - 1] = '\0';
        }
        ifx_set_error((const char *) state, (int) native, (const char *) message);
    } else {
        ifx_set_error("HY000", -1, where != NULL ? where : "Informix CLI error");
    }
}

static void ifx_handle_free(ifx_handle *handle)
{
    if (handle == NULL) {
        return;
    }
    if (handle->path != NULL) {
        remove(handle->path);
        efree(handle->path);
    }
    if (handle->data != NULL) {
        efree(handle->data);
    }
    efree(handle);
}

static void ifx_handle_dtor(zval *zv)
{
    if (zv != NULL && Z_TYPE_P(zv) == IS_PTR) {
        ifx_handle_free((ifx_handle *) Z_PTR_P(zv));
    }
}

static void ifx_params_free(ifx_param *params, int count)
{
    int i;

    if (params == NULL) {
        return;
    }
    for (i = 0; i < count; i++) {
        if (params[i].data != NULL) {
            efree(params[i].data);
        }
    }
    efree(params);
}

static void ifx_result_free(ifx_result *result)
{
    int i;

    if (result == NULL) {
        return;
    }
    if (result->hstmt != SQL_NULL_HSTMT) {
        SQLFreeStmt(result->hstmt, SQL_CLOSE);
        SQLFreeHandle(SQL_HANDLE_STMT, result->hstmt);
        result->hstmt = SQL_NULL_HSTMT;
    }
    if (result->sql != NULL) {
        efree(result->sql);
    }
    if (result->col_names != NULL) {
        for (i = 0; i < result->num_cols; i++) {
            if (result->col_names[i] != NULL) {
                efree(result->col_names[i]);
            }
            if (result->type_names[i] != NULL) {
                efree(result->type_names[i]);
            }
        }
        efree(result->col_names);
        efree(result->type_names);
    }
    if (result->data_types != NULL) {
        efree(result->data_types);
    }
    if (result->col_sizes != NULL) {
        efree(result->col_sizes);
    }
    if (result->precisions != NULL) {
        efree(result->precisions);
    }
    if (result->dec_digits != NULL) {
        efree(result->dec_digits);
    }
    if (result->nullable != NULL) {
        efree(result->nullable);
    }
    ifx_params_free(result->params, result->param_count);
    efree(result);
}

static void ifx_result_dtor(zend_resource *res)
{
    if (res != NULL && res->ptr != NULL) {
        ifx_result_free((ifx_result *) res->ptr);
        res->ptr = NULL;
    }
}

static void ifx_link_dtor(zend_resource *res)
{
    ifx_link *link;

    if (res == NULL || res->ptr == NULL) {
        return;
    }
    link = (ifx_link *) res->ptr;
    if (!link->closed) {
        link->closed = 1;
        if (link->hdbc != SQL_NULL_HDBC) {
            SQLDisconnect(link->hdbc);
            SQLFreeHandle(SQL_HANDLE_DBC, link->hdbc);
            link->hdbc = SQL_NULL_HDBC;
        }
        if (link->henv != SQL_NULL_HENV) {
            SQLFreeHandle(SQL_HANDLE_ENV, link->henv);
            link->henv = SQL_NULL_HENV;
        }
    }
    efree(link);
    res->ptr = NULL;
}

static void ifx_set_default_link(zend_resource *res)
{
    if (IFX_G(default_link) != NULL) {
        GC_DELREF(IFX_G(default_link));
    }
    IFX_G(default_link) = res;
    if (res != NULL) {
        GC_ADDREF(res);
    }
}

static void ifx_clear_default_link(zend_resource *res)
{
    if (IFX_G(default_link) == NULL) {
        return;
    }
    if (res == NULL || IFX_G(default_link) == res) {
        GC_DELREF(IFX_G(default_link));
        IFX_G(default_link) = NULL;
    }
}

/*
 * ifx_pconnect() reuses an open connection with the same DSN/user/password.
 * The cache lives for one request; it mirrors the legacy persistent-link
 * behaviour closely enough for code that expects repeated pconnect calls
 * to return the same link.
 */
static void ifx_persistent_forget(zend_resource *res)
{
    zend_string *key;
    zval *zv;

    if (res == NULL || IFX_G(persistent_links) == NULL) {
        return;
    }
    ZEND_HASH_FOREACH_STR_KEY_VAL(IFX_G(persistent_links), key, zv) {
        if (key != NULL && Z_TYPE_P(zv) == IS_RESOURCE
                && Z_RES_P(zv) == res) {
            zend_hash_del(IFX_G(persistent_links), key);
            return;
        }
    } ZEND_HASH_FOREACH_END();
}

static ifx_link *ifx_link_ptr(zval *zv)
{
    zend_resource *res;

    if (zv == NULL || Z_TYPE_P(zv) != IS_RESOURCE) {
        return NULL;
    }
    res = Z_RES_P(zv);
    if (res->type != le_ifx_link || res->ptr == NULL) {
        return NULL;
    }
    if (((ifx_link *) res->ptr)->closed) {
        return NULL;
    }
    return (ifx_link *) res->ptr;
}

static ifx_result *ifx_result_ptr(zval *zv)
{
    zend_resource *res;

    if (zv == NULL || Z_TYPE_P(zv) != IS_RESOURCE) {
        return NULL;
    }
    res = Z_RES_P(zv);
    if (res->type != le_ifx_result || res->ptr == NULL) {
        return NULL;
    }
    return (ifx_result *) res->ptr;
}

static ifx_link *ifx_default_link_ptr(void)
{
    if (IFX_G(default_link) == NULL || IFX_G(default_link)->ptr == NULL) {
        return NULL;
    }
    return (ifx_link *) IFX_G(default_link)->ptr;
}

static char *ifx_zval_to_cstr(zval *zv)
{
    zend_string *str;
    char *out;

    if (zv == NULL || Z_TYPE_P(zv) == IS_NULL) {
        return NULL;
    }
    str = zval_get_string(zv);
    out = estrndup(ZSTR_VAL(str), ZSTR_LEN(str));
    zend_string_release(str);
    return out;
}

/*
 * Build the CSDK connection string.  The historical "database@server"
 * form and a complete "server=...;database=...;UID=...;PWD=..." DSN are
 * both accepted.
 */
static char *ifx_build_dsn(const char *dsn, const char *user,
                           const char *password)
{
    const char *source = dsn;
    const char *host = getenv("IFX_HOST");
    const char *service = getenv("IFX_SERVICE");
    const char *protocol = getenv("IFX_PROTOCOL");
    char *out;
    size_t size;

    if (source == NULL) {
        source = "";
    }
    if (strncasecmp(source, "informix:", 9) == 0) {
        source += 9;
    } else if (strncasecmp(source, "odbc:", 5) == 0) {
        source += 5;
    }

    if (source[0] != '\0' && strchr(source, '=') != NULL) {
        size = strlen(source) + strlen(user) + strlen(password) + 64;
        out = emalloc(size);
        snprintf(out, size, "%s%sUID=%s;PWD=%s;", source,
                 source[strlen(source) - 1] == ';' ? "" : ";", user, password);
        return out;
    }

    {
        char database[512];
        char server[512];
        const char *at = strchr(source, '@');
        size_t db_len;
        size_t server_len;

        if (at != NULL) {
            db_len = (size_t) (at - source);
            server_len = strlen(at + 1);
        } else {
            db_len = strlen(source);
            server_len = 0;
        }
        if (db_len >= sizeof(database)) {
            db_len = sizeof(database) - 1;
        }
        memcpy(database, source, db_len);
        database[db_len] = '\0';

        if (server_len >= sizeof(server)) {
            server_len = sizeof(server) - 1;
        }
        if (server_len > 0) {
            memcpy(server, at + 1, server_len);
        } else {
            const char *env_server = getenv("INFORMIXSERVER");
            server_len = env_server != NULL ? strlen(env_server) : 0;
            if (server_len >= sizeof(server)) {
                server_len = sizeof(server) - 1;
            }
            if (server_len > 0) {
                memcpy(server, env_server, server_len);
            }
        }
        server[server_len] = '\0';

        size = strlen(database) + strlen(server) + strlen(user) + strlen(password)
             + (host != NULL ? strlen(host) : 0)
             + (service != NULL ? strlen(service) : 0)
             + (protocol != NULL ? strlen(protocol) : 0) + 192;
        out = emalloc(size);
        snprintf(out, size, "server=%s;database=%s;%s%s%s%s%s%sUID=%s;PWD=%s;",
                 server, database,
                 host != NULL ? "host=" : "", host != NULL ? host : "",
                 service != NULL ? ";service=" : "", service != NULL ? service : "",
                 protocol != NULL ? ";protocol=" : "", protocol != NULL ? protocol : "",
                 user, password);
        return out;
    }
}

static const char *ifx_type_from_native(const char *native, SQLSMALLINT type)
{
    char lower[128];
    size_t i;
    size_t len;

    if (native != NULL) {
        len = strlen(native);
        if (len >= sizeof(lower)) {
            len = sizeof(lower) - 1;
        }
        for (i = 0; i < len; i++) {
            lower[i] = (char) tolower((unsigned char) native[i]);
        }
        lower[len] = '\0';

        if (strstr(lower, "serial8") != NULL) return "SQLSERIAL8";
        if (strstr(lower, "bigserial") != NULL) return "SQLBIGSERIAL";
        if (strstr(lower, "serial") != NULL) return "SQLSERIAL";
        if (strstr(lower, "datetime") != NULL) return "SQLDTIME";
        if (strstr(lower, "interval") != NULL) return "SQLINTERVAL";
        if (strstr(lower, "lvarchar") != NULL) return "SQLLVARCHAR";
        if (strstr(lower, "nvarchar") != NULL) return "SQLNVCHAR";
        if (strstr(lower, "varchar") != NULL) return "SQLVCHAR";
        if (strstr(lower, "nchar") != NULL) return "SQLNCHAR";
        if (strstr(lower, "char") != NULL) return "SQLCHAR";
        if (strstr(lower, "boolean") != NULL || strstr(lower, "bool") != NULL) return "SQLBOOL";
        if (strstr(lower, "text") != NULL || strstr(lower, "clob") != NULL) return "SQLTEXT";
        if (strstr(lower, "byte") != NULL || strstr(lower, "blob") != NULL
                || strstr(lower, "json") != NULL || strstr(lower, "bson") != NULL) {
            return "SQLBYTES";
        }
        if (strstr(lower, "decimal") != NULL || strstr(lower, "numeric") != NULL) return "SQLDECIMAL";
        if (strstr(lower, "money") != NULL) return "SQLMONEY";
        if (strstr(lower, "smallfloat") != NULL) return "SQLSMFLOAT";
        if (strstr(lower, "float") != NULL || strstr(lower, "double") != NULL) return "SQLFLOAT";
        if (strstr(lower, "int8") != NULL || strstr(lower, "bigint") != NULL) return "SQLINT8";
        if (strstr(lower, "smallint") != NULL) return "SQLSMINT";
        if (strstr(lower, "integer") != NULL) return "SQLINT";
        if (strstr(lower, "date") != NULL) return "SQLDATE";
        if (strstr(lower, "time") != NULL) return "SQLDTIME";
    }

    switch (type) {
        case SQL_CHAR:
        case SQL_WCHAR:
            return "SQLCHAR";
        case SQL_VARCHAR:
        case SQL_WVARCHAR:
            return "SQLVCHAR";
        case SQL_LONGVARCHAR:
        case SQL_WLONGVARCHAR:
            return "SQLTEXT";
        case SQL_BINARY:
        case SQL_VARBINARY:
        case SQL_LONGVARBINARY:
            return "SQLBYTES";
        case SQL_DECIMAL:
        case SQL_NUMERIC:
            return "SQLDECIMAL";
        case SQL_INTEGER:
            return "SQLINT";
        case SQL_SMALLINT:
            return "SQLSMINT";
        case SQL_BIGINT:
            return "SQLINT8";
        case SQL_REAL:
            return "SQLSMFLOAT";
        case SQL_FLOAT:
        case SQL_DOUBLE:
            return "SQLFLOAT";
        case SQL_DATE:
            return "SQLDATE";
        case SQL_TIME:
        case SQL_TIMESTAMP:
            return "SQLDTIME";
        case SQL_BIT:
            return "SQLBOOL";
        default:
            return "SQLUNKNOWN";
    }
}

static int ifx_type_is_char(const char *type)
{
    return strcmp(type, "SQLCHAR") == 0 || strcmp(type, "SQLNCHAR") == 0;
}

static int ifx_type_is_text(const char *type)
{
    return strcmp(type, "SQLTEXT") == 0;
}

static int ifx_type_is_byte(const char *type)
{
    return strcmp(type, "SQLBYTES") == 0;
}

static int ifx_type_is_bool(const char *type)
{
    return strcmp(type, "SQLBOOL") == 0;
}

static void ifx_result_clear_metadata(ifx_result *result)
{
    int i;

    if (result->col_names != NULL) {
        for (i = 0; i < result->num_cols; i++) {
            if (result->col_names[i] != NULL) efree(result->col_names[i]);
            if (result->type_names[i] != NULL) efree(result->type_names[i]);
        }
        efree(result->col_names);
        efree(result->type_names);
        result->col_names = NULL;
        result->type_names = NULL;
    }
    if (result->data_types != NULL) {
        efree(result->data_types);
        result->data_types = NULL;
    }
    if (result->col_sizes != NULL) {
        efree(result->col_sizes);
        result->col_sizes = NULL;
    }
    if (result->precisions != NULL) {
        efree(result->precisions);
        result->precisions = NULL;
    }
    if (result->dec_digits != NULL) {
        efree(result->dec_digits);
        result->dec_digits = NULL;
    }
    if (result->nullable != NULL) {
        efree(result->nullable);
        result->nullable = NULL;
    }
    result->num_cols = 0;
}

static int ifx_result_load_metadata(ifx_result *result)
{
    SQLSMALLINT count = 0;
    SQLRETURN rc;
    int i;

    if (result == NULL || result->hstmt == SQL_NULL_HSTMT) {
        return 0;
    }
    rc = SQLNumResultCols(result->hstmt, &count);
    if (rc == SQL_ERROR) {
        ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLNumResultCols");
        return 0;
    }
    if (count < 0) {
        count = 0;
    }

    ifx_result_clear_metadata(result);
    result->num_cols = (int) count;
    if (result->num_cols == 0) {
        return 1;
    }

    result->col_names = ecalloc((size_t) result->num_cols, sizeof(char *));
    result->type_names = ecalloc((size_t) result->num_cols, sizeof(char *));
    result->data_types = ecalloc((size_t) result->num_cols, sizeof(SQLSMALLINT));
    result->col_sizes = ecalloc((size_t) result->num_cols, sizeof(SQLULEN));
    result->precisions = ecalloc((size_t) result->num_cols, sizeof(SQLULEN));
    result->dec_digits = ecalloc((size_t) result->num_cols, sizeof(SQLSMALLINT));
    result->nullable = ecalloc((size_t) result->num_cols, sizeof(SQLSMALLINT));

    for (i = 0; i < result->num_cols; i++) {
        SQLCHAR name[256];
        SQLCHAR type_name[128];
        SQLSMALLINT name_len = 0;
        SQLSMALLINT type_len = 0;
        SQLSMALLINT data_type = 0;
        SQLSMALLINT dec = 0;
        SQLSMALLINT nullable = SQL_NULLABLE_UNKNOWN;
        SQLULEN size = 0;
        SQLLEN precision = 0;
        SQLLEN scale = 0;
        const char *mapped;
        size_t j;
        char fallback[64];

        memset(name, 0, sizeof(name));
        memset(type_name, 0, sizeof(type_name));

        rc = SQLDescribeCol(result->hstmt, (SQLUSMALLINT) (i + 1), name,
                            (SQLSMALLINT) sizeof(name), &name_len, &data_type,
                            &size, &dec, &nullable);
        if (rc == SQL_ERROR) {
            ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLDescribeCol");
            return 0;
        }
        if (name_len < 0 || name_len >= (SQLSMALLINT) sizeof(name)) {
            name_len = (SQLSMALLINT) sizeof(name) - 1;
        }
        name[name_len] = '\0';

        if (name[0] == '\0' || strcmp((const char *) name, "(expression)") == 0) {
            snprintf(fallback, sizeof(fallback), "[Expr_%d]", i + 1);
            result->col_names[i] = estrdup(fallback);
        } else {
            for (j = 0; j < (size_t) name_len; j++) {
                name[j] = (SQLCHAR) tolower((unsigned char) name[j]);
            }
            result->col_names[i] = estrdup((const char *) name);
        }

        if (SQLColAttribute(result->hstmt, (SQLUSMALLINT) (i + 1),
                            SQL_DESC_TYPE_NAME, type_name,
                            (SQLSMALLINT) sizeof(type_name), &type_len, NULL)
                == SQL_SUCCESS) {
            if (type_len < 0 || type_len >= (SQLSMALLINT) sizeof(type_name)) {
                type_len = (SQLSMALLINT) sizeof(type_name) - 1;
            }
            type_name[type_len] = '\0';
        }

        mapped = ifx_type_from_native((const char *) type_name, data_type);
        result->type_names[i] = estrdup(mapped);
        result->data_types[i] = data_type;
        result->col_sizes[i] = size;
        result->nullable[i] = nullable;

        if (SQLColAttribute(result->hstmt, (SQLUSMALLINT) (i + 1),
                            SQL_DESC_PRECISION, &precision, 0, NULL, NULL)
                == SQL_SUCCESS && precision >= 0) {
            result->precisions[i] = (SQLULEN) precision;
        } else {
            result->precisions[i] = size;
        }
        if (SQLColAttribute(result->hstmt, (SQLUSMALLINT) (i + 1),
                            SQL_DESC_SCALE, &scale, 0, NULL, NULL)
                == SQL_SUCCESS && scale >= 0) {
            result->dec_digits[i] = (SQLSMALLINT) scale;
        } else {
            result->dec_digits[i] = dec;
        }
    }
    return 1;
}

static int ifx_handle_lookup_silent(zend_long id, ifx_handle **out)
{
    zval *zv;

    if (IFX_G(handles) == NULL) {
        return 0;
    }
    zv = zend_hash_index_find(IFX_G(handles), (zend_ulong) id);
    if (zv == NULL || Z_TYPE_P(zv) != IS_PTR || Z_PTR_P(zv) == NULL) {
        return 0;
    }
    *out = (ifx_handle *) Z_PTR_P(zv);
    return 1;
}

static zend_long ifx_handle_add(ifx_handle *handle)
{
    zend_long id;
    zval zv;

    if (IFX_G(handles) == NULL) {
        ifx_handle_free(handle);
        return -1;
    }
    id = IFX_G(next_handle)++;
    while (zend_hash_index_exists(IFX_G(handles), (zend_ulong) id)) {
        id = IFX_G(next_handle)++;
    }
    ZVAL_PTR(&zv, handle);
    if (zend_hash_index_add(IFX_G(handles), (zend_ulong) id, &zv) == NULL) {
        ifx_handle_free(handle);
        return -1;
    }
    return id;
}

static int ifx_params_from_zval(zval *arr, ifx_param **out, int *out_count,
                                 const char *fn)
{
    zval *value;
    ifx_param *params;
    int count;
    int i = 0;

    *out = NULL;
    *out_count = 0;
    if (arr == NULL || Z_TYPE_P(arr) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(arr) != IS_ARRAY) {
        php_error_docref(NULL, E_WARNING, "%s(): parameters must be an array", fn);
        return 0;
    }

    count = (int) zend_hash_num_elements(Z_ARRVAL_P(arr));
    if (count == 0) {
        return 1;
    }
    params = ecalloc((size_t) count, sizeof(ifx_param));

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(arr), value) {
        zval *v = value;
        ifx_handle *handle = NULL;
        char number[64];

        if (Z_TYPE_P(v) == IS_REFERENCE) {
            v = Z_REFVAL_P(v);
        }
        if (Z_TYPE_P(v) == IS_NULL) {
            params[i].is_null = 1;
            params[i].ind = SQL_NULL_DATA;
            i++;
            continue;
        }
        if (Z_TYPE_P(v) == IS_LONG && ifx_handle_lookup_silent(Z_LVAL_P(v), &handle)) {
            params[i].is_binary = (handle->type == IFX_HANDLE_BLOB && handle->subtype == 0);
            params[i].is_lob = (handle->type == IFX_HANDLE_BLOB || handle->type == IFX_HANDLE_SLOB);
            params[i].len = handle->len;
            params[i].data = emalloc(handle->len + 1);
            if (handle->len > 0 && handle->data != NULL) {
                memcpy(params[i].data, handle->data, handle->len);
            }
            params[i].data[handle->len] = '\0';
            params[i].ind = (SQLLEN) handle->len;
            i++;
            continue;
        }
        if (Z_TYPE_P(v) == IS_LONG) {
            snprintf(number, sizeof(number), ZEND_LONG_FMT, Z_LVAL_P(v));
            params[i].data = estrdup(number);
            params[i].len = strlen(number);
        } else if (Z_TYPE_P(v) == IS_DOUBLE) {
            snprintf(number, sizeof(number), "%.17g", Z_DVAL_P(v));
            params[i].data = estrdup(number);
            params[i].len = strlen(number);
        } else if (Z_TYPE_P(v) == IS_TRUE) {
            params[i].data = estrdup("1");
            params[i].len = 1;
        } else if (Z_TYPE_P(v) == IS_FALSE) {
            params[i].data = estrdup("0");
            params[i].len = 1;
        } else {
            zend_string *str = zval_get_string(v);
            params[i].data = estrndup(ZSTR_VAL(str), ZSTR_LEN(str));
            params[i].len = ZSTR_LEN(str);
            zend_string_release(str);
        }
        params[i].ind = (SQLLEN) params[i].len;
        i++;
    } ZEND_HASH_FOREACH_END();

    *out = params;
    *out_count = i;
    return 1;
}

static int ifx_bind_params(ifx_result *result)
{
    int i;

    for (i = 0; i < result->param_count; i++) {
        ifx_param *p = &result->params[i];
        SQLSMALLINT ctype = p->is_binary ? SQL_C_BINARY : SQL_C_CHAR;
        SQLSMALLINT sqltype;
        SQLULEN column_size;
        SQLULEN buffer_size;
        SQLPOINTER value_ptr;
        SQLLEN *ind_ptr;
        SQLRETURN rc;

        if (p->is_null) {
            sqltype = p->is_binary ? SQL_LONGVARBINARY : SQL_LONGVARCHAR;
            column_size = 0;
            buffer_size = 0;
            value_ptr = NULL;
            p->ind = SQL_NULL_DATA;
        } else {
            if (p->is_binary) {
                sqltype = SQL_LONGVARBINARY;
            } else if (p->is_lob || p->len > 4000) {
                sqltype = SQL_LONGVARCHAR;
            } else {
                sqltype = SQL_VARCHAR;
            }
            column_size = (SQLULEN) (p->len > 0 ? p->len : 1);
            buffer_size = (SQLULEN) p->len;
            value_ptr = p->data;
            p->ind = (SQLLEN) p->len;
        }
        ind_ptr = &p->ind;

        rc = SQLBindParameter(result->hstmt, (SQLUSMALLINT) (i + 1),
                              SQL_PARAM_INPUT, ctype, sqltype, column_size, 0,
                              value_ptr, buffer_size, ind_ptr);
        if (rc == SQL_ERROR) {
            ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLBindParameter");
            return 0;
        }
    }
    return 1;
}

static int ifx_sql_dml(const char *sql)
{
    const char *p = sql;
    char keyword[16];
    size_t n = 0;

    if (p == NULL) {
        return 0;
    }
    while (*p != '\0') {
        while (isspace((unsigned char) *p)) p++;
        if (p[0] == '-' && p[1] == '-') {
            while (*p != '\0' && *p != '\n') p++;
            continue;
        }
        if (p[0] == '/' && p[1] == '*') {
            p += 2;
            while (*p != '\0' && !(p[0] == '*' && p[1] == '/')) p++;
            if (*p != '\0') p += 2;
            continue;
        }
        break;
    }
    while (*p != '\0' && isalpha((unsigned char) *p) && n + 1 < sizeof(keyword)) {
        keyword[n++] = (char) tolower((unsigned char) *p++);
    }
    keyword[n] = '\0';
    return strcmp(keyword, "insert") == 0 || strcmp(keyword, "update") == 0
        || strcmp(keyword, "delete") == 0 || strcmp(keyword, "merge") == 0;
}

static int ifx_sql_insert(const char *sql)
{
    const char *p = sql;
    char keyword[16];
    size_t n = 0;

    if (p == NULL) {
        return 0;
    }
    while (*p != '\0') {
        while (isspace((unsigned char) *p)) p++;
        if (p[0] == '-' && p[1] == '-') {
            while (*p != '\0' && *p != '\n') p++;
            continue;
        }
        if (p[0] == '/' && p[1] == '*') {
            p += 2;
            while (*p != '\0' && !(p[0] == '*' && p[1] == '/')) p++;
            if (*p != '\0') p += 2;
            continue;
        }
        break;
    }
    while (*p != '\0' && isalpha((unsigned char) *p) && n + 1 < sizeof(keyword)) {
        keyword[n++] = (char) tolower((unsigned char) *p++);
    }
    keyword[n] = '\0';
    return strcmp(keyword, "insert") == 0;
}

/*
 * Read sqlerrd[1] and sqlerrd[2] from the session.  SQLRowCount can be
 * zero for INSERT statements that bind LOB parameters, so the old SQLCA
 * counters are still the most reliable source.
 */
static void ifx_session_sqlca(ifx_link *link, zend_long *serial, SQLLEN *affected)
{
    static const char query[] =
        "SELECT dbinfo('sqlca.sqlerrd1'), dbinfo('sqlca.sqlerrd2') "
        "FROM systables WHERE tabid = 1";
    SQLHSTMT stmt = SQL_NULL_HSTMT;
    SQLRETURN rc;
    char buf[64];
    SQLLEN ind = 0;
    int col;

    *serial = 0;
    *affected = 0;
    if (link == NULL || link->hdbc == SQL_NULL_HDBC) {
        return;
    }
    rc = SQLAllocHandle(SQL_HANDLE_STMT, link->hdbc, &stmt);
    if (rc == SQL_ERROR) {
        return;
    }
    rc = SQLExecDirect(stmt, (SQLCHAR *) query, SQL_NTS);
    if (rc != SQL_ERROR && rc != SQL_NO_DATA && SQLFetch(stmt) != SQL_NO_DATA) {
        for (col = 1; col <= 2; col++) {
            memset(buf, 0, sizeof(buf));
            ind = 0;
            rc = SQLGetData(stmt, (SQLUSMALLINT) col, SQL_C_CHAR,
                            buf, sizeof(buf) - 1, &ind);
            if (rc == SQL_ERROR || ind == SQL_NULL_DATA) {
                continue;
            }
            buf[sizeof(buf) - 1] = '\0';
            if (col == 1) {
                *serial = ZEND_STRTOL(buf, NULL, 10);
            } else {
                *affected = (SQLLEN) ZEND_STRTOL(buf, NULL, 10);
            }
        }
    }
    SQLFreeHandle(SQL_HANDLE_STMT, stmt);
}

static ifx_result *ifx_result_new(ifx_link *link, const char *sql,
                                  int cursor_flags, zval *params,
                                  const char *fn)
{
    ifx_result *result;
    SQLRETURN rc;

    result = ecalloc(1, sizeof(ifx_result));
    result->hstmt = SQL_NULL_HSTMT;
    result->link = link;
    result->cursor_flags = cursor_flags;
    result->sql = estrdup(sql != NULL ? sql : "");
    result->affected = 0;
    result->serial = 0;

    rc = SQLAllocHandle(SQL_HANDLE_STMT, link->hdbc, &result->hstmt);
    if (rc == SQL_ERROR) {
        ifx_capture_diag(SQL_HANDLE_DBC, link->hdbc, "SQLAllocHandle(STMT)");
        ifx_result_free(result);
        return NULL;
    }

    if ((cursor_flags & IFX_SCROLL_FLAG) != 0) {
        rc = SQLSetStmtAttr(result->hstmt, SQL_ATTR_CURSOR_TYPE,
                            (SQLPOINTER) SQL_CURSOR_DYNAMIC, 0);
        if (rc == SQL_ERROR) {
            ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLSetStmtAttr(CURSOR_TYPE)");
            ifx_result_free(result);
            return NULL;
        }
    }

    if (!ifx_params_from_zval(params, &result->params, &result->param_count, fn)) {
        ifx_result_free(result);
        return NULL;
    }

    rc = SQLPrepare(result->hstmt, (SQLCHAR *) result->sql, SQL_NTS);
    if (rc == SQL_ERROR) {
        ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLPrepare");
        ifx_result_free(result);
        return NULL;
    }
    result->prepared = 1;
    if (!ifx_result_load_metadata(result)) {
        ifx_result_free(result);
        return NULL;
    }
    return result;
}

static int ifx_result_execute(ifx_result *result)
{
    SQLRETURN rc;
    SQLLEN row_count = 0;
    SQLINTEGER serial = 0;

    if (result == NULL || result->hstmt == SQL_NULL_HSTMT || !result->prepared) {
        return 0;
    }
    if (result->executed) {
        SQLFreeStmt(result->hstmt, SQL_CLOSE);
        result->executed = 0;
    }
    if (!ifx_bind_params(result)) {
        return 0;
    }

    rc = SQLExecute(result->hstmt);
    if (rc == SQL_ERROR) {
        ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLExecute");
        return 0;
    }
    result->executed = 1;
    result->fetched = 0;
    result->affected = 0;

    if (result->num_cols == 0) {
        if (!ifx_result_load_metadata(result)) {
            return 0;
        }
    }

    if (SQLRowCount(result->hstmt, &row_count) == SQL_SUCCESS && row_count > 0) {
        result->affected = row_count;
    }
    SQLGetStmtAttr(result->hstmt, SQL_GET_SERIAL_VALUE, &serial,
                   SQL_IS_INTEGER, NULL);
    if (serial > 0) {
        result->serial = (zend_long) serial;
    }

    if (ifx_sql_dml(result->sql)
            && (result->affected <= 0 || (ifx_sql_insert(result->sql) && result->serial == 0))) {
        zend_long session_serial = 0;
        SQLLEN session_affected = 0;
        ifx_session_sqlca(result->link, &session_serial, &session_affected);
        if (session_serial > 0 && result->serial == 0) {
            result->serial = session_serial;
        }
        if (session_affected > 0 && result->affected <= 0) {
            result->affected = session_affected;
        }
    }
    /* An INSERT with LOB parameters may report no count at all. */
    if (result->affected <= 0 && ifx_sql_insert(result->sql)) {
        int has_lob = 0;
        int i;

        for (i = 0; i < result->param_count; i++) {
            if (result->params[i].is_lob) {
                has_lob = 1;
                break;
            }
        }
        if (has_lob) {
            result->affected = 1;
        }
    }
    ifx_clear_error();
    return 1;
}

static int ifx_column_is_lob(ifx_result *result, int index)
{
    const char *type = result->type_names[index];
    SQLSMALLINT sqltype = result->data_types[index];

    if (ifx_type_is_text(type) || ifx_type_is_byte(type)) {
        return 1;
    }
    if (sqltype == SQL_LONGVARCHAR || sqltype == SQL_WLONGVARCHAR
            || sqltype == SQL_LONGVARBINARY || sqltype == SQL_INFX_UDT_FIXED) {
        return 1;
    }
    return 0;
}

static int ifx_column_is_binary(ifx_result *result, int index)
{
    const char *type = result->type_names[index];
    SQLSMALLINT sqltype = result->data_types[index];

    if (ifx_type_is_byte(type)) {
        return 1;
    }
    if (sqltype == SQL_BINARY || sqltype == SQL_VARBINARY
            || sqltype == SQL_LONGVARBINARY) {
        return 1;
    }
    if (sqltype == SQL_INFX_UDT_FIXED && !ifx_type_is_text(type)) {
        return 1;
    }
    return 0;
}

static int ifx_column_is_text(ifx_result *result, int index)
{
    const char *type = result->type_names[index];
    SQLSMALLINT sqltype = result->data_types[index];

    if (ifx_type_is_text(type)) {
        return 1;
    }
    return sqltype == SQL_LONGVARCHAR || sqltype == SQL_WLONGVARCHAR;
}

/*
 * Read one column as bytes.  SQLGetData is used instead of SQLBindCol so
 * TEXT/BYTE values and long VARCHARs are not truncated and no fixed
 * per-column buffer has to be guessed.
 */
static int ifx_get_column(ifx_result *result, int index, zval *out)
{
    char chunk[IFX_BUF_SIZE];
    char *data = NULL;
    size_t len = 0;
    size_t cap = 0;
    SQLLEN ind = 0;
    SQLRETURN rc;
    int is_null = 0;
    int is_lob = ifx_column_is_lob(result, index);
    int is_binary = ifx_column_is_binary(result, index);
    int is_text = ifx_column_is_text(result, index);
    SQLSMALLINT ctype;
    size_t max_read;
    const char *type_name;

    if (is_text) {
        ctype = SQL_C_CHAR;
        max_read = sizeof(chunk) - 1;
    } else if (is_binary) {
        ctype = SQL_C_BINARY;
        max_read = sizeof(chunk);
    } else {
        ctype = SQL_C_CHAR;
        max_read = sizeof(chunk) - 1;
    }

    for (;;) {
        size_t got;
        SQLLEN available;

        memset(chunk, 0, sizeof(chunk));
        ind = 0;
        rc = SQLGetData(result->hstmt, (SQLUSMALLINT) (index + 1),
                        ctype, chunk, sizeof(chunk), &ind);
        if (rc == SQL_NO_DATA) {
            break;
        }
        if (rc == SQL_ERROR) {
            ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLGetData");
            if (data != NULL) efree(data);
            return 0;
        }
        if (ind == SQL_NULL_DATA) {
            is_null = 1;
            break;
        }
        if (ind == SQL_NO_TOTAL) {
            got = (ctype == SQL_C_CHAR) ? strlen(chunk) : max_read;
        } else if (ind < 0) {
            got = 0;
        } else if ((size_t) ind < max_read) {
            got = (size_t) ind;
        } else {
            got = max_read;
        }
        if (got > max_read) {
            got = max_read;
        }
        if (got > 0) {
            if (len + got + 1 > cap) {
                size_t new_cap = cap == 0 ? 256 : cap * 2;
                while (new_cap < len + got + 1) {
                    new_cap *= 2;
                }
                data = data == NULL ? emalloc(new_cap) : erealloc(data, new_cap);
                cap = new_cap;
            }
            memcpy(data + len, chunk, got);
            len += got;
        }

        if (rc == SQL_SUCCESS) {
            break;
        }
        /* SQL_SUCCESS_WITH_INFO: continue until the last chunk. */
        available = ind;
        if (available >= 0 && (size_t) available <= max_read) {
            break;
        }
        if (got == 0) {
            break;
        }
    }

    if (is_null) {
        if (data != NULL) efree(data);
        if (IFX_G(nullformat)) {
            ZVAL_STRING(out, "NULL");
        } else {
            ZVAL_NULL(out);
        }
        return 1;
    }
    if (data == NULL) {
        data = emalloc(1);
        data[0] = '\0';
        len = 0;
        cap = 1;
    } else {
        data[len] = '\0';
    }

    type_name = result->type_names[index];

    if (ifx_type_is_bool(type_name)) {
        if ((len == 1 && (data[0] == 't' || data[0] == 'T'))
                || (len == 4 && strncasecmp(data, "true", 4) == 0)) {
            efree(data);
            ZVAL_STRING(out, "1");
            return 1;
        }
        if ((len == 1 && (data[0] == 'f' || data[0] == 'F'))
                || (len == 5 && strncasecmp(data, "false", 5) == 0)) {
            efree(data);
            ZVAL_STRING(out, "0");
            return 1;
        }
    }

    if (IFX_G(charasvarchar) && ifx_type_is_char(type_name)) {
        while (len > 0 && data[len - 1] == ' ') {
            len--;
        }
    }

    if (is_lob) {
        int text = ifx_column_is_text(result, index)
                || (ifx_type_is_text(type_name) && !is_binary);
        if ((text && IFX_G(textasvarchar)) || (!text && IFX_G(byteasvarchar))) {
            ZVAL_STRINGL(out, data, len);
            efree(data);
            return 1;
        }
        {
            ifx_handle *handle = ecalloc(1, sizeof(ifx_handle));
            handle->type = IFX_HANDLE_BLOB;
            handle->subtype = text ? 1 : 0;
            handle->mode = IFX_G(blobinfile) ? 1 : 0;
            handle->data = data;
            handle->len = len;
            handle->cap = cap;
            if (handle->mode == 1) {
                zend_string *path = NULL;
                FILE *fp = php_open_temporary_file(NULL, "ifx", &path);
                if (fp == NULL) {
                    if (path != NULL) zend_string_release(path);
                    ifx_handle_free(handle);
                    ifx_set_error("HY000", -1, "Cannot create temporary file for BLOB");
                    return 0;
                }
                if (len > 0 && fwrite(handle->data, 1, len, fp) != len) {
                    fclose(fp);
                    if (path != NULL) {
                        remove(ZSTR_VAL(path));
                        zend_string_release(path);
                    }
                    ifx_handle_free(handle);
                    ifx_set_error("HY000", -1, "Cannot write temporary file for BLOB");
                    return 0;
                }
                fclose(fp);
                if (path != NULL) {
                    handle->path = estrndup(ZSTR_VAL(path), ZSTR_LEN(path));
                    zend_string_release(path);
                }
            }
            ZVAL_LONG(out, ifx_handle_add(handle));
            return 1;
        }
    }

    ZVAL_STRINGL(out, data, len);
    efree(data);
    return 1;
}

static int ifx_fetch_direction(zval *position, int scroll,
                               SQLSMALLINT *direction, SQLLEN *offset,
                               const char *fn)
{
    *direction = SQL_FETCH_NEXT;
    *offset = 0;

    if (!scroll || position == NULL || Z_TYPE_P(position) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE_P(position) == IS_LONG) {
        zend_long value = Z_LVAL_P(position);
        if (value == 0) {
            *direction = SQL_FETCH_NEXT;
        } else {
            *direction = SQL_FETCH_ABSOLUTE;
            *offset = (SQLLEN) value;
        }
        return 1;
    }
    if (Z_TYPE_P(position) == IS_STRING) {
        const char *value = Z_STRVAL_P(position);
        if (strcasecmp(value, "NEXT") == 0) {
            *direction = SQL_FETCH_NEXT;
        } else if (strcasecmp(value, "FIRST") == 0) {
            *direction = SQL_FETCH_FIRST;
        } else if (strcasecmp(value, "LAST") == 0) {
            *direction = SQL_FETCH_LAST;
        } else if (strcasecmp(value, "PRIOR") == 0
                || strcasecmp(value, "PREVIOUS") == 0) {
            *direction = SQL_FETCH_PRIOR;
        } else if (strcasecmp(value, "CURRENT") == 0) {
            *direction = SQL_FETCH_RELATIVE;
        } else {
            php_error_docref(NULL, E_WARNING,
                             "%s(): invalid positioning argument", fn);
            return 0;
        }
        return 1;
    }
    php_error_docref(NULL, E_WARNING, "%s(): invalid positioning argument", fn);
    return 0;
}

static int ifx_fetch_row_zval(ifx_result *result, zval *position, zval *return_value)
{
    SQLSMALLINT direction;
    SQLLEN offset;
    SQLRETURN rc;
    int i;

    if (result == NULL || result->hstmt == SQL_NULL_HSTMT) {
        ZVAL_FALSE(return_value);
        return 0;
    }
    if (!result->executed) {
        ifx_set_error("HY010", -1, "Result is not executed (call ifx_do())");
        ZVAL_FALSE(return_value);
        return 0;
    }
    if (result->num_cols <= 0) {
        ifx_set_error("HY000", -1, "Not a select cursor!");
        ZVAL_FALSE(return_value);
        return 0;
    }
    if (!ifx_fetch_direction(position, (result->cursor_flags & IFX_SCROLL_FLAG) != 0,
                             &direction, &offset, "ifx_fetch_row")) {
        ZVAL_FALSE(return_value);
        return 0;
    }

    if ((result->cursor_flags & IFX_SCROLL_FLAG) != 0) {
        rc = SQLFetchScroll(result->hstmt, direction, offset);
    } else {
        rc = SQLFetch(result->hstmt);
    }
    if (rc == SQL_NO_DATA) {
        ifx_set_end_of_data();
        ZVAL_FALSE(return_value);
        return 0;
    }
    if (rc == SQL_ERROR) {
        ifx_capture_diag(SQL_HANDLE_STMT, result->hstmt, "SQLFetch");
        ZVAL_FALSE(return_value);
        return 0;
    }

    array_init(return_value);
    for (i = 0; i < result->num_cols; i++) {
        zval value;
        ZVAL_UNDEF(&value);
        if (!ifx_get_column(result, i, &value)) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            return 0;
        }
        zend_hash_str_update(Z_ARRVAL_P(return_value),
                             result->col_names[i],
                             strlen(result->col_names[i]), &value);
    }
    result->fetched++;
    ifx_clear_error();
    return 1;
}

/* ------------------------------------------------------------- connection */

static void ifx_connect_impl(INTERNAL_FUNCTION_PARAMETERS, int persistent)
{
    zval *dsn_zv = NULL;
    zval *user_zv = NULL;
    zval *password_zv = NULL;
    char *dsn;
    char *user;
    char *password;
    char *connection_string;
    char *persistent_key = NULL;
    const char *env;
    ifx_link *link;
    SQLRETURN rc;

    (void) persistent;

    ZEND_PARSE_PARAMETERS_START(0, 3)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(dsn_zv)
        Z_PARAM_ZVAL(user_zv)
        Z_PARAM_ZVAL(password_zv)
    ZEND_PARSE_PARAMETERS_END();

    dsn = ifx_zval_to_cstr(dsn_zv);
    user = ifx_zval_to_cstr(user_zv);
    password = ifx_zval_to_cstr(password_zv);

    if (dsn == NULL || dsn[0] == '\0') {
        if (dsn != NULL) efree(dsn);
        env = getenv("IFX_DEFAULT_DATABASE");
        if (env == NULL || env[0] == '\0') {
            env = getenv("INFORMIXDATABASE");
        }
        dsn = env != NULL ? estrdup(env) : NULL;
    }
    if (user == NULL || user[0] == '\0') {
        if (user != NULL) efree(user);
        env = getenv("IFX_DEFAULT_USER");
        user = env != NULL ? estrdup(env) : estrdup("");
    }
    if (password == NULL) {
        env = getenv("IFX_DEFAULT_PASSWORD");
        password = env != NULL ? estrdup(env) : estrdup("");
    }
    if (dsn == NULL || dsn[0] == '\0') {
        if (dsn != NULL) efree(dsn);
        efree(user);
        efree(password);
        ifx_set_error("08001", -1, "No Informix database specified");
        RETURN_FALSE;
    }

    connection_string = ifx_build_dsn(dsn, user, password);
    efree(dsn);
    efree(user);
    efree(password);

    if (persistent && IFX_G(persistent_links) != NULL) {
        zval *cached = zend_hash_str_find(IFX_G(persistent_links),
                                          connection_string,
                                          strlen(connection_string));
        if (cached != NULL) {
            ifx_link *cached_link = ifx_link_ptr(cached);
            if (cached_link != NULL) {
                efree(connection_string);
                ZVAL_COPY(return_value, cached);
                ifx_set_default_link(Z_RES_P(return_value));
                ifx_clear_error();
                return;
            }
            zend_hash_str_del(IFX_G(persistent_links), connection_string,
                              strlen(connection_string));
        }
    }
    if (persistent) {
        persistent_key = estrdup(connection_string);
    }

    link = ecalloc(1, sizeof(ifx_link));
    link->henv = SQL_NULL_HENV;
    link->hdbc = SQL_NULL_HDBC;

    rc = SQLAllocHandle(SQL_HANDLE_ENV, SQL_NULL_HANDLE, &link->henv);
    if (rc == SQL_ERROR) {
        ifx_set_error("HY000", -1, "SQLAllocHandle(ENV) failed");
        efree(connection_string);
        if (persistent_key != NULL) efree(persistent_key);
        efree(link);
        RETURN_FALSE;
    }
    SQLSetEnvAttr(link->henv, SQL_ATTR_ODBC_VERSION,
                  (SQLPOINTER) SQL_OV_ODBC3, 0);
    rc = SQLAllocHandle(SQL_HANDLE_DBC, link->henv, &link->hdbc);
    if (rc == SQL_ERROR) {
        SQLFreeHandle(SQL_HANDLE_ENV, link->henv);
        efree(connection_string);
        if (persistent_key != NULL) efree(persistent_key);
        efree(link);
        ifx_set_error("HY000", -1, "SQLAllocHandle(DBC) failed");
        RETURN_FALSE;
    }

    rc = SQLDriverConnect(link->hdbc, NULL, (SQLCHAR *) connection_string,
                          SQL_NTS, NULL, 0, NULL, SQL_DRIVER_NOPROMPT);
    efree(connection_string);
    if (rc == SQL_ERROR) {
        ifx_capture_diag(SQL_HANDLE_DBC, link->hdbc, "SQLDriverConnect");
        SQLFreeHandle(SQL_HANDLE_DBC, link->hdbc);
        SQLFreeHandle(SQL_HANDLE_ENV, link->henv);
        if (persistent_key != NULL) efree(persistent_key);
        efree(link);
        RETURN_FALSE;
    }

    SQLSetConnectAttr(link->hdbc, SQL_INFX_ATTR_ODBC_TYPES_ONLY,
                      (SQLPOINTER) SQL_TRUE, SQL_NTS);
    SQLSetConnectAttr(link->hdbc, SQL_INFX_ATTR_ENABLE_SCROLL_CURSORS,
                      (SQLPOINTER) SQL_TRUE, SQL_IS_INTEGER);
    SQLSetConnectAttr(link->hdbc, SQL_ATTR_AUTOCOMMIT,
                      (SQLPOINTER) SQL_AUTOCOMMIT_ON, SQL_NTS);

    ZVAL_RES(return_value, zend_register_resource(link, le_ifx_link));
    ifx_set_default_link(Z_RES_P(return_value));
    if (persistent_key != NULL && IFX_G(persistent_links) != NULL) {
        zval copy;
        ZVAL_COPY(&copy, return_value);
        zend_hash_str_update(IFX_G(persistent_links), persistent_key,
                             strlen(persistent_key), &copy);
        efree(persistent_key);
    }
    ifx_clear_error();
}

/* ------------------------------------------------------------------ API */

PHP_FUNCTION(ifx_connect)
{
    ifx_connect_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, 0);
}

PHP_FUNCTION(ifx_pconnect)
{
    ifx_connect_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, 1);
}

PHP_FUNCTION(ifx_close)
{
    zval *link_zv = NULL;
    ifx_link *link;
    zend_resource *res;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(link_zv)
    ZEND_PARSE_PARAMETERS_END();

    if (link_zv != NULL && Z_TYPE_P(link_zv) == IS_NULL) {
        link_zv = NULL;
    }
    if (link_zv != NULL) {
        if (Z_TYPE_P(link_zv) != IS_RESOURCE) {
            RETURN_FALSE;
        }
        res = Z_RES_P(link_zv);
        link = ifx_link_ptr(link_zv);
    } else {
        res = IFX_G(default_link);
        link = ifx_default_link_ptr();
    }
    if (link == NULL) {
        RETURN_FALSE;
    }
    if (res != NULL) {
        ifx_persistent_forget(res);
        ifx_clear_default_link(res);
        zend_list_close(res);
    }
    RETURN_TRUE;
}

static void ifx_query_common(INTERNAL_FUNCTION_PARAMETERS, int execute)
{
    zval *sql_zv;
    zval *arg2 = NULL;
    zval *arg3 = NULL;
    zval *arg4 = NULL;
    zval *params = NULL;
    ifx_link *link = NULL;
    ifx_result *result;
    int cursor_flags = 0;

    ZEND_PARSE_PARAMETERS_START(1, 4)
        Z_PARAM_ZVAL(sql_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(arg2)
        Z_PARAM_ZVAL(arg3)
        Z_PARAM_ZVAL(arg4)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_TYPE_P(sql_zv) != IS_STRING) {
        convert_to_string(sql_zv);
    }

    if (arg2 != NULL && Z_TYPE_P(arg2) == IS_RESOURCE) {
        link = ifx_link_ptr(arg2);
        if (link == NULL) {
            RETURN_FALSE;
        }
    } else if (arg2 != NULL && Z_TYPE_P(arg2) == IS_ARRAY) {
        params = arg2;
    } else if (arg2 != NULL && Z_TYPE_P(arg2) == IS_LONG) {
        cursor_flags = (int) Z_LVAL_P(arg2);
    } else if (arg2 != NULL && Z_TYPE_P(arg2) != IS_NULL) {
        RETURN_FALSE;
    }

    if (arg3 != NULL) {
        if (Z_TYPE_P(arg3) == IS_ARRAY) {
            params = arg3;
        } else if (Z_TYPE_P(arg3) == IS_LONG) {
            cursor_flags = (int) Z_LVAL_P(arg3);
        } else if (Z_TYPE_P(arg3) != IS_NULL) {
            RETURN_FALSE;
        }
    }
    if (arg4 != NULL && Z_TYPE_P(arg4) == IS_ARRAY) {
        params = arg4;
    }
    if (arg4 != NULL && Z_TYPE_P(arg4) != IS_ARRAY && Z_TYPE_P(arg4) != IS_NULL) {
        RETURN_FALSE;
    }

    if (link == NULL) {
        link = ifx_default_link_ptr();
    }
    if (link == NULL) {
        ifx_set_error("08003", -1, "No Informix connection");
        RETURN_FALSE;
    }

    result = ifx_result_new(link, Z_STRVAL_P(sql_zv), cursor_flags,
                            params, execute ? "ifx_query" : "ifx_prepare");
    if (result == NULL) {
        RETURN_FALSE;
    }
    if (execute && !ifx_result_execute(result)) {
        ifx_result_free(result);
        RETURN_FALSE;
    }
    ZVAL_RES(return_value, zend_register_resource(result, le_ifx_result));
}

PHP_FUNCTION(ifx_query)
{
    ifx_query_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, 1);
}

PHP_FUNCTION(ifx_prepare)
{
    ifx_query_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, 0);
}

PHP_FUNCTION(ifx_do)
{
    zval *result_zv;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    if (!ifx_result_execute(result)) {
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_fetch_row)
{
    zval *result_zv;
    zval *position = NULL;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_RESOURCE(result_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(position)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    ifx_fetch_row_zval(result, position, return_value);
}

PHP_FUNCTION(ifx_free_result)
{
    zval *result_zv;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    zend_list_close(Z_RES_P(result_zv));
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_affected_rows)
{
    zval *result_zv;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    RETURN_LONG(result->affected > 0 ? (zend_long) result->affected : 0);
}

PHP_FUNCTION(ifx_num_rows)
{
    zval *result_zv;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    RETURN_LONG(result->fetched);
}

PHP_FUNCTION(ifx_num_fields)
{
    zval *result_zv;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    RETURN_LONG(result->num_cols);
}

PHP_FUNCTION(ifx_fieldtypes)
{
    zval *result_zv;
    ifx_result *result;
    int i;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL || !result->executed || result->num_cols <= 0) {
        ifx_set_error("HY000", -1, "Not a select cursor!");
        RETURN_FALSE;
    }
    array_init(return_value);
    for (i = 0; i < result->num_cols; i++) {
        add_assoc_string(return_value, result->col_names[i], result->type_names[i]);
    }
}

PHP_FUNCTION(ifx_fieldproperties)
{
    zval *result_zv;
    ifx_result *result;
    int i;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL || !result->executed || result->num_cols <= 0) {
        ifx_set_error("HY000", -1, "Not a select cursor!");
        RETURN_FALSE;
    }
    array_init(return_value);
    for (i = 0; i < result->num_cols; i++) {
        char property[256];
        char nullable = (result->nullable[i] == SQL_NULLABLE) ? 'Y' : 'N';
        snprintf(property, sizeof(property), "%s;%lu;%lu;%d;%c",
                 result->type_names[i],
                 (unsigned long) result->col_sizes[i],
                 (unsigned long) result->precisions[i],
                 (int) result->dec_digits[i], nullable);
        add_assoc_string(return_value, result->col_names[i], property);
    }
}

PHP_FUNCTION(ifx_error)
{
    zval *link_zv = NULL;
    char buffer[1200];
    const char *status;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(link_zv)
    ZEND_PARSE_PARAMETERS_END();

    if (IFX_G(last_sqlcode) == 0) {
        status = "";
    } else if (IFX_G(last_sqlcode) == 100) {
        status = "N";
    } else if (IFX_G(last_sqlcode) > 0 && IFX_G(last_sqlcode) < 100) {
        status = "W";
    } else {
        status = "E";
    }
    snprintf(buffer, sizeof(buffer), "%s [SQLSTATE=%s  SQLCODE=%d]  %s",
             status, IFX_G(last_sqlstate), IFX_G(last_sqlcode),
             IFX_G(last_error));
    RETURN_STRING(buffer);
}

PHP_FUNCTION(ifx_errormsg)
{
    zval *code_zv = NULL;
    zend_long code = 0;
    int have_code = 0;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(code_zv)
    ZEND_PARSE_PARAMETERS_END();

    if (code_zv != NULL && Z_TYPE_P(code_zv) != IS_NULL) {
        code = zval_get_long(code_zv);
        have_code = 1;
    }
    if (!have_code || code == IFX_G(last_sqlcode)) {
        RETURN_STRING(IFX_G(last_error));
    }
    switch ((int) code) {
        case -201:
            RETURN_STRING("A syntax error has occurred.");
        case -206:
            RETURN_STRING("The column name is not found in any table in the query.");
        case -217:
            RETURN_STRING("Column or expression in the select list is invalid.");
        case -239:
            RETURN_STRING("Could not position within a table.");
        case -268:
            RETURN_STRING("The routine cannot be resolved.");
        case -608:
            RETURN_STRING("Unique constraint violated.");
        case -11067:
            RETURN_STRING("No such table or view.");
        case 100:
            RETURN_STRING("No rows found.");
        default:
            {
                char message[128];
                snprintf(message, sizeof(message), "Informix error %d", (int) code);
                RETURN_STRING(message);
            }
    }
}

PHP_FUNCTION(ifx_getsqlca)
{
    zval *result_zv;
    ifx_result *result;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_RESOURCE(result_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL) {
        RETURN_FALSE;
    }
    array_init(return_value);
    add_assoc_long(return_value, "sqlerrd0", 0);
    add_assoc_long(return_value, "sqlerrd1", result->serial);
    add_assoc_long(return_value, "sqlerrd2", (zend_long) result->affected);
    add_assoc_long(return_value, "sqlerrd3", 0);
    add_assoc_long(return_value, "sqlerrd4", 0);
    add_assoc_long(return_value, "sqlerrd5", 0);
}

static void ifx_html_write(const char *text, size_t len)
{
    if (len > 0) {
        php_output_write(text, len);
    }
}

PHP_FUNCTION(ifx_htmltbl_result)
{
    zval *result_zv;
    zval *options_zv = NULL;
    ifx_result *result;
    const char *options = "";
    zval row;
    int count = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_RESOURCE(result_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(options_zv)
    ZEND_PARSE_PARAMETERS_END();

    result = ifx_result_ptr(result_zv);
    if (result == NULL || !result->executed || result->num_cols <= 0) {
        ifx_set_error("HY000", -1, "Not a select cursor!");
        RETURN_FALSE;
    }
    if (options_zv != NULL && Z_TYPE_P(options_zv) != IS_NULL) {
        if (Z_TYPE_P(options_zv) != IS_STRING) {
            convert_to_string(options_zv);
        }
        options = Z_STRVAL_P(options_zv);
    }

    {
        char head[512];
        int n;
        int i;
        if (options[0] != '\0') {
            n = snprintf(head, sizeof(head), "<table %s><tr>", options);
        } else {
            n = snprintf(head, sizeof(head), "<table><tr>");
        }
        ifx_html_write(head, (size_t) n);
        for (i = 0; i < result->num_cols; i++) {
            char cell[512];
            const char *name = result->col_names[i];
            char upper[256];
            size_t len = strlen(name);
            if (len >= sizeof(upper)) len = sizeof(upper) - 1;
            memcpy(upper, name, len);
            upper[len] = '\0';
            if (len > 0) {
                upper[0] = (char) toupper((unsigned char) upper[0]);
            }
            snprintf(cell, sizeof(cell), "<th>%s</th>", upper);
            ifx_html_write(cell, strlen(cell));
        }
        ifx_html_write("</tr>", 5);
    }

    while (1) {
        ZVAL_UNDEF(&row);
        if (!ifx_fetch_row_zval(result, NULL, &row)) {
            break;
        }
        count++;
        ifx_html_write("<tr>", 4);
        {
            zval *value;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL(row), value) {
                ifx_html_write("<td>", 4);
                if (Z_TYPE_P(value) == IS_NULL) {
                    if (IFX_G(nullformat)) {
                        ifx_html_write("NULL", 4);
                    }
                } else if (Z_TYPE_P(value) == IS_LONG) {
                    ifx_handle *handle = NULL;
                    if (ifx_handle_lookup_silent(Z_LVAL_P(value), &handle)) {
                        if (handle->type == IFX_HANDLE_BLOB && handle->subtype == 0) {
                            ifx_html_write("(BYTE)", 6);
                        } else if (handle->data != NULL) {
                            ifx_html_write(handle->data, handle->len);
                        }
                    } else {
                        char number[64];
                        snprintf(number, sizeof(number), ZEND_LONG_FMT, Z_LVAL_P(value));
                        ifx_html_write(number, strlen(number));
                    }
                } else {
                    zend_string *str = zval_get_string(value);
                    ifx_html_write(ZSTR_VAL(str), ZSTR_LEN(str));
                    zend_string_release(str);
                }
                ifx_html_write("</td>", 5);
            } ZEND_HASH_FOREACH_END();
        }
        ifx_html_write("</tr>", 5);
        zval_ptr_dtor(&row);
    }

    if (count == 0) {
        ifx_html_write("<h2>No rows found</h2>", 21);
    }
    if (IFX_G(last_sqlcode) == 100) {
        ifx_clear_error();
    }
    RETURN_LONG(count);
}

/* ------------------------------------------------------------- BLOB/CHAR */

static char *ifx_read_source_file(const char *path, size_t *out_len)
{
    php_stream *stream;
    zend_string *contents;
    char *result;

    *out_len = 0;
    stream = php_stream_open_wrapper(path, "rb", 0, NULL);
    if (stream == NULL) {
        return NULL;
    }
    contents = php_stream_copy_to_mem(stream, PHP_STREAM_COPY_ALL, 0);
    php_stream_close(stream);
    if (contents == NULL) {
        return NULL;
    }
    result = estrndup(ZSTR_VAL(contents), ZSTR_LEN(contents));
    *out_len = ZSTR_LEN(contents);
    zend_string_release(contents);
    return result;
}

static char *ifx_write_temp_file(const char *data, size_t len)
{
    zend_string *path = NULL;
    FILE *fp;
    char *result = NULL;

    fp = php_open_temporary_file(NULL, "ifx", &path);
    if (fp == NULL) {
        if (path != NULL) zend_string_release(path);
        return NULL;
    }
    if (len > 0 && fwrite(data, 1, len, fp) != len) {
        fclose(fp);
        if (path != NULL) {
            remove(ZSTR_VAL(path));
            zend_string_release(path);
        }
        return NULL;
    }
    fclose(fp);
    if (path != NULL) {
        result = estrndup(ZSTR_VAL(path), ZSTR_LEN(path));
        zend_string_release(path);
    }
    return result;
}

PHP_FUNCTION(ifx_create_blob)
{
    zend_long type;
    zend_long mode;
    zend_string *param;
    ifx_handle *handle;
    char *data;
    size_t len;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(type)
        Z_PARAM_LONG(mode)
        Z_PARAM_STR(param)
    ZEND_PARSE_PARAMETERS_END();

    if (mode != 0) {
        data = ifx_read_source_file(ZSTR_VAL(param), &len);
        if (data == NULL) {
            php_error_docref(NULL, E_WARNING,
                             "ifx_create_blob(): cannot read blob file \"%s\"",
                             ZSTR_VAL(param));
            RETURN_FALSE;
        }
    } else {
        len = ZSTR_LEN(param);
        data = estrndup(ZSTR_VAL(param), len);
    }

    handle = ecalloc(1, sizeof(ifx_handle));
    handle->type = IFX_HANDLE_BLOB;
    handle->subtype = type != 0 ? 1 : 0;
    handle->mode = mode != 0 ? 1 : 0;
    handle->data = data;
    handle->len = len;
    handle->cap = len + 1;
    if (handle->mode == 1) {
        handle->path = ifx_write_temp_file(handle->data, handle->len);
        if (handle->path == NULL) {
            ifx_handle_free(handle);
            ifx_set_error("HY000", -1, "Cannot create temporary blob file");
            RETURN_FALSE;
        }
    }
    RETURN_LONG(ifx_handle_add(handle));
}

static ifx_handle *ifx_handle_checked(zend_long id, int type, const char *fn)
{
    ifx_handle *handle = NULL;

    if (!ifx_handle_lookup_silent(id, &handle) || handle->type != type) {
        php_error_docref(NULL, E_WARNING,
                         "%s(): %ld is not a valid handle", fn, (long) id);
        return NULL;
    }
    return handle;
}

PHP_FUNCTION(ifx_copy_blob)
{
    zend_long id;
    ifx_handle *src;
    ifx_handle *copy;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    src = ifx_handle_checked(id, IFX_HANDLE_BLOB, "ifx_copy_blob");
    if (src == NULL) {
        RETURN_FALSE;
    }
    copy = ecalloc(1, sizeof(ifx_handle));
    copy->type = IFX_HANDLE_BLOB;
    copy->subtype = src->subtype;
    copy->mode = src->mode;
    copy->len = src->len;
    copy->cap = src->len + 1;
    copy->data = emalloc(copy->cap);
    if (src->len > 0) {
        memcpy(copy->data, src->data, src->len);
    }
    copy->data[copy->len] = '\0';
    if (copy->mode == 1) {
        copy->path = ifx_write_temp_file(copy->data, copy->len);
        if (copy->path == NULL) {
            ifx_handle_free(copy);
            RETURN_FALSE;
        }
    }
    RETURN_LONG(ifx_handle_add(copy));
}

PHP_FUNCTION(ifx_free_blob)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_BLOB, "ifx_free_blob");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    zend_hash_index_del(IFX_G(handles), (zend_ulong) id);
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_get_blob)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_BLOB, "ifx_get_blob");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    if (handle->mode == 1 && handle->path != NULL) {
        RETURN_STRING(handle->path);
    }
    RETURN_STRINGL(handle->data != NULL ? handle->data : "", handle->len);
}

PHP_FUNCTION(ifx_update_blob)
{
    zend_long id;
    zend_string *value;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(id)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_BLOB, "ifx_update_blob");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    handle->data = erealloc(handle->data, ZSTR_LEN(value) + 1);
    memcpy(handle->data, ZSTR_VAL(value), ZSTR_LEN(value));
    handle->data[ZSTR_LEN(value)] = '\0';
    handle->len = ZSTR_LEN(value);
    handle->cap = ZSTR_LEN(value) + 1;
    if (handle->mode == 1) {
        FILE *fp;
        if (handle->path == NULL) {
            handle->path = ifx_write_temp_file(handle->data, handle->len);
            if (handle->path == NULL) {
                RETURN_FALSE;
            }
        } else {
            fp = fopen(handle->path, "wb");
            if (fp == NULL) {
                RETURN_FALSE;
            }
            if (handle->len > 0 && fwrite(handle->data, 1, handle->len, fp) != handle->len) {
                fclose(fp);
                RETURN_FALSE;
            }
            fclose(fp);
        }
    }
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_blobinfile_mode)
{
    zend_long mode;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();

    IFX_G(blobinfile) = mode != 0;
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_create_char)
{
    zend_string *value;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();

    handle = ecalloc(1, sizeof(ifx_handle));
    handle->type = IFX_HANDLE_CHAR;
    handle->len = ZSTR_LEN(value);
    handle->cap = handle->len + 1;
    handle->data = emalloc(handle->cap);
    memcpy(handle->data, ZSTR_VAL(value), handle->len);
    handle->data[handle->len] = '\0';
    RETURN_LONG(ifx_handle_add(handle));
}

PHP_FUNCTION(ifx_free_char)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_CHAR, "ifx_free_char");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    zend_hash_index_del(IFX_G(handles), (zend_ulong) id);
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_get_char)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_CHAR, "ifx_get_char");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    RETURN_STRINGL(handle->data != NULL ? handle->data : "", handle->len);
}

PHP_FUNCTION(ifx_update_char)
{
    zend_long id;
    zend_string *value;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(id)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_CHAR, "ifx_update_char");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    handle->data = erealloc(handle->data, ZSTR_LEN(value) + 1);
    memcpy(handle->data, ZSTR_VAL(value), ZSTR_LEN(value));
    handle->data[ZSTR_LEN(value)] = '\0';
    handle->len = ZSTR_LEN(value);
    handle->cap = ZSTR_LEN(value) + 1;
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_textasvarchar)
{
    zend_long mode;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();
    IFX_G(textasvarchar) = mode != 0;
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_byteasvarchar)
{
    zend_long mode;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();
    IFX_G(byteasvarchar) = mode != 0;
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_charasvarchar)
{
    zend_long mode;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();
    IFX_G(charasvarchar) = mode != 0;
    RETURN_TRUE;
}

PHP_FUNCTION(ifx_nullformat)
{
    zend_long mode;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();
    IFX_G(nullformat) = mode != 0;
    RETURN_TRUE;
}

/* ------------------------------------------------------------------ SLOB */

PHP_FUNCTION(ifxus_create_slob)
{
    zend_long mode;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();

    handle = ecalloc(1, sizeof(ifx_handle));
    handle->type = IFX_HANDLE_SLOB;
    handle->flags = (int) (mode == 0 ? IFX_SLOB_RDWR : mode);
    handle->open = 1;
    handle->pos = 0;
    RETURN_LONG(ifx_handle_add(handle));
}

PHP_FUNCTION(ifxus_open_slob)
{
    zend_long id;
    zend_long mode;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(id)
        Z_PARAM_LONG(mode)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_open_slob");
    if (handle == NULL || handle->open) {
        RETURN_FALSE;
    }
    handle->flags = (int) (mode == 0 ? IFX_SLOB_RDWR : mode);
    handle->open = 1;
    if ((handle->flags & IFX_SLOB_APPEND) != 0) {
        handle->pos = handle->len;
    }
    RETURN_LONG(0);
}

PHP_FUNCTION(ifxus_close_slob)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_close_slob");
    if (handle == NULL || !handle->open) {
        RETURN_FALSE;
    }
    handle->open = 0;
    RETURN_TRUE;
}

PHP_FUNCTION(ifxus_free_slob)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_free_slob");
    if (handle == NULL) {
        RETURN_FALSE;
    }
    zend_hash_index_del(IFX_G(handles), (zend_ulong) id);
    RETURN_TRUE;
}

PHP_FUNCTION(ifxus_tell_slob)
{
    zend_long id;
    ifx_handle *handle;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_tell_slob");
    if (handle == NULL || !handle->open) {
        RETURN_FALSE;
    }
    RETURN_LONG((zend_long) handle->pos);
}

PHP_FUNCTION(ifxus_seek_slob)
{
    zend_long id;
    zend_long offset;
    zend_long whence;
    ifx_handle *handle;
    zend_long base;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(id)
        Z_PARAM_LONG(whence)
        Z_PARAM_LONG(offset)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_seek_slob");
    if (handle == NULL || !handle->open) {
        RETURN_FALSE;
    }
    if (whence == 0) {
        base = 0;
    } else if (whence == 1) {
        base = (zend_long) handle->pos;
    } else if (whence == 2) {
        base = (zend_long) handle->len;
    } else {
        RETURN_FALSE;
    }
    if (base + offset < 0) {
        RETURN_FALSE;
    }
    handle->pos = (size_t) (base + offset);
    RETURN_LONG(0);
}

PHP_FUNCTION(ifxus_read_slob)
{
    zend_long id;
    zend_long bytes;
    ifx_handle *handle;
    size_t available;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(id)
        Z_PARAM_LONG(bytes)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_read_slob");
    if (handle == NULL || !handle->open || bytes < 0) {
        RETURN_FALSE;
    }
    if ((handle->flags & IFX_SLOB_WRONLY) != 0
            && (handle->flags & (IFX_SLOB_RDWR | IFX_SLOB_RDONLY)) == 0) {
        RETURN_FALSE;
    }
    available = handle->len > handle->pos ? handle->len - handle->pos : 0;
    if ((size_t) bytes < available) {
        available = (size_t) bytes;
    }
    {
        zend_string *out = zend_string_init(
            handle->data != NULL ? handle->data + handle->pos : "", available, 0);
        handle->pos += available;
        RETURN_STR(out);
    }
}

PHP_FUNCTION(ifxus_write_slob)
{
    zend_long id;
    zend_string *value;
    ifx_handle *handle;
    size_t required;
    size_t end;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(id)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();

    handle = ifx_handle_checked(id, IFX_HANDLE_SLOB, "ifxus_write_slob");
    if (handle == NULL || !handle->open || ZSTR_LEN(value) == 0) {
        RETURN_FALSE;
    }
    if ((handle->flags & (IFX_SLOB_WRONLY | IFX_SLOB_RDWR | IFX_SLOB_APPEND)) == 0) {
        RETURN_FALSE;
    }
    if ((handle->flags & IFX_SLOB_APPEND) != 0) {
        handle->pos = handle->len;
    }
    end = handle->pos + ZSTR_LEN(value);
    required = end + 1;
    if (required > handle->cap) {
        size_t new_cap = handle->cap == 0 ? 256 : handle->cap;
        while (new_cap < required) {
            new_cap *= 2;
        }
        handle->data = handle->data == NULL ? emalloc(new_cap)
                                             : erealloc(handle->data, new_cap);
        handle->cap = new_cap;
    }
    if (handle->pos > handle->len) {
        memset(handle->data + handle->len, 0, handle->pos - handle->len);
    }
    memcpy(handle->data + handle->pos, ZSTR_VAL(value), ZSTR_LEN(value));
    handle->data[end] = '\0';
    handle->pos = end;
    if (end > handle->len) {
        handle->len = end;
    }
    RETURN_LONG((zend_long) ZSTR_LEN(value));
}

/* --------------------------------------------------------------- module */

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_connect, 0, 0, 0)
    ZEND_ARG_INFO(0, database)
    ZEND_ARG_INFO(0, user)
    ZEND_ARG_INFO(0, password)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_close, 0, 0, 0)
    ZEND_ARG_INFO(0, link_identifier)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_query, 0, 0, 1)
    ZEND_ARG_INFO(0, query)
    ZEND_ARG_INFO(0, link_identifier)
    ZEND_ARG_INFO(0, cursor_type)
    ZEND_ARG_INFO(0, blobidarray)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_prepare, 0, 0, 1)
    ZEND_ARG_INFO(0, query)
    ZEND_ARG_INFO(0, link_identifier)
    ZEND_ARG_INFO(0, cursor_type)
    ZEND_ARG_INFO(0, blobidarray)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_result, 0, 0, 1)
    ZEND_ARG_INFO(0, result_id)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_fetch_row, 0, 0, 1)
    ZEND_ARG_INFO(0, result_id)
    ZEND_ARG_INFO(0, position)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_htmltbl_result, 0, 0, 1)
    ZEND_ARG_INFO(0, result_id)
    ZEND_ARG_INFO(0, html_table_options)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_error, 0, 0, 0)
    ZEND_ARG_INFO(0, link_identifier)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_errormsg, 0, 0, 0)
    ZEND_ARG_INFO(0, errorcode)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_create_blob, 0, 0, 3)
    ZEND_ARG_INFO(0, type)
    ZEND_ARG_INFO(0, mode)
    ZEND_ARG_INFO(0, param)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_long1, 0, 0, 1)
    ZEND_ARG_INFO(0, id)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_long2, 0, 0, 2)
    ZEND_ARG_INFO(0, id)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_long3, 0, 0, 3)
    ZEND_ARG_INFO(0, id)
    ZEND_ARG_INFO(0, mode)
    ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_mode, 0, 0, 1)
    ZEND_ARG_INFO(0, mode)
ZEND_END_ARG_INFO()

static const zend_function_entry ifx_functions[] = {
    PHP_FE(ifx_connect, arginfo_ifx_connect)
    PHP_FE(ifx_pconnect, arginfo_ifx_connect)
    PHP_FE(ifx_close, arginfo_ifx_close)
    PHP_FE(ifx_query, arginfo_ifx_query)
    PHP_FE(ifx_prepare, arginfo_ifx_prepare)
    PHP_FE(ifx_do, arginfo_ifx_result)
    PHP_FE(ifx_fetch_row, arginfo_ifx_fetch_row)
    PHP_FE(ifx_free_result, arginfo_ifx_result)
    PHP_FE(ifx_affected_rows, arginfo_ifx_result)
    PHP_FE(ifx_num_rows, arginfo_ifx_result)
    PHP_FE(ifx_num_fields, arginfo_ifx_result)
    PHP_FE(ifx_fieldtypes, arginfo_ifx_result)
    PHP_FE(ifx_fieldproperties, arginfo_ifx_result)
    PHP_FE(ifx_error, arginfo_ifx_error)
    PHP_FE(ifx_errormsg, arginfo_ifx_errormsg)
    PHP_FE(ifx_getsqlca, arginfo_ifx_result)
    PHP_FE(ifx_htmltbl_result, arginfo_ifx_htmltbl_result)
    PHP_FE(ifx_create_blob, arginfo_ifx_create_blob)
    PHP_FE(ifx_copy_blob, arginfo_ifx_long1)
    PHP_FE(ifx_free_blob, arginfo_ifx_long1)
    PHP_FE(ifx_get_blob, arginfo_ifx_long1)
    PHP_FE(ifx_update_blob, arginfo_ifx_long2)
    PHP_FE(ifx_blobinfile_mode, arginfo_ifx_mode)
    PHP_FE(ifx_create_char, arginfo_ifx_long1)
    PHP_FE(ifx_free_char, arginfo_ifx_long1)
    PHP_FE(ifx_get_char, arginfo_ifx_long1)
    PHP_FE(ifx_update_char, arginfo_ifx_long2)
    PHP_FE(ifxus_create_slob, arginfo_ifx_mode)
    PHP_FE(ifxus_open_slob, arginfo_ifx_long2)
    PHP_FE(ifxus_close_slob, arginfo_ifx_long1)
    PHP_FE(ifxus_free_slob, arginfo_ifx_long1)
    PHP_FE(ifxus_read_slob, arginfo_ifx_long2)
    PHP_FE(ifxus_write_slob, arginfo_ifx_long2)
    PHP_FE(ifxus_seek_slob, arginfo_ifx_long3)
    PHP_FE(ifxus_tell_slob, arginfo_ifx_long1)
    PHP_FE(ifx_textasvarchar, arginfo_ifx_mode)
    PHP_FE(ifx_byteasvarchar, arginfo_ifx_mode)
    PHP_FE(ifx_charasvarchar, arginfo_ifx_mode)
    PHP_FE(ifx_nullformat, arginfo_ifx_mode)
    PHP_FE_END
};

static PHP_GINIT_FUNCTION(ifx)
{
    memset(ifx_globals, 0, sizeof(zend_ifx_globals));
    memcpy(ifx_globals->last_sqlstate, "00000", 6);
}

static PHP_MINIT_FUNCTION(ifx)
{
    le_ifx_link = zend_register_list_destructors_ex(
        ifx_link_dtor, NULL, "ifx link", module_number);
    le_ifx_result = zend_register_list_destructors_ex(
        ifx_result_dtor, NULL, "ifx result", module_number);

    REGISTER_LONG_CONSTANT("IFX_SCROLL", IFX_SCROLL_FLAG,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_HOLD", IFX_HOLD_FLAG,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_RDONLY", IFX_SLOB_RDONLY,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_WRONLY", IFX_SLOB_WRONLY,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_APPEND", IFX_SLOB_APPEND,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_RDWR", IFX_SLOB_RDWR,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_BUFFER", IFX_SLOB_BUFFER,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_NOBUFFER", IFX_SLOB_NOBUFFER,
                           CONST_CS | CONST_PERSISTENT);
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ifx)
{
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(ifx)
{
    IFX_G(default_link) = NULL;
    IFX_G(next_handle) = 1;
    IFX_G(blobinfile) = 0;
    IFX_G(textasvarchar) = 0;
    IFX_G(byteasvarchar) = 0;
    IFX_G(charasvarchar) = 0;
    IFX_G(nullformat) = 0;
    ifx_clear_error();
    IFX_G(handles) = emalloc(sizeof(HashTable));
    zend_hash_init(IFX_G(handles), 8, NULL, ifx_handle_dtor, 0);
    IFX_G(persistent_links) = emalloc(sizeof(HashTable));
    zend_hash_init(IFX_G(persistent_links), 4, NULL, ZVAL_PTR_DTOR, 0);
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(ifx)
{
    if (IFX_G(default_link) != NULL) {
        zend_list_delete(IFX_G(default_link));
        IFX_G(default_link) = NULL;
    }
    if (IFX_G(persistent_links) != NULL) {
        zend_hash_destroy(IFX_G(persistent_links));
        efree(IFX_G(persistent_links));
        IFX_G(persistent_links) = NULL;
    }
    if (IFX_G(handles) != NULL) {
        zend_hash_destroy(IFX_G(handles));
        efree(IFX_G(handles));
        IFX_G(handles) = NULL;
    }
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(ifx)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "ifx support", "enabled");
    php_info_print_table_row(2, "Version", PHP_IFX_VERSION);
    php_info_print_table_row(2, "Backend", "Informix Client SDK CLI (direct)");
    php_info_print_table_row(2, "API functions", "39");
    php_info_print_table_row(2, "Scroll cursors", "yes");
    php_info_print_table_row(2, "Blob/CHAR/SLOB", "yes");
    php_info_print_table_end();
}

zend_module_entry ifx_module_entry = {
    STANDARD_MODULE_HEADER,
    "ifx",
    ifx_functions,
    PHP_MINIT(ifx),
    PHP_MSHUTDOWN(ifx),
    PHP_RINIT(ifx),
    PHP_RSHUTDOWN(ifx),
    PHP_MINFO(ifx),
    PHP_IFX_VERSION,
    PHP_MODULE_GLOBALS(ifx),
    PHP_GINIT(ifx),
    NULL,
    NULL,
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_IFX
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(ifx)
#endif
