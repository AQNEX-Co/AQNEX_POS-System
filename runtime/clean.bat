@echo off
title AQNEX Runtime Cleaner
echo Cleaning Apache...

:: 1. تنظيف Apache
cd apache
del /f /q ABOUT_APACHE.txt CHANGES.txt INSTALL.txt LICENSE.txt NOTICE.txt README.txt
rd /s /q "conf\original"
rd /s /q "error"
rd /s /q "htdocs"
cd bin
del /f /q ab.exe abs.exe ApacheMonitor.exe htdbm.exe htdigest.exe httxt2dbm.exe logresolve.exe wintty.exe
rd /s /q "iconv"
cd ..\..

echo Cleaning PHP...
:: 2. تنظيف PHP
cd php
del /f /q deplister.exe license.txt news.txt readme-redist-bins.txt README.md snapshot.txt
rd /s /q "extras"
rd /s /q "lib"
cd ext
:: حذف الملحقات غير الضرورية (ابقاء الأساسيات فقط)
del /f /q php_bz2.dll php_com_dotnet.dll php_dba.dll php_dl_test.dll php_enchant.dll php_ffi.dll php_ftp.dll php_gmp.dll php_imap.dll php_ldap.dll php_oci8_19.dll php_odbc.dll php_pdo_firebird.dll php_pdo_oci.dll php_pdo_odbc.dll php_pdo_pgsql.dll php_pgsql.dll php_shmop.dll php_snmp.dll php_soap.dll php_sockets.dll php_sysvshm.dll php_tidy.dll php_xsl.dll php_zend_test.dll
cd ..\..

echo Cleaning MariaDB...
:: 3. تنظيف MariaDB
cd mariadb
del /f /q COPYING CREDITS README.md THIRDPARTY
rd /s /q "include"
rd /s /q "man"
:: تنظيف اللغات في MariaDB (ابقاء الإنجليزية فقط والترميزات)
cd share
for /d %%i in (*) do (
    if /i not "%%i"=="english" if /i not "%%i"=="charsets" rd /s /q "%%i"
)
del /f /q JavaWrappers.jar JdbcInterface.jar Mongo2.jar Mongo3.jar
cd ..\bin
:: حذف أدوات الماريا دي بي غير الضرورية للمستخدم العادي
del /f /q aria_chk.exe aria_dump_log.exe aria_ftdump.exe aria_pack.exe aria_read_log.exe innochecksum.exe mariabackup.exe mariadb-backup.exe mariadb-binlog.exe mariadb-conv.exe mariadb-import.exe mariadb-ldb.exe mariadb-plugin.exe mariadb-slap.exe mariadb-tzinfo-to-sql.exe mbstream.exe myisamchk.exe myisamlog.exe myisampack.exe myisam_ftdump.exe mysqlbinlog.exe mysqlimport.exe mysqlslap.exe mysql_ldb.exe mysql_plugin.exe mysql_tzinfo_to_sql.exe perror.exe replace.exe sst_dump.exe
cd ..\..

echo ---------------------------------------
echo Done! Runtime is now lightweight.
pause