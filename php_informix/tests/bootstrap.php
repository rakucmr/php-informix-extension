<?php

declare(strict_types=1);

/**
 * Bootstrap pentru testele de integrare ifx-compat.
 *
 * Mediul necesar:
 *   INFORMIXDIR, INFORMIXSERVER, INFORMIXSQLHOSTS, PATH cu binarele CSDK
 *   IFX_TEST_DSN       (obligatoriu: "baza@server" sau "informix:...")
 *   IFX_TEST_USER      (poate fi gol pentru autentificare OS locală)
 *   IFX_TEST_PASSWORD  (poate fi gol pentru autentificare OS locală)
 *   IFX_TEST_SERVER    (opțional, altfel se deduce din DSN)
 *   IFX_TEST_DATABASE  (opțional, altfel se deduce din DSN)
 *
 * Testele rulează într-un singur proces; fiecare fișier începe cu reset_state().
 */

require_once __DIR__ . '/../src/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

final class SkipTest extends \Exception
{
}

final class TestFailure extends \Exception
{
}

$GLOBALS['__ifx_stats'] = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$GLOBALS['__ifx_failures'] = [];
$GLOBALS['__ifx_warnings'] = [];
$GLOBALS['__ifx_current'] = '';

set_error_handler(static function (int $errno, string $errstr): bool {
    if ((error_reporting() & $errno) === 0) {
        return false;
    }
    $GLOBALS['__ifx_warnings'][] = $errstr;

    return true;
});

function reset_state() {
    \Informix\Api::reset();
    $GLOBALS['__ifx_warnings'] = [];
}

function ifx_test_env(string $name, bool $allowEmpty = false): string
{
    $value = getenv($name);
    if ($value === false || (!$allowEmpty && $value === '')) {
        throw new \RuntimeException('Setați ' . $name . ' pentru testele de integrare.');
    }

    return $value;
}

function ifx_test_dsn(): string
{
    return ifx_test_env('IFX_TEST_DSN');
}

function ifx_test_user(): string
{
    return ifx_test_env('IFX_TEST_USER', true);
}

function ifx_test_password(): string
{
    return ifx_test_env('IFX_TEST_PASSWORD', true);
}

function ifx_test_server(): string
{
    $value = getenv('IFX_TEST_SERVER');
    if ($value !== false && $value !== '') {
        return $value;
    }
    $dsn = ifx_test_dsn();
    if (preg_match('/^[^@]+@(.+)$/', $dsn, $matches) === 1) {
        return $matches[1];
    }
    if (preg_match('/server=([^;]+)/', $dsn, $matches) === 1) {
        return $matches[1];
    }
    throw new \RuntimeException('Nu pot deduce serverul din IFX_TEST_DSN; setați IFX_TEST_SERVER.');
}

function ifx_test_database(): string
{
    $value = getenv('IFX_TEST_DATABASE');
    if ($value !== false && $value !== '') {
        return $value;
    }
    $dsn = ifx_test_dsn();
    if (preg_match('/^([^@]+)@/', $dsn, $matches) === 1) {
        return $matches[1];
    }
    if (preg_match('/database=([^;]+)/', $dsn, $matches) === 1) {
        return $matches[1];
    }
    throw new \RuntimeException('Nu pot deduce baza din IFX_TEST_DSN; setați IFX_TEST_DATABASE.');
}

function ifx_test_connect(): \Informix\Connection
{
    $conn = ifx_connect(ifx_test_dsn(), ifx_test_user(), ifx_test_password());
    if (!$conn instanceof \Informix\Connection) {
        throw new \RuntimeException('Nu pot conecta la ' . ifx_test_dsn());
    }

    return $conn;
}

function warnings(): array
{
    return $GLOBALS['__ifx_warnings'];
}

function clear_warnings() {
    $GLOBALS['__ifx_warnings'] = [];
}

function test(string $name, callable $fn, bool $allowWarnings = false) {
    $GLOBALS['__ifx_current'] = $name;
    $GLOBALS['__ifx_warnings'] = [];
    try {
        $fn();
    } catch (SkipTest $e) {
        $GLOBALS['__ifx_stats']['skip']++;
        echo "  SKIP  {$name} — {$e->getMessage()}\n";

        return;
    } catch (TestFailure $e) {
        fail_test($name, $e->getMessage());

        return;
    } catch (\Throwable $e) {
        fail_test($name, get_class($e) . ': ' . $e->getMessage(), $e);

        return;
    }

    $caught = $GLOBALS['__ifx_warnings'];
    if ($caught !== [] && !$allowWarnings) {
        fail_test($name, 'avertismente neașteptate: ' . implode(' | ', $caught));

        return;
    }

    $GLOBALS['__ifx_stats']['pass']++;
    echo "  PASS  {$name}\n";
}

function fail_test(string $name, string $message, $previous = null) {
    $GLOBALS['__ifx_stats']['fail']++;
    $GLOBALS['__ifx_failures'][] = ['name' => $name, 'message' => $message];
    echo "  FAIL  {$name}\n        {$message}\n";
    if ($previous !== null) {
        foreach (array_slice(explode("\n", $previous->getTraceAsString()), 0, 3) as $line) {
            echo "        {$line}\n";
        }
    }
}

function skip_test(string $reason) {
    throw new SkipTest($reason);
}

function assert_true($value, string $message = '') {
    if ($value !== true) {
        throw new TestFailure($message !== '' ? $message : 'Așteptam true, am primit ' . dump_value($value));
    }
}

function assert_false($value, string $message = '') {
    if ($value !== false) {
        throw new TestFailure($message !== '' ? $message : 'Așteptam false, am primit ' . dump_value($value));
    }
}

function assert_same($expected, $actual, string $message = '') {
    if ($expected !== $actual) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'așteptam ' . dump_value($expected) . ', am primit ' . dump_value($actual)
        );
    }
}

function assert_equals($expected, $actual, string $message = '') {
    if ($expected != $actual) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'așteptam ' . dump_value($expected) . ', am primit ' . dump_value($actual)
        );
    }
}

function assert_instance(string $class, $value, string $message = '') {
    if (!$value instanceof $class) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'așteptam instanță ' . $class . ', am primit ' . dump_value($value)
        );
    }
}

function assert_is_array($value, string $message = '') {
    if (!is_array($value)) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'așteptam array, am primit ' . dump_value($value));
    }
}

function assert_key(string $key, array $array, string $message = '') {
    if (!array_key_exists($key, $array)) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'lipsește cheia "' . $key . '" din ' . implode(', ', array_keys($array))
        );
    }
}

function assert_count(int $expected, array $array, string $message = '') {
    if (count($array) !== $expected) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'așteptam ' . $expected . ' elemente, am primit ' . count($array)
        );
    }
}

function assert_contains(string $needle, $haystack, string $message = '') {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'textul ' . dump_value($haystack) . ' nu conține ' . dump_value($needle)
        );
    }
}

function assert_not_contains(string $needle, $haystack, string $message = '') {
    if (is_string($haystack) && str_contains($haystack, $needle)) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'textul ' . dump_value($haystack) . ' conține ' . dump_value($needle)
        );
    }
}

function assert_matches(string $pattern, $value, string $message = '') {
    if (!is_string($value) || preg_match($pattern, $value) !== 1) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . dump_value($value) . ' nu se potrivește cu /' . $pattern . '/'
        );
    }
}

function assert_greater($actual, $minimum, string $message = '') {
    if ($actual <= $minimum) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'așteptam > ' . $minimum . ', am primit ' . $actual
        );
    }
}

function assert_not_false($value, string $message = '') {
    if ($value === false) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'am primit false');
    }
}

function dump_value($value): string
{
    if (is_object($value)) {
        return get_class($value);
    }
    if (is_string($value) && strlen($value) > 120) {
        return var_export(substr($value, 0, 120) . '…', true);
    }

    return var_export($value, true);
}

/**
 * @return array{0: mixed, 1: string}
 */
function capture_output(callable $fn): array
{
    ob_start();
    try {
        $result = $fn();
        $output = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return [$result, $output];
}
