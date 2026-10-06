<?php
/**
 * Genisys PHP 8.x 兼容层 — 运行时 API 替换
 * 
 * 替换 PHP 7.0 中已废弃/移除的函数：
 * - each() → foreach + key()/next()
 * - create_function() → 匿名函数
 * - assert() → if + throw
 * - preg_replace /e → preg_replace_callback
 */
declare(strict_types=1);

namespace Genisys\Compat;

/**
 * 兼容性运行时辅助
 */
final class RuntimeCompat
{
    /**
     * 替代 each() — 遍历数组并返回当前键值
     * 与原 PHP each() 语义一致：按引用操作数组内部指针
     */
    public static function each(array &$array): ?array
    {
        $key = key($array);
        if ($key === null) {
            return null;
        }
        $value = current($array);
        next($array);
        return [$key, $value];
    }

    /**
     * 替代 create_function() — 创建匿名函数
     */
    public static function createFunction(string $args, string $body): \Closure
    {
        return eval("return function($args) { $body };");
    }

    /**
     * 替代 assert() — 条件断言
     */
    public static function assert($condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message ?: 'Assertion failed');
        }
    }
}