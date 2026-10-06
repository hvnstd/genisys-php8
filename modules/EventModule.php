<?php
/**
 * Module: EventModule
 * 职责：事件总线（6级优先级责任链，替代 Event/HandlerList）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：保持责任链模式，优化优先级排序
 *
 * 优先级定义（从高到低）：
 *   MONITOR  = 0  监控事件，不修改事件结果
 *   SYSTEM   = 1  系统级事件，最高优先级
 *   HIGH     = 2  高优先级
 *   NORMAL   = 3  普通优先级
 *   LOW      = 4  低优先级
 *   IGNORE   = 5  忽略（最低优先级，仅在需要时触发）
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\RuntimeCompat;

class EventModule
{
    // 6 级优先级（数值越小优先级越高）
    public const PRIORITY_IGNORE   = 5;
    public const PRIORITY_LOW      = 4;
    public const PRIORITY_NORMAL   = 3;
    public const PRIORITY_HIGH     = 2;
    public const PRIORITY_SYSTEM   = 1;
    public const PRIORITY_MONITOR  = 0;

    private array $handlers = [];     // eventName => [ ['priority' => int, 'handler' => callable, 'plugin' => string] ]
    private array $eventClasses = []; // eventClass => true
    private array $plugins = [];      // pluginName => true (已加载插件注册表)

    /**
     * 注册事件处理器
     *
     * @param string   $eventName  事件名称
     * @param callable $handler   处理函数
     * @param int      $priority  优先级（默认 NORMAL）
     * @param string   $plugin    插件名（可选）
     */
    public function registerHandler(string $eventName, callable $handler, int $priority = self::PRIORITY_NORMAL, string $plugin = ''): void
    {
        if ($priority < self::PRIORITY_MONITOR || $priority > self::PRIORITY_IGNORE) {
            return;
        }

        $this->handlers[$eventName][] = [
            'priority' => $priority,
            'handler'  => $handler,
            'plugin'   => $plugin,
        ];
        if ($plugin !== '') {
            $this->plugins[$plugin] = true;
        }
    }

    /**
     * 注册事件类（用于类型安全的事件分发）
     */
    public function registerEventClass(string $eventClass): void
    {
        $this->eventClasses[$eventClass] = true;
    }

    /**
     * 注册插件（用于事件清理）
     */
    public function registerPlugin(string $pluginName): void
    {
        $this->plugins[$pluginName] = true;
    }

    /**
     * 卸载插件
     */
    public function unregisterPlugin(string $pluginName): void
    {
        unset($this->plugins[$pluginName]);
        $this->unregisterPluginHandlers($pluginName);
    }

    /**
     * 触发事件（按优先级降序执行 — 高优先级先执行）
     *
     * 执行顺序：MONITOR → SYSTEM → HIGH → NORMAL → LOW → IGNORE
     * （数值越小越先执行）
     *
     * @param string  $eventName  事件名称
     * @param object  $event     事件对象
     * @return bool  事件是否被取消（true = 已取消）
     */
    public function callEvent(string $eventName, object $event): bool
    {
        if (!isset($this->handlers[$eventName])) {
            return false;
        }

        $handlers = $this->handlers[$eventName];

        // 按优先级降序排序（数值小 = 优先级高 = 先执行）
        usort($handlers, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $cancelled = false;
        foreach ($handlers as $entry) {
            try {
                call_user_func($entry['handler'], $event);

                // 检查事件是否被取消
                if ($this->isEventCancelled($event)) {
                    $cancelled = true;
                    break; // 高优先级处理器可以取消事件传播
                }
            } catch (\Throwable $e) {
                error_log("Event error [$eventName]: " . $e->getMessage());
            }
        }

        return $cancelled;
    }

    /**
     * 检查事件是否被取消
     * 兼容 Cancellable 接口和 isCancelled() 方法
     */
    private function isEventCancelled(object $event): bool
    {
        if (method_exists($event, 'isCancelled')) {
            try {
                return $event->isCancelled() === true;
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    /**
     * 获取所有已注册的事件名称
     *
     * @return array
     */
    public function getRegisteredEvents(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * 获取指定事件的处理器数量
     */
    public function getHandlerCount(string $eventName): int
    {
        return isset($this->handlers[$eventName]) ? count($this->handlers[$eventName]) : 0;
    }

    /**
     * 获取指定插件的所有事件处理器
     *
     * @param string $pluginName
     * @return array
     */
    public function getPluginHandlers(string $pluginName): array
    {
        $result = [];
        foreach ($this->handlers as $eventName => $entries) {
            foreach ($entries as $entry) {
                if ($entry['plugin'] === $pluginName) {
                    $result[$eventName][] = $entry;
                }
            }
        }
        return $result;
    }

    /**
     * 清理插件的所有事件处理器
     */
    public function unregisterPluginHandlers(string $pluginName): void
    {
        foreach ($this->handlers as $eventName => &$entries) {
            $this->handlers[$eventName] = array_values(
                array_filter(
                    $entries,
                    fn($e) => $e['plugin'] !== $pluginName
                )
            );
        }
        unset($entries);
    }

    /**
     * 惰性清理已卸载插件的残留处理器
     * 移除所有 plugin 不在已注册插件列表中的处理器
     */
    public function cleanup(): void
    {
        foreach ($this->handlers as $eventName => $entries) {
            $this->handlers[$eventName] = array_values(array_filter(
                $entries,
                fn($e) => $e['plugin'] === '' || isset($this->plugins[$e['plugin']])
            ));
        }
    }

    /**
     * 注销所有事件处理器
     */
    public function unregisterAll(): void
    {
        $this->handlers = [];
    }

    /**
     * 注销指定事件的所有处理器
     */
    public function unregisterEvent(string $eventName): void
    {
        unset($this->handlers[$eventName]);
    }

    /**
     * 获取所有已注册的插件名
     *
     * @return array
     */
    public function getRegisteredPlugins(): array
    {
        return array_keys($this->plugins);
    }

    /**
     * 获取优先级名称
     */
    public static function getPriorityName(int $priority): string
    {
        $names = [
            self::PRIORITY_MONITOR => 'MONITOR',
            self::PRIORITY_SYSTEM  => 'SYSTEM',
            self::PRIORITY_HIGH    => 'HIGH',
            self::PRIORITY_NORMAL  => 'NORMAL',
            self::PRIORITY_LOW     => 'LOW',
            self::PRIORITY_IGNORE  => 'IGNORE',
        ];
        return $names[$priority] ?? 'UNKNOWN';
    }
}