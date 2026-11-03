<?php
/**
 * Test configuration.
 *
 * All connection details are supplied through environment variables so no
 * server name or credential is stored in the repository:
 *   PDO_INFORMIX_DSN, PDO_INFORMIX_USER, PDO_INFORMIX_PASS
 *   PDO_INFORMIX_REPORT_DSN, PDO_INFORMIX_REPORT_USER, PDO_INFORMIX_REPORT_PASS
 *
 * The connection-string form (contains "=") goes through SQLDriverConnect, so it
 * needs host/service/protocol/database/server. The plain form ("informix:database")
 * would go through SQLConnect and therefore needs a DSN in odbc.ini.
 */

$dsn = getenv('PDO_INFORMIX_DSN');
$user = getenv('PDO_INFORMIX_USER');
$password = getenv('PDO_INFORMIX_PASS');
if ($dsn === false || $dsn === '' || $user === false || $user === '' || $password === false) {
    throw new RuntimeException('Setați PDO_INFORMIX_DSN, PDO_INFORMIX_USER și PDO_INFORMIX_PASS.');
}

$reportDsn = getenv('PDO_INFORMIX_REPORT_DSN');
$reportUser = getenv('PDO_INFORMIX_REPORT_USER');
$reportPassword = getenv('PDO_INFORMIX_REPORT_PASS');

return [
    'dsn' => $dsn,
    'user' => $user,
    'pass' => $password,

    // Connection used by report.php to store the result of each test run.
    'report' => [
        'dsn' => $reportDsn !== false && $reportDsn !== '' ? $reportDsn : $dsn,
        'user' => $reportUser !== false && $reportUser !== '' ? $reportUser : $user,
        'pass' => $reportPassword !== false && $reportPassword !== '' ? $reportPassword : $password,
        'table' => getenv('PDO_INFORMIX_REPORT_TABLE') ?: 'test_results',
    ],
];
