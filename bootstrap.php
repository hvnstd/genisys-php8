<?php
/**
 * Genisys PHP 8.x — 引导器
 *
 * 统一加载兼容层与全部模块。多数模块文件在一个文件内声明多个类
 * （如 ItemModule.php 里的 Item 家族、NBTModule.php 里的 Tag 家族），
 * 无法按类名 autoload，因此采用逐文件 require_once 的引导方式。
 */
declare(strict_types=1);

foreach (['runtime', 'concurrency', 'weakref', 'yaml', 'threadmanager', 'binarystream'] as $compat) {
    require_once __DIR__ . '/compat/' . $compat . '.php';
}

// compat 层先行，模块可能在类声明期引用兼容接口（ThreadInterface 等）
foreach (glob(__DIR__ . '/modules/*.php') ?: [] as $moduleFile) {
    require_once $moduleFile;
}
