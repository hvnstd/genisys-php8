<?php
/**
 * Module: SchedulerModule
 * 职责：异步任务调度（替代 ServerScheduler + ReversePriorityQueue）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：Fiber + SplPriorityQueue（最小堆）
 *
 * @author  Genisys Port Team
 * @version  1.0.0
 * @since    PHP 8.1
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\ThreadInterface;
use Genisys\Compat\WorkerInterface;
use Genisys\Compat\ThreadManagerInterface;

// ────────────────────────────────────────────────────────────────────────
// TaskHandler — 任务句柄
// ────────────────────────────────────────────────────────────────────────

/**
 * 任务句柄 — 封装任务的元数据与生命周期状态。
 *
 * 每个通过调度器提交的任务都会被包装为 TaskHandler，
 * 携带执行时间、重复周期、超时控制、取消标志等信息。
 */
class TaskHandler
{
    /** @var bool 是否已被取消 */
    private bool $cancelled = false;

    /** @var bool 是否已从队列中移除 */
    private bool $removed = false;

    /** @var float 任务开始执行时的微时间戳 */
    private float $startTime = 0.0;

    /**
     * 构造一个新的任务句柄。
     *
     * @param string    $id        任务唯一标识
     * @param mixed     $task      任务回调
     * @param int       $delay     初始延迟 tick 数（-1 表示无延迟）
     * @param int       $period    重复周期 tick 数（-1 表示不重复，>0 表示每隔 period tick 重复）
     * @param float     $nextRun   下次执行时间（tick 单位）
     * @param float|null $timeout  超时时间（秒），null 表示不限制
     */
    public function __construct(
        private string   $id,
        private mixed    $task,
        private int      $delay,
        private int      $period,
        private float    $nextRun,
        private ?float   $timeout = null
    ) {
    }

    /**
     * 获取任务 ID。
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取任务回调。
     *
     * @return mixed
     */
    public function getTask(): mixed
    {
        return $this->task;
    }

    /**
     * 获取初始延迟 tick 数。
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * 获取重复周期 tick 数。
     *
     * @return int -1 表示不重复，>0 表示重复周期
     */
    public function getPeriod(): int
    {
        return $this->period;
    }

    /**
     * 获取下次执行时间（tick 单位）。
     */
    public function getNextRun(): float
    {
        return $this->nextRun;
    }

    /**
     * 设置下次执行时间。
     */
    public function setNextRun(float $tick): void
    {
        $this->nextRun = $tick;
    }

    /**
     * 检查任务是否已被取消。
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * 取消此任务。
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * 检查是否为重复任务。
     */
    public function isRepeating(): bool
    {
        return $this->period > 0;
    }

    /**
     * 检查是否为延迟任务。
     */
    public function isDelayed(): bool
    {
        return $this->delay > 0;
    }

    /**
     * 获取超时时间（秒）。
     *
     * @return float|null null 表示不限制
     */
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }

    /**
     * 获取任务开始执行时的微时间戳。
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * 设置任务开始执行时的微时间戳。
     */
    public function setStartTime(float $time): void
    {
        $this->startTime = $time;
    }

    /**
     * 检查任务是否已从队列中移除。
     */
    public function isRemoved(): bool
    {
        return $this->removed;
    }

    /**
     * 标记任务为已移除。
     */
    public function remove(): void
    {
        $this->removed = true;
    }
}

// ────────────────────────────────────────────────────────────────────────
// ReversePriorityQueue — 最小堆优先级队列
// ────────────────────────────────────────────────────────────────────────

/**
 * 最小堆优先级队列 — 基于 \SplPriorityQueue 实现。
 *
 * SplPriorityQueue 默认行为是最大堆（高优先级先出）。
 * 此类反转比较逻辑，实现最小堆：数值越小（时间越早）的任务优先级越高。
 *
 * 用于调度器中按 nextRun 时间升序提取任务，确保到期任务优先执行。
 */
class ReversePriorityQueue extends \SplPriorityQueue
{
    /**
     * 比较两个优先级，实现最小堆语义。
     *
     * @param mixed $priority1 前一个优先级（nextRun 时间）
     * @param mixed $priority2 后一个优先级（nextRun 时间）
     * @return int 负数表示 $priority1 优先级更高（更早执行）
     */
    public function compare($priority1, $priority2): int
    {
        return (int) -($priority1 - $priority2);
    }
}

// ────────────────────────────────────────────────────────────────────────
// SchedulerModule — Fiber 协程任务调度器
// ────────────────────────────────────────────────────────────────────────

/**
 * Fiber 协程任务调度器 — 替代原 pthreads 线程模型。
 *
 * 核心特性：
 * - 基于 PHP 8.1+ Fiber 的协程调度循环
 * - 最小堆优先级队列（ReversePriorityQueue）
 * - 定时任务（scheduleTask）与即时任务（addTask）混合调度
 * - 任务取消与超时机制
 * - 线程安全的接口兼容（WorkerInterface）
 *
 * @see WorkerInterface
 * @see TaskHandler
 * @see ReversePriorityQueue
 */
class SchedulerModule implements WorkerInterface
{
    /** @var bool 是否收到关闭信号 */
    private bool $shutdown = false;

    /** @var ReversePriorityQueue 任务优先级队列（最小堆） */
    private ReversePriorityQueue $queue;

    /** @var array<string, TaskHandler> 已注册任务映射（id => handler） */
    private array $tasks = [];

    /** @var float 当前 tick 计数（每 tick = maxTickMs 毫秒） */
    private float $currentTick = 0.0;

    /** @var int 每 tick 对应的毫秒数（默认 20ms = 50Hz） */
    private int $maxTickMs = 20;

    /** @var Fiber|null 调度器主 Fiber */
    private ?Fiber $schedulerFiber = null;

    /** @var bool Fiber 是否正在运行 */
    private bool $fiberRunning = false;

    /**
     * 构造调度器。
     *
     * @param ThreadManagerInterface $threadManager 线程管理器（用于线程间通信）
     */
    public function __construct(
        private ThreadManagerInterface $threadManager
    ) {
        $this->queue = new ReversePriorityQueue();
        $this->queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    /**
     * {@inheritdoc}
     */
    public function getThreadName(): string
    {
        return 'Scheduler';
    }

    /**
     * {@inheritdoc}
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * {@inheritdoc}
     */
    public function shutdown(): void
    {
        $this->shutdown = true;
    }

    /**
     * {@inheritdoc}
     */
    public function isAlive(): bool
    {
        return !$this->shutdown;
    }

    /**
     * {@inheritdoc}
     */
    public function start(): void
    {
        $this->onRun();
    }

    /**
     * {@inheritdoc}
     *
     * Fiber 模式下无需阻塞 join，协程由调度器内部管理。
     * 轮询等待 Fiber 运行结束。
     */
    public function join(): void
    {
        while ($this->fiberRunning) {
            usleep(10000); // 10ms
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->onRun();
    }

    // ── 调度循环 ────────────────────────────────────────────────────

    /**
     * 主调度循环 — 使用 Fiber 协程包装。
     *
     * 创建一个 Fiber 作为调度循环的执行上下文，
     * 替代原 pthreads 线程模型。每次 tick 处理到期任务后
     * 通过 Fiber::suspend() 让出控制权，实现协程式调度。
     */
    public function onRun(): void
    {
        $this->fiberRunning = true;

        // 创建调度 Fiber — 封装完整的 tick 循环
        $this->schedulerFiber = new Fiber(function (): void {
            while (!$this->isShutdown()) {
                $this->tick();
                // 让出控制权，允许其他 Fiber 或事件循环接管
                Fiber::suspend();
            }
        });

        // 主循环：按固定频率恢复调度 Fiber
        while (!$this->isShutdown()) {
            // 如果 Fiber 已终止（理论上不会，因为循环是无限的），重新创建
            if ($this->schedulerFiber->isTerminated()) {
                $this->schedulerFiber = new Fiber(function (): void {
                    while (!$this->isShutdown()) {
                        $this->tick();
                        Fiber::suspend();
                    }
                });
            }

            // 恢复调度 Fiber，执行一个 tick
            $this->schedulerFiber->resume();

            // 控制循环频率：每 maxTickMs 毫秒执行一个 tick
            usleep($this->maxTickMs * 1000);
        }

        $this->fiberRunning = false;
    }

    /**
     * 执行一个调度 tick。
     *
     * 从优先级队列中提取所有到期任务，按优先级顺序执行。
     * 重复任务在执行后会重新入队。
     */
    private function tick(): void
    {
        $this->currentTick += 1.0;
        $currentTime = $this->currentTick;

        // 按优先级顺序处理所有到期任务
        while (!$this->queue->isEmpty()) {
            /** @var TaskHandler $handler */
            $handler = $this->queue->extract();

            if (!$handler instanceof TaskHandler) {
                continue;
            }

            // 任务尚未到期 — 放回队列并停止本轮处理
            if ($handler->getNextRun() > $currentTime) {
                $this->queue->insert($handler, $handler->getNextRun());
                break;
            }

            // 跳过已取消或已移除的任务
            if ($handler->isCancelled() || $handler->isRemoved()) {
                unset($this->tasks[$handler->getId()]);
                continue;
            }

            // 执行任务
            $this->executeTask($handler);

            // 重复任务：重新计算下次执行时间并入队
            if ($handler->isRepeating() && !$handler->isCancelled()) {
                $handler->setNextRun($this->currentTick + $handler->getPeriod());
                $this->queue->insert($handler, $handler->getNextRun());
            } else {
                // 一次性任务：标记移除并从注册表中删除
                $handler->remove();
                unset($this->tasks[$handler->getId()]);
            }
        }
    }

    // ── 任务执行 ────────────────────────────────────────────────────

    /**
     * 执行单个任务 — 使用 Fiber 包装，支持超时控制。
     *
     * @param TaskHandler $handler 任务句柄
     */
    private function executeTask(TaskHandler $handler): void
    {
        $task      = $handler->getTask();
        $timeout   = $handler->getTimeout();
        $startTime = microtime(true);
        $handler->setStartTime($startTime);

        try {
            // 使用 Fiber 包装任务执行，支持协程式挂起/恢复
            $fiber = new Fiber(function () use ($task, $handler): void {
                try {
                    call_user_func($task);
                } catch (\Throwable $e) {
                    error_log(
                        "Scheduler task error [{$handler->getId()}]: "
                        . $e->getMessage()
                    );
                }
            });

            // 启动 Fiber
            if (!$fiber->isStarted()) {
                $fiber->start();
            }

            // 持续恢复 Fiber 直到完成或超时
            while (!$fiber->isTerminated()) {
                $fiber->resume();

                // 超时检查
                if ($timeout !== null && (microtime(true) - $startTime) > $timeout) {
                    $handler->cancel();
                    error_log(
                        "Scheduler task [{$handler->getId()}] "
                        . "timed out after {$timeout}s"
                    );
                    break;
                }
            }

            // 最终超时检查（防止 Fiber 恰好在超时边界完成）
            if (
                $timeout !== null
                && !$handler->isCancelled()
                && (microtime(true) - $startTime) > $timeout
            ) {
                $handler->cancel();
                error_log(
                    "Scheduler task [{$handler->getId()}] "
                    . "timed out after {$timeout}s"
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "Scheduler task execution error [{$handler->getId()}]: "
                . $e->getMessage()
            );
        }
    }

    // ── 公共 API ────────────────────────────────────────────────────

    /**
     * 调度定时（重复）任务。
     *
     * @param int           $intervalTicks 间隔 tick 数（每 tick = maxTickMs 毫秒）
     * @param callable      $task         任务回调
     * @param int           $delay        初始延迟 tick 数（默认 0，立即执行）
     * @param float|null    $timeout      超时时间（秒），null 表示不限制
     * @return string 任务唯一 ID
     *
     * @example
     * ```php
     * $id = $scheduler->scheduleTask(20, fn() => echo "Every 400ms");
     * ```
     */
    public function scheduleTask(
        int      $intervalTicks,
        callable $task,
        int      $delay    = 0,
        ?float   $timeout  = null
    ): string {
        $id = $this->generateTaskId();

        $handler = new TaskHandler(
            id:       $id,
            task:     $task,
            delay:    $delay,
            period:   $intervalTicks,
            nextRun:  $this->currentTick + $delay,
            timeout:  $timeout
        );

        $this->tasks[$id] = $handler;
        $this->queue->insert($handler, $handler->getNextRun());

        return $id;
    }

    /**
     * 添加即时任务（立即执行，不重复）。
     *
     * @param callable   $task     任务回调
     * @param float|null $timeout  超时时间（秒），null 表示不限制
     * @return string 任务唯一 ID
     *
     * @example
     * ```php
     * $id = $scheduler->addTask(fn() => doWork());
     * ```
     */
    public function addTask(callable $task, ?float $timeout = null): string
    {
        return $this->scheduleTask(0, $task, 0, $timeout);
    }

    /**
     * 取消指定任务。
     *
     * @param string $taskId 任务 ID
     * @return bool 是否成功取消（任务存在且已标记取消则返回 true）
     */
    public function cancelTask(string $taskId): bool
    {
        if (isset($this->tasks[$taskId])) {
            $this->tasks[$taskId]->cancel();
            unset($this->tasks[$taskId]);
            return true;
        }
        return false;
    }

    /**
     * 取消所有已注册任务。
     *
     * 清空任务注册表和优先级队列。
     */
    public function cancelAllTasks(): void
    {
        foreach ($this->tasks as $handler) {
            $handler->cancel();
        }
        $this->tasks = [];

        // 清空优先级队列
        while (!$this->queue->isEmpty()) {
            $this->queue->extract();
        }
    }

    /**
     * 检查指定任务是否仍在队列中。
     *
     * @param string $taskId 任务 ID
     * @return bool
     */
    public function isQueued(string $taskId): bool
    {
        return isset($this->tasks[$taskId]);
    }

    /**
     * 获取当前 tick 计数。
     *
     * @return float
     */
    public function getCurrentTick(): float
    {
        return $this->currentTick;
    }

    /**
     * 获取队列中活跃任务数量。
     *
     * @return int
     */
    public function getQueueSize(): int
    {
        return count($this->tasks);
    }

    // ── 内部工具 ────────────────────────────────────────────────────

    /**
     * 生成唯一的任务 ID。
     *
     * @return string
     */
    private function generateTaskId(): string
    {
        return (string) spl_object_id(new \stdClass())
            . '_'
            . random_int(0, 999_999);
    }
}