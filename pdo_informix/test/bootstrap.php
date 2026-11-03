<?php
/**
 * Shared helpers for the pdo_informix test suite.
 * Every test exits 0 on success and 1 on failure.
 */

$GLOBALS['__failures'] = 0;

function pdo_informix_config(): array
{
    return require __DIR__ . '/config.php';
}

function connect(array $options = []): PDO
{
    $cfg = pdo_informix_config();

    return new PDO(
        $cfg['dsn'],
        $cfg['user'],
        $cfg['pass'],
        $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok) {
        echo "  ok    {$label}\n";
        return;
    }

    $GLOBALS['__failures']++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function check_equals(string $label, $expected, $actual): void
{
    check(
        $label,
        $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM systables WHERE tabname = ?");
    $stmt->execute([strtolower($table)]);

    return (int) $stmt->fetchColumn() > 0;
}

function drop_table(PDO $pdo, string $table): void
{
    try {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    } catch (PDOException $e) {
        // The table can still be locked by an open cursor; nothing else to do here.
    }
}

/**
 * Informix reports column labels in upper case (NAME, AMOUNT, ...), so fetched
 * rows are normalised to lower case keys for the assertions below.
 */
function row_lower(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        $out[strtolower((string) $key)] = $value;
    }

    return $out;
}

function finish(): void
{
    if ($GLOBALS['__failures'] > 0) {
        echo "FAILED: {$GLOBALS['__failures']} assertion(s)\n";
        exit(1);
    }

    echo "PASS\n";
    exit(0);
}

/**
 * Arguments needed to start a child PHP process with pdo_informix loaded.
 * Empty when the extension already comes from php.ini (e.g. in the Docker
 * image), otherwise the same -d flags the runner was started with.
 */
function pdo_informix_child_args(): array
{
    $files = [];
    if (($loaded = php_ini_loaded_file()) !== false) {
        $files[] = $loaded;
    }
    $scanned = php_ini_scanned_files();
    if (is_string($scanned) && $scanned !== '') {
        foreach (explode(',', $scanned) as $file) {
            $files[] = trim($file);
        }
    }

    $iniText = '';
    foreach ($files as $file) {
        $iniText .= (string) @file_get_contents($file);
    }

    if (stripos($iniText, 'pdo_informix') !== false) {
        return [];
    }

    return ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_informix'];
}
