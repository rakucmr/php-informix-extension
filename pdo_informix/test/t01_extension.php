<?php
/**
 * t01 - the extension is present, registers the PDO driver and reports a version.
 */

require __DIR__ . '/bootstrap.php';

check('extension_loaded("pdo_informix")', extension_loaded('pdo_informix'));
check('PDO::getAvailableDrivers() contains "informix"', in_array('informix', PDO::getAvailableDrivers(), true));

$version = phpversion('pdo_informix');
check('phpversion("pdo_informix") is not false', $version !== false, var_export($version, true));

if ($version !== false) {
    check('version looks like 1.3.x', (bool) preg_match('/^1\.3\.\d+$/', $version), $version);
}

$info = (string) phpversion('pdo');
check('pdo extension is loaded', $info !== '');

finish();
