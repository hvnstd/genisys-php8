<?php
/** Batch 6 测试夹具插件（真实 PluginBase 主类 + 注解监听器） */

use Genisys\Module\CommandSender;
use Genisys\Module\Event;
use Genisys\Module\Listener;
use Genisys\Module\PluginBase;

class TestPlugin extends PluginBase
{
    public static bool $enabledFlag = false;
    public static array $commandLog = [];

    protected function onEnable(): void
    {
        self::$enabledFlag = true;
        $this->getModule()->registerEvents(new TestListener(), $this);
    }

    protected function onDisable(): void
    {
        self::$enabledFlag = false;
    }

    public function onCommand(CommandSender $sender, string $command, string $label, array $args): bool
    {
        self::$commandLog[] = [$command, $args];
        $sender->sendMessage('Hello ' . ($args[0] ?? 'world'));
        return true;
    }
}

class TestEvent extends Event
{
    public const EVENT_NAME = 'test.custom';

    public function __construct(public string $payload = '')
    {
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }
}

class TestListener implements Listener
{
    public static array $log = [];

    /** @priority MONITOR */
    public function onCanceller(TestEvent $e): bool
    {
        // 仅当负载为 cancel 时取消事件（验证 @ignoreCancelled 语义）
        return $e->payload === 'cancel' ? false : true;
    }

    /** @priority HIGH */
    public function onHigh(TestEvent $e): void
    {
        self::$log[] = 'high:' . $e->payload;
    }

    /** @ignoreCancelled true */
    public function onAware(TestEvent $e): void
    {
        self::$log[] = 'aware';
    }

    /** @priority LOW */
    public function onPlain(TestEvent $e): void
    {
        self::$log[] = 'plain';
    }
}
