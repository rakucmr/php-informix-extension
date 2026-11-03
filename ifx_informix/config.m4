PHP_ARG_WITH([ifx],
  [for Informix direct support],
  [AS_HELP_STRING([--with-ifx[=DIR]], [Enable the direct ifx extension using the Informix Client SDK in DIR])],
  [no])

if test "$PHP_IFX" != "no"; then
  if test "$PHP_IFX" = "yes"; then
    IFX_DIR=/opt/ibm/informix
  else
    IFX_DIR=$PHP_IFX
  fi
  AC_DEFINE([HAVE_IFX], [1], [Direct Informix support])
  PHP_ADD_INCLUDE([$IFX_DIR/incl/cli])
  PHP_ADD_LIBRARY_WITH_PATH([ifcli], [$IFX_DIR/lib], [IFX_SHARED_LIBADD])
  PHP_SUBST([IFX_SHARED_LIBADD])
  PHP_NEW_EXTENSION([ifx], [ifx.c], [$ext_shared])
fi
