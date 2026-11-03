/*
 * ifx_pdo - native compatibility extension for PHP 7.0-8.5, over PDO
 *
 * Expune API-ul procedural ifx_* / ifxus_* ca funcții C și păstrează
 * link-urile și rezultatele ca resurse, ca extensia ext/informix originală.
 * Motorul de compatibilitate (PDO + semantica legacy) este încorporat în DLL
 * și evaluat o singură dată pe request; funcțiile C sunt adaptoare subțiri.
 * Necesită driverul pdo_informix.
 */

#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "zend_execute.h"
#include "Zend/zend_compile.h"
#include "Zend/zend_stream.h"
#include "main/php_open_temporary_file.h"
#include "php_ifx.h"

extern const unsigned char ifx_php_bootstrap[];
extern const size_t ifx_php_bootstrap_len;

#if PHP_VERSION_ID < 80000
typedef int ifx_result;
#else
typedef zend_result ifx_result;
#endif

typedef struct {
    zval object;
    zend_bool freed;
} ifx_handle;

static int le_ifx_link;
static int le_ifx_result;

ZEND_DECLARE_MODULE_GLOBALS(ifx)

PHP_INI_BEGIN()
    STD_PHP_INI_BOOLEAN("ifx.blobinfile", "1", PHP_INI_ALL, OnUpdateBool, blobinfile, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_BOOLEAN("ifx.textasvarchar", "0", PHP_INI_ALL, OnUpdateBool, textasvarchar, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_BOOLEAN("ifx.byteasvarchar", "0", PHP_INI_ALL, OnUpdateBool, byteasvarchar, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_BOOLEAN("ifx.charasvarchar", "0", PHP_INI_ALL, OnUpdateBool, charasvarchar, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_BOOLEAN("ifx.nullformat", "0", PHP_INI_ALL, OnUpdateBool, nullformat, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.default_host", "", PHP_INI_ALL, OnUpdateString, default_host, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.default_user", "", PHP_INI_ALL, OnUpdateString, default_user, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.default_password", "", PHP_INI_ALL, OnUpdateString, default_password, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.default_database", "", PHP_INI_ALL, OnUpdateString, default_database, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_BOOLEAN("ifx.allow_persistent", "1", PHP_INI_ALL, OnUpdateBool, allow_persistent, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.max_links", "-1", PHP_INI_ALL, OnUpdateLong, max_links, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.max_persistent", "-1", PHP_INI_ALL, OnUpdateLong, max_persistent, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_BOOLEAN("ifx.htmltbl_escape", "0", PHP_INI_ALL, OnUpdateBool, htmltbl_escape, zend_ifx_globals, ifx_globals)
    STD_PHP_INI_ENTRY("ifx.connect_timeout", "-1", PHP_INI_ALL, OnUpdateLong, connect_timeout, zend_ifx_globals, ifx_globals)
PHP_INI_END()

static void ifx_handle_dtor(zend_resource *rsrc)
{
    ifx_handle *handle = (ifx_handle *) rsrc->ptr;
    if (!handle) {
        return;
    }
    if (!Z_ISUNDEF(handle->object)) {
        zval_ptr_dtor(&handle->object);
    }
    efree(handle);
}

static void ifx_report_exception(const char *where)
{
    zend_object *ex;
    zval rv;
    zval *message;

    if (!EG(exception)) {
        return;
    }
    ex = EG(exception);
#if PHP_VERSION_ID < 80000
    {
        zval ex_zv;
        ZVAL_OBJ(&ex_zv, ex);
        message = zend_read_property(ex->ce, &ex_zv, "message", sizeof("message") - 1, 1, &rv);
    }
#else
    message = zend_read_property(ex->ce, ex, "message", sizeof("message") - 1, 1, &rv);
#endif
    php_error_docref(NULL, E_WARNING, "%s(): %s", where,
        message && Z_TYPE_P(message) == IS_STRING ? Z_STRVAL_P(message) : "Informix compatibility error");
    zend_clear_exception();
}

static ifx_result ifx_call_static(const char *class_method, zval *retval, uint32_t argc, zval *argv)
{
    zval fn;
    ifx_result status;

    ZVAL_STRING(&fn, class_method);
    ZVAL_UNDEF(retval);
    status = call_user_function(NULL, NULL, &fn, retval, argc, argv);
    zval_ptr_dtor(&fn);

    if (EG(exception)) {
        ifx_report_exception(class_method);
        if (!Z_ISUNDEF_P(retval)) {
            zval_ptr_dtor(retval);
        }
        ZVAL_FALSE(retval);
        return FAILURE;
    }

    if (status == FAILURE && Z_ISUNDEF_P(retval)) {
        ZVAL_FALSE(retval);
    }

    return status;
}

static zend_bool ifx_class_loaded(const char *name)
{
    zend_string *class_name = zend_string_init(name, strlen(name), 0);
    zend_class_entry *ce = zend_lookup_class(class_name);
    zend_string_release(class_name);

    return ce != NULL;
}

static void ifx_apply_ini(void);

static void ifx_bootstrap_file(void)
{
    zend_string *path = NULL;
    FILE *fp;
    zend_file_handle fh;
    zend_op_array *op_array;

    fp = php_open_temporary_file(NULL, "ifx", &path);
    if (!fp || !path) {
        php_error_docref(NULL, E_WARNING, "ifx: nu pot crea fișierul temporar pentru bootstrap");
        if (path) {
            zend_string_release(path);
        }
        return;
    }
    if (fwrite("<?php\n", 1, 6, fp) != 6
        || fwrite(ifx_php_bootstrap, 1, ifx_php_bootstrap_len, fp) != ifx_php_bootstrap_len) {
        fclose(fp);
        VCWD_UNLINK(ZSTR_VAL(path));
        zend_string_release(path);
        php_error_docref(NULL, E_WARNING, "ifx: nu pot scrie bootstrap-ul în fișierul temporar");
        return;
    }
    fflush(fp);
    fclose(fp);

#if PHP_VERSION_ID >= 70400
    zend_stream_init_filename(&fh, ZSTR_VAL(path));
#else
    memset(&fh, 0, sizeof(fh));
    zend_stream_open(ZSTR_VAL(path), &fh);
#endif
    op_array = zend_compile_file(&fh, ZEND_REQUIRE);
    if (op_array) {
        zend_execute(op_array, NULL);
        destroy_op_array(op_array);
        efree(op_array);
    } else {
        php_error_docref(NULL, E_WARNING, "ifx: nu pot compila bootstrap-ul");
    }
    zend_destroy_file_handle(&fh);
    VCWD_UNLINK(ZSTR_VAL(path));
    zend_string_release(path);
}

static void ifx_ensure_bootstrap(void)
{
    if (IFX_G(bootstrapped)) {
        return;
    }
    IFX_G(bootstrapped) = 1;

    if (!ifx_class_loaded("IfxCompatApi")) {
        ifx_bootstrap_file();
        if (EG(exception)) {
            ifx_report_exception("ifx bootstrap");
        }
    }
    ifx_apply_ini();
}

static void ifx_collect_args(INTERNAL_FUNCTION_PARAMETERS, uint32_t *argc, zval **argv)
{
    uint32_t count = ZEND_NUM_ARGS();
    uint32_t i;
    zval *args = NULL;

    ifx_ensure_bootstrap();

    if (count > 0) {
        args = safe_emalloc(count, sizeof(zval), 0);
        for (i = 0; i < count; i++) {
            ZVAL_COPY(&args[i], ZEND_CALL_ARG(execute_data, i + 1));
        }
    }
    *argc = count;
    *argv = args;
}

static void ifx_release_args(uint32_t argc, zval *argv)
{
    uint32_t i;

    if (!argv) {
        return;
    }
    for (i = 0; i < argc; i++) {
        zval_ptr_dtor(&argv[i]);
    }
    efree(argv);
}

static zend_bool ifx_unwrap_arg(uint32_t argc, zval *argv, uint32_t index, int le, const char *type, const char *function)
{
    ifx_handle *handle;
    zval object;

    if (index >= argc || Z_TYPE(argv[index]) == IS_NULL) {
        return 1;
    }
    if (Z_TYPE(argv[index]) != IS_RESOURCE) {
        php_error_docref(NULL, E_WARNING, "%s(): expected an Informix %s resource", function, type);
        return 0;
    }
    handle = (ifx_handle *) zend_fetch_resource_ex(&argv[index], type, le);
    if (!handle || handle->freed) {
        php_error_docref(NULL, E_WARNING, "%s(): the Informix %s resource is not valid", function, type);
        return 0;
    }
    ZVAL_COPY(&object, &handle->object);
    zval_ptr_dtor(&argv[index]);
    ZVAL_COPY_VALUE(&argv[index], &object);

    return 1;
}

static void ifx_return_object(zval *return_value, zval *retval, int le)
{
    ifx_handle *handle;

    if (Z_TYPE_P(retval) == IS_NULL) {
        ZVAL_FALSE(return_value);
        return;
    }
    if (Z_TYPE_P(retval) != IS_OBJECT) {
        ZVAL_COPY_VALUE(return_value, retval);
        return;
    }
    handle = ecalloc(1, sizeof(ifx_handle));
    ZVAL_COPY(&handle->object, retval);
    zval_ptr_dtor(retval);
    ZVAL_RES(return_value, zend_register_resource(handle, le));
}

static void ifx_forward(INTERNAL_FUNCTION_PARAMETERS, const char *method, int wrap)
{
    uint32_t argc;
    zval *argv;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    ifx_call_static(method, &retval, argc, argv);
    ifx_release_args(argc, argv);

    if (wrap) {
        ifx_return_object(return_value, &retval, wrap);
    } else {
        ZVAL_COPY_VALUE(return_value, &retval);
    }
}

static void ifx_forward_handle(INTERNAL_FUNCTION_PARAMETERS, const char *method, int le, const char *type)
{
    uint32_t argc;
    zval *argv;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    if (!ifx_unwrap_arg(argc, argv, 0, le, type, method)) {
        ifx_release_args(argc, argv);
        RETURN_FALSE;
    }
    ifx_call_static(method, &retval, argc, argv);
    ifx_release_args(argc, argv);
    ZVAL_COPY_VALUE(return_value, &retval);
}

static void ifx_apply_config(const char *name, zval *value)
{
    zval args[2], retval;

    ZVAL_STRING(&args[0], name);
    ZVAL_COPY(&args[1], value);
    ifx_call_static("IfxCompatConfig::set", &retval, 2, args);
    zval_ptr_dtor(&args[0]);
    zval_ptr_dtor(&args[1]);
    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }
}

static void ifx_apply_ini(void)
{
    zval value;

    if (!ifx_class_loaded("IfxCompatConfig")) {
        return;
    }

    ZVAL_LONG(&value, IFX_G(blobinfile));
    ifx_apply_config("blobinfile", &value);
    ZVAL_LONG(&value, IFX_G(textasvarchar));
    ifx_apply_config("textasvarchar", &value);
    ZVAL_LONG(&value, IFX_G(byteasvarchar));
    ifx_apply_config("byteasvarchar", &value);
    ZVAL_LONG(&value, IFX_G(charasvarchar));
    ifx_apply_config("charasvarchar", &value);
    ZVAL_LONG(&value, IFX_G(nullformat));
    ifx_apply_config("nullformat", &value);
    ZVAL_BOOL(&value, IFX_G(allow_persistent));
    ifx_apply_config("allow_persistent", &value);
    ZVAL_LONG(&value, IFX_G(max_links));
    ifx_apply_config("max_links", &value);
    ZVAL_LONG(&value, IFX_G(max_persistent));
    ifx_apply_config("max_persistent", &value);
    ZVAL_BOOL(&value, IFX_G(htmltbl_escape));
    ifx_apply_config("htmltbl_escape", &value);

    if (IFX_G(default_host) && IFX_G(default_host)[0]) {
        ZVAL_STRING(&value, IFX_G(default_host));
        ifx_apply_config("default_host", &value);
        zval_ptr_dtor(&value);
    }
    if (IFX_G(default_user) && IFX_G(default_user)[0]) {
        ZVAL_STRING(&value, IFX_G(default_user));
        ifx_apply_config("default_user", &value);
        zval_ptr_dtor(&value);
    }
    if (IFX_G(default_password) && IFX_G(default_password)[0]) {
        ZVAL_STRING(&value, IFX_G(default_password));
        ifx_apply_config("default_password", &value);
        zval_ptr_dtor(&value);
    }
    if (IFX_G(default_database) && IFX_G(default_database)[0]) {
        ZVAL_STRING(&value, IFX_G(default_database));
        ifx_apply_config("default_database", &value);
        zval_ptr_dtor(&value);
    }
    if (IFX_G(connect_timeout) > 0) {
        ZVAL_LONG(&value, IFX_G(connect_timeout));
        ifx_apply_config("connect_timeout", &value);
    }
}

PHP_GINIT_FUNCTION(ifx)
{
    memset(ifx_globals, 0, sizeof(*ifx_globals));
    ifx_globals->blobinfile = 1;
    ifx_globals->allow_persistent = 1;
    ifx_globals->max_links = -1;
    ifx_globals->max_persistent = -1;
    ifx_globals->connect_timeout = -1;
}

PHP_MINIT_FUNCTION(ifx_pdo)
{
    le_ifx_link = zend_register_list_destructors_ex(ifx_handle_dtor, NULL, "ifx link", module_number);
    le_ifx_result = zend_register_list_destructors_ex(ifx_handle_dtor, NULL, "ifx result", module_number);

    REGISTER_INI_ENTRIES();

    REGISTER_LONG_CONSTANT("IFX_SCROLL", 1, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_HOLD", 2, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_RDONLY", 0x4, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_WRONLY", 0x2, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_APPEND", 0x1, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_RDWR", 0x8, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_BUFFER", 0x200, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("IFX_LO_NOBUFFER", 0x400, CONST_CS | CONST_PERSISTENT);

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(ifx_pdo)
{
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

PHP_RINIT_FUNCTION(ifx_pdo)
{
    IFX_G(bootstrapped) = 0;

    return SUCCESS;
}

PHP_MINFO_FUNCTION(ifx_pdo)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "ifx_pdo support", "enabled");
    php_info_print_table_row(2, "Version", PHP_IFX_PDO_VERSION);
    php_info_print_table_row(2, "Implementation", "PDO informix + embedded compatibility engine");
    php_info_print_table_row(2, "Requires", "pdo_informix");
    php_info_print_table_end();
    DISPLAY_INI_ENTRIES();
}

PHP_FUNCTION(ifx_connect)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::connect", le_ifx_link);
}

PHP_FUNCTION(ifx_pconnect)
{
    uint32_t argc;
    zval *argv;
    zval retval;
    zval args[4];
    uint32_t i;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);

    /* ifx_pconnect(db, user, parola) sau forme parțiale; al patrulea
       argument este flagul de persistență, nu un parametru al utilizatorului. */
    for (i = 0; i < 3; i++) {
        if (i < argc) {
            ZVAL_COPY_VALUE(&args[i], &argv[i]);
        } else {
            ZVAL_NULL(&args[i]);
        }
    }
    ZVAL_TRUE(&args[3]);

    ifx_call_static("IfxCompatApi::connect", &retval, 4, args);

    for (i = 0; i < 4; i++) {
        zval_ptr_dtor(&args[i]);
    }
    for (i = 3; i < argc; i++) {
        zval_ptr_dtor(&argv[i]);
    }
    if (argv) {
        efree(argv);
    }
    ifx_return_object(return_value, &retval, le_ifx_link);
}

PHP_FUNCTION(ifx_close)
{
    uint32_t argc;
    zval *argv;
    ifx_handle *handle = NULL;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    if (argc > 0 && Z_TYPE(argv[0]) == IS_RESOURCE) {
        handle = (ifx_handle *) zend_fetch_resource_ex(&argv[0], "ifx link", le_ifx_link);
    }
    if (!ifx_unwrap_arg(argc, argv, 0, le_ifx_link, "link", "ifx_close")) {
        ifx_release_args(argc, argv);
        RETURN_FALSE;
    }
    ifx_call_static("IfxCompatApi::close", &retval, argc, argv);
    ifx_release_args(argc, argv);
    if (Z_TYPE(retval) == IS_TRUE && handle) {
        handle->freed = 1;
    }
    ZVAL_COPY_VALUE(return_value, &retval);
}

PHP_FUNCTION(ifx_query)
{
    uint32_t argc;
    zval *argv;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    if (argc > 1 && !ifx_unwrap_arg(argc, argv, 1, le_ifx_link, "link", "ifx_query")) {
        ifx_release_args(argc, argv);
        RETURN_FALSE;
    }
    ifx_call_static("IfxCompatApi::query", &retval, argc, argv);
    ifx_release_args(argc, argv);
    ifx_return_object(return_value, &retval, le_ifx_result);
}

PHP_FUNCTION(ifx_prepare)
{
    uint32_t argc;
    zval *argv;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    if (argc > 1 && !ifx_unwrap_arg(argc, argv, 1, le_ifx_link, "link", "ifx_prepare")) {
        ifx_release_args(argc, argv);
        RETURN_FALSE;
    }
    ifx_call_static("IfxCompatApi::prepare", &retval, argc, argv);
    ifx_release_args(argc, argv);
    ifx_return_object(return_value, &retval, le_ifx_result);
}

PHP_FUNCTION(ifx_do)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::do", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_fetch_row)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::fetchRow", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_htmltbl_result)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::htmlTable", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_fieldtypes)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::fieldTypes", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_fieldproperties)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::fieldProperties", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_num_fields)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::numFields", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_num_rows)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::numRows", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_affected_rows)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::affectedRows", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_getsqlca)
{
    ifx_forward_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::sqlca", le_ifx_result, "result");
}

PHP_FUNCTION(ifx_free_result)
{
    uint32_t argc;
    zval *argv;
    ifx_handle *handle = NULL;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    if (argc > 0 && Z_TYPE(argv[0]) == IS_RESOURCE) {
        handle = (ifx_handle *) zend_fetch_resource_ex(&argv[0], "ifx result", le_ifx_result);
    }
    if (!ifx_unwrap_arg(argc, argv, 0, le_ifx_result, "result", "ifx_free_result")) {
        ifx_release_args(argc, argv);
        RETURN_FALSE;
    }
    ifx_call_static("IfxCompatApi::freeResult", &retval, argc, argv);
    ifx_release_args(argc, argv);
    if (Z_TYPE(retval) == IS_TRUE && handle) {
        handle->freed = 1;
    }
    ZVAL_COPY_VALUE(return_value, &retval);
}

PHP_FUNCTION(ifx_error)
{
    uint32_t argc;
    zval *argv;
    zval retval;

    ifx_collect_args(INTERNAL_FUNCTION_PARAM_PASSTHRU, &argc, &argv);
    if (argc > 0 && !ifx_unwrap_arg(argc, argv, 0, le_ifx_link, "link", "ifx_error")) {
        ifx_release_args(argc, argv);
        RETURN_FALSE;
    }
    ifx_call_static("IfxCompatApi::error", &retval, argc, argv);
    ifx_release_args(argc, argv);
    ZVAL_COPY_VALUE(return_value, &retval);
}

PHP_FUNCTION(ifx_errormsg)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::errorMessage", 0);
}

PHP_FUNCTION(ifx_create_blob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::createBlob", 0);
}

PHP_FUNCTION(ifx_copy_blob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::copyBlob", 0);
}

PHP_FUNCTION(ifx_free_blob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::freeBlob", 0);
}

PHP_FUNCTION(ifx_get_blob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::getBlob", 0);
}

PHP_FUNCTION(ifx_update_blob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::updateBlob", 0);
}

PHP_FUNCTION(ifx_blobinfile_mode)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::blobinfileMode", 0);
}

PHP_FUNCTION(ifx_textasvarchar)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::textAsVarchar", 0);
}

PHP_FUNCTION(ifx_byteasvarchar)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::byteAsVarchar", 0);
}

PHP_FUNCTION(ifx_charasvarchar)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::charAsVarchar", 0);
}

PHP_FUNCTION(ifx_nullformat)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::nullFormat", 0);
}

PHP_FUNCTION(ifx_create_char)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::createChar", 0);
}

PHP_FUNCTION(ifx_free_char)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::freeChar", 0);
}

PHP_FUNCTION(ifx_get_char)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::getChar", 0);
}

PHP_FUNCTION(ifx_update_char)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::updateChar", 0);
}

PHP_FUNCTION(ifxus_create_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::createSlob", 0);
}

PHP_FUNCTION(ifxus_free_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::freeSlob", 0);
}

PHP_FUNCTION(ifxus_close_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::closeSlob", 0);
}

PHP_FUNCTION(ifxus_open_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::openSlob", 0);
}

PHP_FUNCTION(ifxus_seek_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::seekSlob", 0);
}

PHP_FUNCTION(ifxus_tell_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::tellSlob", 0);
}

PHP_FUNCTION(ifxus_read_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::readSlob", 0);
}

PHP_FUNCTION(ifxus_write_slob)
{
    ifx_forward(INTERNAL_FUNCTION_PARAM_PASSTHRU, "IfxCompatApi::writeSlob", 0);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_ifx_variadic, 0, 0, 0)
    ZEND_ARG_VARIADIC_INFO(0, args)
ZEND_END_ARG_INFO()

static const zend_function_entry ifx_functions[] = {
    PHP_FE(ifx_connect, arginfo_ifx_variadic)
    PHP_FE(ifx_pconnect, arginfo_ifx_variadic)
    PHP_FE(ifx_close, arginfo_ifx_variadic)
    PHP_FE(ifx_query, arginfo_ifx_variadic)
    PHP_FE(ifx_prepare, arginfo_ifx_variadic)
    PHP_FE(ifx_do, arginfo_ifx_variadic)
    PHP_FE(ifx_error, arginfo_ifx_variadic)
    PHP_FE(ifx_errormsg, arginfo_ifx_variadic)
    PHP_FE(ifx_affected_rows, arginfo_ifx_variadic)
    PHP_FE(ifx_getsqlca, arginfo_ifx_variadic)
    PHP_FE(ifx_fetch_row, arginfo_ifx_variadic)
    PHP_FE(ifx_htmltbl_result, arginfo_ifx_variadic)
    PHP_FE(ifx_fieldtypes, arginfo_ifx_variadic)
    PHP_FE(ifx_fieldproperties, arginfo_ifx_variadic)
    PHP_FE(ifx_num_fields, arginfo_ifx_variadic)
    PHP_FE(ifx_num_rows, arginfo_ifx_variadic)
    PHP_FE(ifx_free_result, arginfo_ifx_variadic)
    PHP_FE(ifx_create_blob, arginfo_ifx_variadic)
    PHP_FE(ifx_copy_blob, arginfo_ifx_variadic)
    PHP_FE(ifx_free_blob, arginfo_ifx_variadic)
    PHP_FE(ifx_get_blob, arginfo_ifx_variadic)
    PHP_FE(ifx_update_blob, arginfo_ifx_variadic)
    PHP_FE(ifx_blobinfile_mode, arginfo_ifx_variadic)
    PHP_FE(ifx_textasvarchar, arginfo_ifx_variadic)
    PHP_FE(ifx_byteasvarchar, arginfo_ifx_variadic)
    PHP_FE(ifx_charasvarchar, arginfo_ifx_variadic)
    PHP_FE(ifx_nullformat, arginfo_ifx_variadic)
    PHP_FE(ifx_create_char, arginfo_ifx_variadic)
    PHP_FE(ifx_free_char, arginfo_ifx_variadic)
    PHP_FE(ifx_get_char, arginfo_ifx_variadic)
    PHP_FE(ifx_update_char, arginfo_ifx_variadic)
    PHP_FE(ifxus_create_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_free_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_close_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_open_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_seek_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_tell_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_read_slob, arginfo_ifx_variadic)
    PHP_FE(ifxus_write_slob, arginfo_ifx_variadic)
    PHP_FE_END
};

static const zend_module_dep ifx_pdo_deps[] = {
    ZEND_MOD_REQUIRED("pdo")
    ZEND_MOD_END
};

zend_module_entry ifx_pdo_module_entry = {
    STANDARD_MODULE_HEADER_EX,
    NULL,
    ifx_pdo_deps,
    "ifx_pdo",
    ifx_functions,
    PHP_MINIT(ifx_pdo),
    PHP_MSHUTDOWN(ifx_pdo),
    PHP_RINIT(ifx_pdo),
    NULL,
    PHP_MINFO(ifx_pdo),
    PHP_IFX_PDO_VERSION,
    PHP_MODULE_GLOBALS(ifx),
    PHP_GINIT(ifx),
    NULL,
    NULL,
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_IFX_PDO
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(ifx_pdo)
#endif


