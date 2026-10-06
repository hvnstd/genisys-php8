<?php
/**
 * Module: 命令与插件基类（Batch 6 收尾）
 *
 * 对照原版 0.14.3：
 * - Event/Listener          ← event/Event.php、event/Listener.php
 * - PluginBase              ← plugin/PluginBase.php（onEnable/onDisable/onCommand）
 * - CommandSender           ← command/CommandSender.php + ConsoleCommandSender.php
 * - DefaultCommands         ← command/defaults/（version/plugins/list/say/stop）
 * - ConsoleReader           ← command/CommandReader.php（pthreads → 非阻塞轮询）
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\YamlAdapter;

/* ==========================================================================
 * 事件基类与监听器（对照 event/Event.php、Listener.php）
 * ========================================================================== */

abstract class Event
{
    /** @var bool 事件是否已取消（可取消事件由处理器置 true / 返回 false） */
    public bool $cancelled = false;

    /** 事件名（注册表键，子类必须定义为 const） */
    public const EVENT_NAME = '';

    abstract public function getName(): string;
}

interface Listener
{
}

/* ==========================================================================
 * 插件基类（对照 plugin/PluginBase.php）
 * ========================================================================== */

abstract class PluginBase
{
    public bool $enabled = false;

    /** 插件所在路径（由 PluginModule 装配时写入） */
    public string $path = '';

    public function __construct(
        private PluginModule $module,
        private PluginInfo $info
    ) {
    }

    public function getName(): string
    {
        return $this->info->name;
    }

    public function getVersion(): string
    {
        return $this->info->version;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getModule(): PluginModule
    {
        return $this->module;
    }

    /**
     * plugin.yml 中 commands 字段的命令桥接到本方法
     * （对照原版 PluginCommand → PluginBase::onCommand）
     */
    public function onCommand(CommandSender $sender, string $command, string $label, array $args): bool
    {
        return false;
    }

    protected function onEnable(): void
    {
    }

    protected function onDisable(): void
    {
    }

    /** 供 PluginModule 生命周期调用（同包可见性控制在外部触发处） */
    public function __bootstrapEnable(): void
    {
        $this->enabled = true;
        $this->onEnable();
    }

    public function __bootstrapDisable(): void
    {
        $this->enabled = false;
        $this->onDisable();
    }
}

/* ==========================================================================
 * 命令发送者（对照 command/CommandSender.php、ConsoleCommandSender.php）
 * ========================================================================== */

interface CommandSender
{
    public function sendMessage(string $message): void;

    public function getName(): string;

    public function hasPermission(string $permission): bool;
}

class ConsoleCommandSender implements CommandSender
{
    public function __construct(private $logger = null)
    {
        $this->logger = $logger ?? fn(string $m) => fwrite(STDOUT, $m . PHP_EOL);
    }

    public function sendMessage(string $message): void
    {
        ($this->logger)($message);
    }

    public function getName(): string
    {
        return 'CONSOLE';
    }

    public function hasPermission(string $permission): bool
    {
        return true; // 控制台拥有全部权限（对照原版）
    }
}

/* ==========================================================================
 * 默认命令（对照 command/defaults/ 的最小集）
 * ========================================================================== */

final class DefaultCommands
{
    /**
     * 注册服务器基础命令
     *
     * @param array $serverInfo {name, version, protocol, mcpeVersion}
     * @param callable|null $stopHandler stop 命令回调
     */
    public static function register(
        PluginModule $pm,
        ?PlayerModule $players,
        array $serverInfo,
        ?callable $stopHandler = null
    ): void {
        $pm->registerCommand('version', [
            'description' => 'Show server version',
            'aliases' => ['ver', 'about'],
            'handler' => function (CommandSender $sender, array $args) use ($serverInfo): bool {
                $sender->sendMessage($serverInfo['name'] . ' ' . $serverInfo['version']
                    . ' (MCPE ' . $serverInfo['mcpeVersion']
                    . ' / protocol ' . $serverInfo['protocol'] . ')');
                return true;
            },
        ]);

        $pm->registerCommand('plugins', [
            'description' => 'List loaded plugins',
            'aliases' => ['pl'],
            'handler' => function (CommandSender $sender, array $args) use ($pm): bool {
                $rows = $pm->getPlugins();
                if ($rows === []) {
                    $sender->sendMessage('Plugins (0): none');
                    return true;
                }
                $names = array_map(
                    fn(array $p) => ($p['enabled'] ? '§a' : '§c') . $p['name'] . ' v' . $p['version'] . '§r',
                    $rows
                );
                $sender->sendMessage('Plugins (' . count($rows) . '): ' . implode(', ', $names));
                return true;
            },
        ]);

        $pm->registerCommand('list', [
            'description' => 'List online players',
            'handler' => function (CommandSender $sender, array $args) use ($players): bool {
                $count = $players !== null ? $players->getOnlineCount() : 0;
                $sender->sendMessage('There are ' . $count . ' players online');
                return true;
            },
        ]);

        $pm->registerCommand('say', [
            'description' => 'Broadcast a message',
            'permission' => 'genisys.command.say',
            'handler' => function (CommandSender $sender, array $args) use ($pm): bool {
                if ($args === []) {
                    $sender->sendMessage('Usage: /say <message>');
                    return false;
                }
                $pm->broadcastMessage('Server', implode(' ', $args));
                return true;
            },
        ]);

        if ($stopHandler !== null) {
            $pm->registerCommand('stop', [
                'description' => 'Stop the server',
                'handler' => function (CommandSender $sender, array $args) use ($stopHandler): bool {
                    $sender->sendMessage('Stopping the server...');
                    $stopHandler();
                    return true;
                },
            ]);
        }
    }
}

/* ==========================================================================
 * 控制台读取（对照 command/CommandReader.php：pthreads → stream_select 轮询）
 * ========================================================================== */

final class ConsoleReader
{
    /** @var resource|null */
    private $stream;
    /** @var string[] 已读取待处理的命令行 */
    private array $queue = [];

    /**
     * @param resource|null $stream 可注入的输入流（默认 STDIN；测试用 tmpfile）
     */
    public function __construct($stream = null)
    {
        if ($stream === null) {
            $stream = @fopen('php://stdin', 'rb');
            if ($stream !== false && defined('STDIN')) {
                // 正常服务器场景：注册到 stream_select
            }
        }
        $this->stream = $stream;
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, false);
        }
    }

    /**
     * 非阻塞轮询一次输入流，缓冲可用行。返回本调用读到的行数。
     */
    public function poll(): int
    {
        if (!is_resource($this->stream)) {
            return 0;
        }
        $read = [$this->stream];
        $write = null;
        $except = null;
        while (@stream_select($read, $write, $except, 0, 0) === 1) {
            $line = fgets($this->stream);
            if ($line === false) {
                return 0; // EOF
            }
            $line = trim($line);
            if ($line !== '') {
                $this->queue[] = $line;
            }
            $read = [$this->stream];
        }
        return 0;
    }

    /**
     * 取出一条已缓冲的命令行；无则返回 null
     */
    public function nextCommand(): ?string
    {
        return array_shift($this->queue);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }
}

/**
 * 命令模块入口
 */
final class CommandModule
{
    public const VERSION = '1.0.0';
    public function getName(): string { return 'CommandModule'; }
    public function getVersion(): string { return self::VERSION; }
}
