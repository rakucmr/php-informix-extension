/*
 * ifx_pdo native compatibility extension for PHP 7.0-8.5
 *
 * Reimplementarea API-ului procedural ifx_* / ifxus_* din ext/informix,
 * peste driverul PDO informix.
 */

#ifndef PHP_IFX_H
#define PHP_IFX_H

extern zend_module_entry ifx_pdo_module_entry;
#define phpext_ifx_pdo_ptr &ifx_pdo_module_entry

#define PHP_IFX_PDO_VERSION "1.1.0"

#ifdef PHP_WIN32
# define PHP_IFX_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
# define PHP_IFX_API __attribute__ ((visibility("default")))
#else
# define PHP_IFX_API
#endif

#ifdef ZTS
# include "TSRM.h"
#endif

ZEND_BEGIN_MODULE_GLOBALS(ifx)
    zend_bool blobinfile;
    zend_bool textasvarchar;
    zend_bool byteasvarchar;
    zend_bool charasvarchar;
    zend_bool nullformat;
    char *default_host;
    char *default_user;
    char *default_password;
    char *default_database;
    zend_bool allow_persistent;
    zend_long max_links;
    zend_long max_persistent;
    zend_bool htmltbl_escape;
    zend_long connect_timeout;
    zend_bool bootstrapped;
ZEND_END_MODULE_GLOBALS(ifx)

ZEND_EXTERN_MODULE_GLOBALS(ifx)
#define IFX_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(ifx, v)

#endif /* PHP_IFX_H */
