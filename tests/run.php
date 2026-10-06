<?php
/**
 * Genisys PHP 8.x — 轻量测试器
 *
 * 用法：php tests/run.php [名称过滤]
 * 无任何外部依赖（无 phpunit/vendor）。测试用例放在 tests/cases/ 下，
 * 每个文件调用 test('名称', function() { ... }) 注册用例，
 * 用 check()/check_same()/check_throws() 断言。
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['__tests'] = [];
$GLOBALS['__failures'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function check(bool $cond, string $what = ''): void
{
    if (!$cond) {
        throw new RuntimeException($what !== '' ? $what : 'check() failed');
    }
}

function check_same(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($what !== '' ? "$what: " : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function check_throws(callable $fn, string $class = \Throwable::class, string $what = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            throw new RuntimeException(($what !== '' ? "$what: " : '') .
                'expected ' . $class . ', got ' . get_class($e));
        }
        return;
    }
    throw new RuntimeException(($what !== '' ? "$what: " : '') . "expected $class to be thrown, nothing was");
}

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $caseFile) {
    require_once $caseFile;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        echo "  PASS  $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  $name\n        " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
