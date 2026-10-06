<?php
/**
 * Genisys PHP 8.x 兼容层 — 线程管理器
 *
 * 替代原版 ThreadManager（pthreads Volatile 共享注册表）。
 * PHP 8.x 下没有跨线程共享内存，这里退化为进程内的
 * ThreadInterface 对象注册表：登记、注销、存活查询与信号广播。
 */
declare(strict_types=1);

namespace Genisys\Compat;

final class SimpleThreadManager implements ThreadManagerInterface
{
    private static ?SimpleThreadManager $instance = null;

    /** @var array<int, ThreadInterface> spl_object_id => thread */
    private array $threads = [];

    /** @var bool signalAll() 后待消费的广播标志 */
    private bool $signalled = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add(ThreadInterface $thread): void
    {
        $this->threads[spl_object_id($thread)] = $thread;
    }

    public function remove(ThreadInterface $thread): void
    {
        unset($this->threads[spl_object_id($thread)]);
    }

    public function getThreads(): array
    {
        return array_values($this->threads);
    }

    public function signalAll(): void
    {
        $this->signalled = true;
    }

    /**
     * 消费广播信号（原版 pthreads 条件变量 wait 的轮询等价物）。
     */
    public function consumeSignal(): bool
    {
        $signalled = $this->signalled;
        $this->signalled = false;
        return $signalled;
    }
}
