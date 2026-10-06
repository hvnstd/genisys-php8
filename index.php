<?php
/**
 * Genisys PHP 8.x — 模块化服务端入口
 * 
 * ReactOS 式架构重构：
 * 每个模块独立，通过 compat/ 层获取运行时兼容性
 * 模块间仅通过接口通信，不直接 import
 */
declare(strict_types=1);

// 引导器：加载兼容层与全部模块
require_once __DIR__ . '/bootstrap.php';

use Genisys\Compat\RuntimeCompat;
use Genisys\Compat\YamlAdapter;
use Genisys\Module\NetworkModule;
use Genisys\Module\SchedulerModule;
use Genisys\Module\MemoryModule;
use Genisys\Module\PlayerModule;
use Genisys\Module\LevelModule;
use Genisys\Module\EntityModule;
use Genisys\Module\BlockModule;
use Genisys\Module\InventoryModule;
use Genisys\Module\EventModule;
use Genisys\Module\PluginModule;

// PHP 8.x 兼容性检查
RuntimeCompat::assert(PHP_VERSION_ID >= 80000, 'Genisys PHP8 requires PHP 8.0+');

echo "Genisys PHP8 Server starting...\n";
echo "PHP Version: " . PHP_VERSION . "\n";

// 运行配置（环境变量可覆盖：GENISYS_PORT / GENISYS_MOTD / GENISYS_WORLD_DIR）
$serverPort = (int)($_ENV['GENISYS_PORT'] ?? getenv('GENISYS_PORT') ?: 19132);
$serverMotd = $_ENV['GENISYS_MOTD'] ?? getenv('GENISYS_MOTD') ?: 'Genisys PHP8 Server';
$worldDir = $_ENV['GENISYS_WORLD_DIR'] ?? getenv('GENISYS_WORLD_DIR') ?: __DIR__ . '/worlds/World';

// 初始化模块（依赖注入：每个模块只拿到它需要的）
$eventModule = new EventModule();
$pluginModule = new PluginModule();
$memoryModule = new MemoryModule();
$playerModule = new PlayerModule();
$levelModule = new LevelModule('World', $worldDir);
$entityModule = new EntityModule();
$blockModule = new BlockModule();
$inventoryModule = new InventoryModule();

// 网络模块（需要 SchedulerModule 的线程管理器接口）
$threadManager = \Genisys\Compat\SimpleThreadManager::getInstance();
$schedulerModule = new SchedulerModule($threadManager);
$networkModule = new NetworkModule('Network', $serverPort, $serverMotd);

// 玩家会话接线：RakNet 会话 → PlayerSession 状态机
$playerModule->setLevel($levelModule);
$networkModule->setPlayerModule($playerModule);

// 插件与命令系统装配（Batch 6）
$pluginModule->loadPlugins(__DIR__ . '/plugins');
\Genisys\Module\DefaultCommands::register($pluginModule, $playerModule, [
    'name' => 'Genisys PHP8',
    'version' => '1.0.0',
    'protocol' => \Genisys\Module\MinecraftProtocol::CURRENT_PROTOCOL,
    'mcpeVersion' => \Genisys\Module\MinecraftProtocol::MINECRAFT_VERSION,
], function () use ($networkModule): void {
    $networkModule->shutdown();
});
$consoleSender = new \Genisys\Module\ConsoleCommandSender();
$consoleReader = new \Genisys\Module\ConsoleReader();
$networkModule->onTick = function () use ($consoleReader, $consoleSender, $pluginModule): void {
    $consoleReader->poll();
    while (($line = $consoleReader->nextCommand()) !== null) {
        $pluginModule->dispatchCommand($line, $consoleSender);
    }
};

echo "Modules loaded:\n";
echo "  - NetworkModule (RakNet + RCON + Query)\n";
echo "  - SchedulerModule (Fiber async tasks)\n";
echo "  - MemoryModule (WeakRef leak tracking)\n";
echo "  - PlayerModule (auth + chat + inventory)\n";
echo "  - LevelModule (Anvil/McRegion/LevelDB)\n";
echo "  - EntityModule (AI + movement)\n";
echo "  - BlockModule (place/remove/update)\n";
echo "  - InventoryModule (containers + crafting)\n";
echo "  - EventModule (6-priority chain)\n";
echo "  - PluginModule (loader + commands)\n";

echo "\nStarting network listener on port $serverPort...\n";
echo "MCPE MOTD: " . $networkModule->getMotd() . "\n";
echo "Ready for connections.\n";

// 启动网络模块（Fiber 协程）
$networkModule->start();