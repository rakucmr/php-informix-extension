PHP_ARG_ENABLE([ifx_pdo],
  [whether to enable ifx_pdo compatibility support],
  [AS_HELP_STRING([--enable-ifx-pdo], [Enable ifx_pdo compatibility support over PDO])],
  [no])

if test "$PHP_IFX_PDO" != "no"; then
  AC_DEFINE([HAVE_IFX_PDO], [1], [ifx_pdo compatibility support])
  PHP_NEW_EXTENSION([ifx_pdo], [ifx.c ifx_php.c], [$ext_shared])
  PHP_ADD_EXTENSION_DEP([ifx_pdo], [pdo], [true])
fi
