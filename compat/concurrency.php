<?php
/**
 * Genisys PHP 8.x 兼容层 — 并发模型接口
 * 
 * 定义 Thread / Worker / ThreadManager 的抽象接口
 * 实现由 Fiber/parallel 扩展提供
 */
declare(strict_types=1);

namespace Genisys\Compat;

/**
 * 线程接口（替代 pthreads Thread）
 */
interface ThreadInterface
{
    public function start(): void;
    public function join(): void;
    public function isAlive(): bool;
    public function getThreadName(): string;
    public function onRun(): void;
}

/**
 * Worker 接口（替代 pthreads Worker）
 */
interface WorkerInterface extends ThreadInterface
{
    public function isShutdown(): bool;
    public function shutdown(): void;
    public function run(): void;
}

/**
 * 线程管理器接口（替代 ThreadManager + Volatile）
 */
interface ThreadManagerInterface
{
    public static function getInstance(): self;
    public function add(ThreadInterface $thread): void;
    public function remove(ThreadInterface $thread): void;
    public function getThreads(): array;
    public function signalAll(): void;
}

/**
 * 线程间通信通道（替代 Volatile 的共享内存）
 */
interface VolatileInterface
{
    public function get(string $key);
    public function set(string $key, $value): void;
    public function delete(string $key): void;
    public function has(string $key): bool;
}
