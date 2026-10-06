<?php
/**
 * Module: PluginModule
 * 职责：插件加载/卸载/事件管理（替代 PluginManager）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：SplObjectStorage + \WeakRef
 *
 * 升级内容：
 *   - 三种插件加载器：Folder / Phar / Script
 *   - plugin.yml 元数据解析（name, version, main, api, commands, events）
 *   - 插件 enable/disable 生命周期
 *   - 命令注册和执行系统
 *   - 基于 SplObjectStorage 的插件管理 + \WeakRef 弱引用
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\YamlAdapter;

/* ==========================================================================
 * PluginInfo — 插件元数据容器
 * ========================================================================== */
class PluginInfo
{
    /** 清洗后的插件名（readonly，仅构造时赋值一次） */
    public readonly string $name;

    public function __construct(
        string $name,
        public string $version = '1.0.0',
        public ?string $main = null,
        public string $path = '',
        public bool $enabled = false,
        public array $metadata = [],
        public array $commands = [],
        public array $events = [],
        public string $api = '1.0.0',
        public array $depend = [],
        public array $softDepend = [],
        public ?string $prefix = null,
        public ?string $description = null,
        public array $authors = [],
        public ?string $website = null,
    ) {
        $clean = preg_replace('/[^A-Za-z0-9 _.-]/', '', $name);
        $this->name = str_replace(' ', '_', (string)$clean);
    }

    public function getFullName(): string
    {
        return $this->name . ' v' . $this->version;
    }
}

/* ==========================================================================
 * PluginLoaderInterface — 插件加载器接口
 * ========================================================================== */
interface PluginLoaderInterface
{
    /**
     * 加载插件
     */
    public function loadPlugin(string $path): ?object;

    /**
     * 从路径获取插件描述
     */
    public function getPluginDescription(string $path): ?PluginInfo;

    /**
     * 返回此加载器接受的文件名模式（正则表达式数组）
     *
     * @return string[]
     */
    public function getPluginFilters(): array;

    /**
     * 启用插件
     */
    public function enablePlugin(object $plugin): void;

    /**
     * 禁用插件
     */
    public function disablePlugin(object $plugin): void;
}

/* ==========================================================================
 * FolderPluginLoader — 文件夹加载器
 * ========================================================================== */
class FolderPluginLoader implements PluginLoaderInterface
{
    /**
     * 加载文件夹插件
     */
    public function loadPlugin(string $path): ?object
    {
        if (!is_dir($path) || !file_exists($path . '/plugin.yml')) {
            return null;
        }

        $info = $this->getPluginDescription($path);
        if ($info === null) {
            return null;
        }

        $plugin = $this->createPluginObject($info, $path);
        return $plugin;
    }

    /**
     * 从 plugin.yml 获取插件描述
     */
    public function getPluginDescription(string $path): ?PluginInfo
    {
        $yamlFile = $path . '/plugin.yml';
        if (!file_exists($yamlFile)) {
            return null;
        }
        $content = @file_get_contents($yamlFile);
        if ($content === false || trim($content) === '') {
            return null;
        }
        return self::parseFromYaml($content);
    }

    public function getPluginFilters(): array
    {
        return ['/[^.]/'];
    }

    public function enablePlugin(object $plugin): void
    {
        if (isset($plugin->enabled)) {
            $plugin->enabled = true;
        }
    }

    public function disablePlugin(object $plugin): void
    {
        if (isset($plugin->enabled)) {
            $plugin->enabled = false;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  YAML 解析                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * 从 YAML 字符串解析 PluginInfo
     */
    public static function parseFromYaml(string $yaml): ?PluginInfo
    {
        try {
            $data = YamlAdapter::getInstance()->parse($yaml);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($data) || !isset($data['name'])) {
            return null;
        }

        $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', (string) $data['name']);
        $name = str_replace(' ', '_', $name);
        if ($name === '') {
            return null;
        }

        $api = $data['api'] ?? '1.0.0';
        if (is_array($api)) {
            $api = $api[0] ?? '1.0.0';
        }

        $authors = [];
        if (isset($data['author'])) {
            $authors[] = (string) $data['author'];
        }
        if (isset($data['authors']) && is_array($data['authors'])) {
            foreach ($data['authors'] as $author) {
                $authors[] = (string) $author;
            }
        }

        return new PluginInfo(
            name: $name,
            version: (string) ($data['version'] ?? '1.0.0'),
            main: isset($data['main']) ? (string) $data['main'] : null,
            path: '',
            enabled: false,
            metadata: isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [],
            commands: isset($data['commands']) && is_array($data['commands']) ? $data['commands'] : [],
            events: isset($data['events']) && is_array($data['events']) ? $data['events'] : [],
            api: (string) $api,
            depend: isset($data['depend']) ? (array) $data['depend'] : [],
            softDepend: isset($data['softdepend']) ? (array) $data['softdepend'] : [],
            prefix: isset($data['prefix']) ? (string) $data['prefix'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            authors: $authors,
            website: isset($data['website']) ? (string) $data['website'] : null,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  辅助                                                               */
    /* ------------------------------------------------------------------ */

    private function createPluginObject(PluginInfo $info, string $path): object
    {
        // 加载器只负责信息载体；主类实例化统一由 PluginModule::materializeMainClass 处理
        $plugin = new \stdClass();
        $plugin->name = $info->name;
        $plugin->version = $info->version;
        $plugin->main = $info->main;
        $plugin->path = $path;
        $plugin->enabled = false;
        $plugin->metadata = $info->metadata;
        $plugin->pluginInfo = $info;
        return $plugin;
    }
}

/* ==========================================================================
 * PharPluginLoader — 归档加载器
 * ========================================================================== */
class PharPluginLoader implements PluginLoaderInterface
{
    /**
     * 加载 Phar 插件
     */
    public function loadPlugin(string $path): ?object
    {
        if (!file_exists($path)) {
            return null;
        }

        $info = $this->getPluginDescription($path);
        if ($info === null) {
            return null;
        }

        $plugin = new \stdClass();
        $plugin->name = $info->name;
        $plugin->version = $info->version;
        $plugin->main = $info->main;
        $plugin->path = $path;
        $plugin->enabled = false;
        $plugin->metadata = $info->metadata;
        $plugin->pluginInfo = $info;
        $plugin->isPhar = true;
        return $plugin;
    }

    /**
     * 从 Phar 归档获取插件描述
     */
    public function getPluginDescription(string $path): ?PluginInfo
    {
        try {
            $phar = new \Phar($path);
            if (!isset($phar['plugin.yml'])) {
                return null;
            }
            $entry = $phar['plugin.yml'];
            if (!$entry instanceof \PharFileInfo) {
                return null;
            }
            return FolderPluginLoader::parseFromYaml($entry->getContent());
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getPluginFilters(): array
    {
        return ['/\.phar$/i'];
    }

    public function enablePlugin(object $plugin): void
    {
        if (isset($plugin->enabled)) {
            $plugin->enabled = true;
        }
    }

    public function disablePlugin(object $plugin): void
    {
        if (isset($plugin->enabled)) {
            $plugin->enabled = false;
        }
    }
}

/* ==========================================================================
 * ScriptPluginLoader — 单文件脚本加载器
 * ========================================================================== */
class ScriptPluginLoader implements PluginLoaderInterface
{
    /**
     * 加载单文件脚本插件
     */
    public function loadPlugin(string $path): ?object
    {
        if (!file_exists($path)) {
            return null;
        }

        $info = $this->getPluginDescription($path);
        if ($info === null) {
            return null;
        }

        $plugin = new \stdClass();
        $plugin->name = $info->name;
        $plugin->version = $info->version;
        $plugin->main = $info->main;
        $plugin->path = $path;
        $plugin->enabled = false;
        $plugin->metadata = $info->metadata;
        $plugin->pluginInfo = $info;
        $plugin->isScript = true;
        return $plugin;
    }

    /**
     * 从 PHP 文件的 docblock 注释获取插件描述
     */
    public function getPluginDescription(string $path): ?PluginInfo
    {
        $content = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($content === false) {
            return null;
        }

        $data = [];
        $insideHeader = false;
        foreach ($content as $line) {
            if (!$insideHeader && strpos($line, '/**') !== false) {
                $insideHeader = true;
                continue;
            }

            if (preg_match('/^[ \t]+\*[ \t]+@([a-zA-Z]+)([ \t]+(.*))?$/', $line, $matches) > 0) {
                $key = $matches[1];
                $value = isset($matches[3]) ? trim($matches[3]) : '';
                if ($key === 'notscript') {
                    return null;
                }
                $data[$key] = $value;
            }

            if ($insideHeader && strpos($line, '**/') !== false) {
                break;
            }
        }

        if (!$insideHeader || !isset($data['name'])) {
            return null;
        }

        $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', $data['name']);
        $name = str_replace(' ', '_', $name);
        if ($name === '') {
            return null;
        }

        $authors = [];
        if (isset($data['author'])) {
            $authors[] = $data['author'];
        }

        return new PluginInfo(
            name: $name,
            version: $data['version'] ?? '1.0.0',
            main: $data['main'] ?? null,
            path: $path,
            enabled: false,
            metadata: [],
            commands: [],
            events: [],
            api: $data['api'] ?? '1.0.0',
            depend: isset($data['depend']) ? array_map('trim', explode(',', $data['depend'])) : [],
            softDepend: isset($data['softdepend']) ? array_map('trim', explode(',', $data['softdepend'])) : [],
            prefix: $data['prefix'] ?? null,
            description: $data['description'] ?? null,
            authors: $authors,
            website: $data['website'] ?? null,
        );
    }

    public function getPluginFilters(): array
    {
        return ['/\.php$/i'];
    }

    public function enablePlugin(object $plugin): void
    {
        if (isset($plugin->enabled)) {
            $plugin->enabled = true;
        }
    }

    public function disablePlugin(object $plugin): void
    {
        if (isset($plugin->enabled)) {
            $plugin->enabled = false;
        }
    }
}

/* ==========================================================================
 * CommandInfo — 命令元数据
 * ========================================================================== */
class CommandInfo
{
    public function __construct(
        public readonly string $name,
        public ?string $description = null,
        public ?string $usage = null,
        public array $aliases = [],
        public ?string $permission = null,
        public ?string $permissionMessage = null,
        public $handler = null,
        public ?object $plugin = null,
    ) {
    }
}

/* ==========================================================================
 * EventHandlerEntry — 事件处理器条目（含 \WeakRef 插件引用）
 * ========================================================================== */
class EventHandlerEntry
{
    /** \WeakReference（PHP 7.4+ 内置；原 \WeakRef 类在 PHP 8 已不存在） */
    private ?\WeakReference $pluginRef;

    public function __construct(
        private $handler,
        private readonly int $priority,
        private readonly string $eventName,
        ?object $plugin = null,
    ) {
        $this->pluginRef = $plugin !== null ? \WeakReference::create($plugin) : null;
    }

    /**
     * 检查插件引用是否仍有效
     */
    public function isPluginValid(): bool
    {
        if ($this->pluginRef === null) {
            return true; // 无插件绑定的处理器始终有效
        }
        return $this->pluginRef->get() !== null;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getPluginRef(): ?\WeakReference
    {
        return $this->pluginRef;
    }
}

/* ==========================================================================
 * PluginModule — 插件管理模块主类
 *
 * 使用 SplObjectStorage 管理插件对象：
 *   - 插件对象作为 SplObjectStorage 的键
 *   - PluginInfo 作为关联数据
 *   - 通过 \WeakRef 在事件处理器中引用插件，避免阻止 GC
 * ========================================================================== */
class PluginModule
{
    /**
     * SplObjectStorage — 插件对象 => PluginInfo
     *
     * @var \SplObjectStorage
     */
    private \SplObjectStorage $pluginStorage;

    /**
     * 插件名 => 插件对象（快速查找）
     *
     * @var array<string, object>
     */
    private array $pluginNameMap = [];

    /**
     * 事件处理器列表
     *
     * @var array<string, list<EventHandlerEntry>>
     */
    private array $eventHandlers = [];

    /**
     * 命令注册表
     *
     * @var array<string, CommandInfo>
     */
    private array $commands = [];

    /**
     * 命令别名索引：别名 => 主命令名
     *
     * @var array<string, string>
     */
    private array $commandAliases = [];

    /**
     * 已注册的加载器
     *
     * @var array<string, PluginLoaderInterface>
     */
    private array $loaders = [];

    public function __construct()
    {
        $this->pluginStorage = new \SplObjectStorage();
        $this->registerBuiltInLoaders();
    }

    /* ==========================================================================
     * 加载器注册
     * ========================================================================== */

    /**
     * 注册内置的三种加载器
     */
    private function registerBuiltInLoaders(): void
    {
        $this->loaders['folder'] = new FolderPluginLoader();
        $this->loaders['phar']   = new PharPluginLoader();
        $this->loaders['script'] = new ScriptPluginLoader();
    }

    /**
     * 注册自定义加载器
     */
    public function registerLoader(string $name, PluginLoaderInterface $loader): void
    {
        $this->loaders[$name] = $loader;
    }

    /**
     * 根据路径匹配合适的加载器
     */
    private function findLoader(string $path): ?PluginLoaderInterface
    {
        $basename = basename($path);
        foreach ($this->loaders as $loader) {
            foreach ($loader->getPluginFilters() as $filter) {
                if (preg_match($filter, $basename) > 0) {
                    return $loader;
                }
            }
        }
        return null;
    }

    /* ==========================================================================
     * 插件加载 / 解析
     * ========================================================================== */

    /**
     * 加载插件（Folder / Phar / Script 三种加载器）
     *
     * @return bool 成功时 true，失败时 false
     */
    public function loadPlugin(string $path): bool
    {
        $info = $this->parsePluginYaml($path);
        if ($info === null) {
            return false;
        }

        // 检查名称冲突
        if (isset($this->pluginNameMap[$info->name])) {
            error_log("PluginModule: Plugin '{$info->name}' already loaded, skipping.");
            return false;
        }

        $loader = $this->findLoader($path);
        if ($loader === null) {
            error_log("PluginModule: No loader found for path: {$path}");
            return false;
        }

        $plugin = $loader->loadPlugin($path);
        if ($plugin === null) {
            return false;
        }

        // 主类实例化（对照原版 PluginManager：new $main(...)），失败则保留信息载体
        $plugin = $this->materializeMainClass($plugin, $path);

        // 使用 SplObjectStorage 管理插件
        $info->path = $path;
        $this->pluginStorage->attach($plugin, $info);
        $this->pluginNameMap[$info->name] = $plugin;

        // 注册事件监听
        foreach ($info->events as $eventName => $handler) {
            $this->registerEventFromYaml($eventName, $handler, $plugin);
        }

        // 注册命令
        foreach ($info->commands as $cmdName => $cmdInfo) {
            $this->registerCommandFromYaml($cmdName, $cmdInfo, $plugin);
        }

        return true;
    }

    /**
     * 把加载器的信息载体升级为 PluginBase 主类实例
     * （main 类文件约定：$path/$main.php 或 $path/src/$main.php）
     */
    private function materializeMainClass(object $carrier, string $path): object
    {
        $main = $carrier->main ?? null;
        if (!is_string($main) || $main === '') {
            return $carrier;
        }
        if (!class_exists($main)) {
            $candidates = [
                $path . '/' . str_replace('\\', '/', $main) . '.php',
                $path . '/src/' . str_replace('\\', '/', $main) . '.php',
            ];
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    require_once $candidate;
                    break;
                }
            }
        }
        $info = $carrier->pluginInfo ?? null;
        if (class_exists($main) && is_subclass_of($main, PluginBase::class) && $info !== null) {
            $plugin = new $main($this, $info);
            $plugin->path = $path;
            return $plugin;
        }
        return $carrier;
    }

    /**
     * 是否为 PluginBase 主类实例
     */
    private function isPluginBase(object $plugin): bool
    {
        return $plugin instanceof PluginBase;
    }

    /**
     * 从目录加载所有插件
     *
     * @return string[] 成功加载的插件名称列表
     */
    public function loadPlugins(string $directory): array
    {
        $loaded = [];
        if (!is_dir($directory)) {
            return $loaded;
        }

        // 顶层条目逐一尝试（目录插件 / .phar 归档 / 脚本），统一走 loadPlugin 入口
        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = rtrim($directory, '/') . '/' . $entry;
            if ($this->loadPlugin($path)) {
                $info = $this->parsePluginYaml($path);
                if ($info !== null) {
                    $loaded[] = $info->name;
                }
            }
        }

        // 对照原版：加载完成后统一启用（onEnable 钩子 + plugin.yml 命令注册）
        foreach ($loaded as $name) {
            $this->enablePlugin($name);
        }

        return $loaded;
    }

    /**
     * 解析 plugin.yml 元数据（支持 Folder / Phar / Script 三种格式）
     *
     * @return PluginInfo|null
     */
    public function parsePluginYaml(string $path): ?PluginInfo
    {
        // 1) Folder 模式 — 目录下的 plugin.yml
        $yamlFile = $path . '/plugin.yml';
        if (is_dir($path) && file_exists($yamlFile)) {
            $content = @file_get_contents($yamlFile);
            if ($content !== false && trim($content) !== '') {
                return FolderPluginLoader::parseFromYaml($content);
            }
        }

        // 2) Phar 模式 — 归档内的 plugin.yml
        if (file_exists($path)) {
            try {
                $phar = new \Phar($path);
                if (isset($phar['plugin.yml'])) {
                    $entry = $phar['plugin.yml'];
                    if ($entry instanceof \PharFileInfo) {
                        return FolderPluginLoader::parseFromYaml($entry->getContent());
                    }
                }
            } catch (\Throwable $e) {
                // 不是有效的 Phar，继续尝试 Script 模式
            }

            // 3) Script 模式 — PHP 文件 docblock
            $content = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($content !== false) {
                $info = $this->parseScriptHeader($content);
                if ($info !== null) {
                    return $info;
                }
            }
        }

        return null;
    }

    /**
     * 从 PHP 文件的 docblock 头解析 Script 插件信息
     *
     * @param string[] $lines
     */
    private function parseScriptHeader(array $lines): ?PluginInfo
    {
        $data = [];
        $insideHeader = false;
        foreach ($lines as $line) {
            if (!$insideHeader && strpos($line, '/**') !== false) {
                $insideHeader = true;
                continue;
            }
            if (preg_match('/^[ \t]+\*[ \t]+@([a-zA-Z]+)([ \t]+(.*))?$/', $line, $matches) > 0) {
                $key = $matches[1];
                $value = isset($matches[3]) ? trim($matches[3]) : '';
                if ($key === 'notscript') {
                    return null;
                }
                $data[$key] = $value;
            }
            if ($insideHeader && strpos($line, '**/') !== false) {
                break;
            }
        }

        if (!$insideHeader || !isset($data['name'])) {
            return null;
        }

        $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', $data['name']);
        $name = str_replace(' ', '_', $name);
        if ($name === '') {
            return null;
        }

        $authors = [];
        if (isset($data['author'])) {
            $authors[] = $data['author'];
        }

        return new PluginInfo(
            name: $name,
            version: $data['version'] ?? '1.0.0',
            main: $data['main'] ?? null,
            path: '',
            enabled: false,
            metadata: [],
            commands: [],
            events: [],
            api: $data['api'] ?? '1.0.0',
            depend: isset($data['depend']) ? array_map('trim', explode(',', $data['depend'])) : [],
            softDepend: isset($data['softdepend']) ? array_map('trim', explode(',', $data['softdepend'])) : [],
            prefix: $data['prefix'] ?? null,
            description: $data['description'] ?? null,
            authors: $authors,
            website: $data['website'] ?? null,
        );
    }

    /* ==========================================================================
     * 插件生命周期管理
     * ========================================================================== */

    /**
     * 启用插件
     */
    public function enablePlugin(string $name): bool
    {
        if (!isset($this->pluginNameMap[$name])) {
            return false;
        }
        $plugin = $this->pluginNameMap[$name];

        // 生命周期钩子（对照原版 PluginManager::enablePlugin → onEnable）
        if ($this->isPluginBase($plugin)) {
            $plugin->__bootstrapEnable();
        } else {
            $plugin->enabled = true;
        }

        // 注册 plugin.yml 中声明的命令（桥接到 onCommand，对照原版 PluginCommand）
        if ($this->isPluginBase($plugin)) {
            $info = $this->pluginStorage[$plugin] ?? null;
            if ($info !== null) {
                foreach ($info->commands as $cmdName => $cmdConf) {
                    if (!is_array($cmdConf)) {
                        $cmdConf = [];
                    }
                    $this->registerCommand((string)$cmdName, $cmdConf, $plugin);
                }
            }
        }

        // 通知加载器
        $loader = $this->findLoader($plugin->path ?? '');
        if ($loader !== null) {
            $loader->enablePlugin($plugin);
        }

        return true;
    }

    /**
     * 禁用插件
     */
    public function disablePlugin(string $name): bool
    {
        if (!isset($this->pluginNameMap[$name])) {
            return false;
        }
        $plugin = $this->pluginNameMap[$name];

        // 生命周期钩子（对照原版 PluginManager::disablePlugin → onDisable）
        if ($this->isPluginBase($plugin)) {
            $plugin->__bootstrapDisable();
        } else {
            $plugin->enabled = false;
        }

        // 注销该插件的事件处理器与命令（对照原版 HandlerList::unregisterAll）
        $this->unregisterPluginEvents($plugin);
        $this->cleanupPluginCommands($plugin);

        // 通知加载器
        $loader = $this->findLoader($plugin->path ?? '');
        if ($loader !== null) {
            $loader->disablePlugin($plugin);
        }

        return true;
    }

    /**
     * 注销指定插件的事件处理器
     */
    private function unregisterPluginEvents(object $plugin): void
    {
        foreach ($this->eventHandlers as $eventName => $entries) {
            $kept = [];
            foreach ($entries as $entry) {
                $handlerPlugin = $entry->getPluginRef();
                if ($handlerPlugin !== null && $handlerPlugin->get() === $plugin) {
                    continue;
                }
                $kept[] = $entry;
            }
            if ($kept === []) {
                unset($this->eventHandlers[$eventName]);
            } else {
                $this->eventHandlers[$eventName] = $kept;
            }
        }
    }

    /**
     * 卸载插件（从 SplObjectStorage 中移除）
     */
    public function unloadPlugin(string $name): bool
    {
        if (!isset($this->pluginNameMap[$name])) {
            return false;
        }

        $plugin = $this->pluginNameMap[$name];

        // 先禁用
        $plugin->enabled = false;

        // 从 SplObjectStorage 中分离（弱引用自动失效）
        $this->pluginStorage->detach($plugin);
        unset($this->pluginNameMap[$name]);

        // 清理该插件的命令
        $this->cleanupPluginCommands($plugin);

        return true;
    }

    /**
     * 清理指定插件的命令
     */
    private function cleanupPluginCommands(object $plugin): void
    {
        foreach ($this->commands as $cmdName => $cmdInfo) {
            if ($cmdInfo->plugin === $plugin) {
                foreach ($cmdInfo->aliases as $alias) {
                    unset($this->commandAliases[(string)$alias]);
                }
                unset($this->commands[$cmdName]);
            }
        }
    }

    /**
     * 清理失效的事件处理器（插件已被 GC）
     */
    public function cleanupStaleHandlers(): int
    {
        $cleaned = 0;
        foreach ($this->eventHandlers as $eventName => $entries) {
            $valid = [];
            foreach ($entries as $entry) {
                if ($entry->isPluginValid()) {
                    $valid[] = $entry;
                } else {
                    $cleaned++;
                }
            }
            if (empty($valid)) {
                unset($this->eventHandlers[$eventName]);
            } else {
                $this->eventHandlers[$eventName] = $valid;
            }
        }
        return $cleaned;
    }

    /* ==========================================================================
     * 插件查询
     * ========================================================================== */

    /**
     * 获取已加载插件列表
     *
     * @return array<array{name:string, version:string, enabled:bool, path:string, main:?string, metadata:array}>
     */
    public function getPlugins(): array
    {
        $result = [];
        foreach ($this->pluginStorage as $plugin) {
            $info = $this->pluginStorage[$plugin];
            $result[] = [
                'name'      => $plugin->name ?? $info->name,
                'version'   => $plugin->version ?? $info->version,
                'enabled'   => $plugin->enabled ?? false,
                'path'      => $plugin->path ?? $info->path,
                'main'      => $plugin->main ?? $info->main,
                'metadata'  => $plugin->metadata ?? $info->metadata,
            ];
        }
        return $result;
    }

    /**
     * 获取插件数量
     */
    public function getPluginCount(): int
    {
        return $this->pluginStorage->count();
    }

    /**
     * 检查插件是否已加载
     */
    public function hasPlugin(string $name): bool
    {
        return isset($this->pluginNameMap[$name]);
    }

    /**
     * 获取插件对象
     */
    public function getPlugin(string $name): ?object
    {
        return $this->pluginNameMap[$name] ?? null;
    }

    /**
     * 获取插件元信息
     */
    public function getPluginInfo(string $name): ?PluginInfo
    {
        $plugin = $this->pluginNameMap[$name] ?? null;
        if ($plugin !== null) {
            return $this->pluginStorage[$plugin];
        }
        return null;
    }

    /**
     * 检查插件是否启用
     */
    public function isPluginEnabled(string $name): bool
    {
        if (!isset($this->pluginNameMap[$name])) {
            return false;
        }
        return $this->pluginNameMap[$name]->enabled === true;
    }

    /* ==========================================================================
     * 事件系统
     * ========================================================================== */

    /**
     * 注册事件处理器
     *
     * @param callable $handler 事件处理函数
     * @param int      $priority 优先级（越高越先执行）
     * @param object|null $plugin 绑定的插件对象（用于 \WeakRef 弱引用）
     */
    public function registerEvent(string $eventName, $handler, int $priority = 0, ?object $plugin = null): void
    {
        $this->eventHandlers[$eventName][] = new EventHandlerEntry(
            handler: $handler,
            priority: $priority,
            eventName: $eventName,
            plugin: $plugin,
        );
    }

    /**
     * 从 plugin.yml 的 events 字段注册事件
     *
     * 支持两种格式：
     *   events:
     *     PlayerJoin: onJoin           # 简单格式：事件名 => 处理器方法名
     *     PlayerJoin:                  # 复杂格式
     *       handler: onJoin
     *       priority: HIGH
     */
    private function registerEventFromYaml(string $eventName, mixed $handler, object $plugin): void
    {
        if (is_callable($handler)) {
            $this->registerEvent($eventName, $handler, 0, $plugin);
            return;
        }

        if (is_string($handler)) {
            // 简单格式：事件名 => 方法名
            $callable = function (array $data) use ($plugin, $handler) {
                if (method_exists($plugin, $handler)) {
                    return $plugin->$handler($data);
                }
                return null;
            };
            $this->registerEvent($eventName, $callable, 0, $plugin);
            return;
        }

        if (is_array($handler)) {
            $handlerName = $handler['handler'] ?? null;
            $priority = $this->parsePriority($handler['priority'] ?? 'NORMAL');
            if (is_string($handlerName) && method_exists($plugin, $handlerName)) {
                $callable = function (array $data) use ($plugin, $handlerName) {
                    return $plugin->$handlerName($data);
                };
                $this->registerEvent($eventName, $callable, $priority, $plugin);
            }
        }
    }

    /**
     * 解析优先级字符串
     */
    private function parsePriority(string $priority): int
    {
        return match (strtoupper($priority)) {
            'LOWEST'    => -10,
            'LOW'       => -5,
            'NORMAL'    => 0,
            'HIGH'      => 5,
            'HIGHEST'   => 10,
            default     => 0,
        };
    }

    /**
     * 触发事件（按优先级排序后依次调用）
     *
     * @param array $data 事件数据
     */
    public function callEvent(string $eventName, array $data = []): void
    {
        if (!isset($this->eventHandlers[$eventName])) {
            return;
        }

        $handlers = $this->eventHandlers[$eventName];

        // 按优先级降序排序
        usort($handlers, function (EventHandlerEntry $a, EventHandlerEntry $b): int {
            return $b->getPriority() <=> $a->getPriority();
        });

        foreach ($handlers as $entry) {
            // 跳过已失效的插件引用
            if (!$entry->isPluginValid()) {
                continue;
            }

            try {
                call_user_func($entry->getHandler(), $data);
            } catch (\Throwable $e) {
                error_log("Event handler error [$eventName]: " . $e->getMessage());
            }
        }
    }

    /**
     * 获取事件处理器数量
     */
    public function getEventHandlerCount(string $eventName): int
    {
        if (!isset($this->eventHandlers[$eventName])) {
            return 0;
        }
        return count($this->eventHandlers[$eventName]);
    }

    /**
     * 获取所有事件名称
     *
     * @return string[]
     */
    public function getRegisteredEvents(): array
    {
        return array_keys($this->eventHandlers);
    }

    /* ==========================================================================
     * 注解式事件自动注册（对照原版 PluginManager::registerEvents）
     * ========================================================================== */

    /**
     * 扫描 Listener 的 public 方法自动注册事件处理器：
     * - 方法只有一个参数且类型为 Event 子类
     * - @priority <NAME> 注解指定优先级（数值越小越先，见 EventModule 6 级约定）
     * - @ignoreCancelled true 注解：事件已取消时跳过
     */
    public function registerEvents(object $listener, object $plugin): int
    {
        if ($this->isPluginBase($plugin) && !$plugin->isEnabled()) {
            throw new \LogicException('Plugin attempted to register ' . get_class($listener) . ' while not enabled');
        }

        $registered = 0;
        $reflection = new \ReflectionClass($listener);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            $parameters = $method->getParameters();
            if (count($parameters) !== 1) {
                continue;
            }
            $type = $parameters[0]->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $eventClass = $type->getName();
            if (!is_subclass_of($eventClass, Event::class)) {
                continue;
            }
            $eventName = (new \ReflectionClass($eventClass))->getConstant('EVENT_NAME');
            if ($eventName === false || $eventName === '') {
                continue;
            }

            // 注解解析（对照原版正则）
            $doc = (string)$method->getDocComment();
            $priority = 3; // NORMAL
            if (preg_match('/@priority[\t ]{1,}([a-zA-Z]{1,})/m', $doc, $m) > 0) {
                $map = ['MONITOR' => 0, 'SYSTEM' => 1, 'HIGH' => 2, 'NORMAL' => 3, 'LOW' => 4, 'IGNORE' => 5];
                $priority = $map[strtoupper($m[1])] ?? 3;
            }
            $ignoreCancelled = false;
            if (preg_match('/@ignoreCancelled[\t ]{1,}([a-zA-Z]{1,})/m', $doc, $m) > 0) {
                $ignoreCancelled = strtolower($m[1]) === 'true';
            }

            // 闭包强持有监听器与方法（注册期间监听器必须存活，对照原版 HandlerList 强引用）
            $handler = function (Event $event) use ($method, $listener) {
                return $method->invoke($listener, $event);
            };

            $this->eventHandlers[$eventName][] = new EventHandlerEntry(
                handler: $handler,
                priority: $priority,
                eventName: $eventName,
                plugin: $plugin,
            );
            // 记录 ignoreCancelled 标志（与 handler 一一对应，用 spl id 索引）
            $this->ignoreCancelledFlags[spl_object_id($this->eventHandlers[$eventName][array_key_last($this->eventHandlers[$eventName])])] = $ignoreCancelled;
            $registered++;
        }
        return $registered;
    }

    /**
     * 派发事件对象：按优先级调用处理器；处理器返回 false 或 @ignoreCancelled 语义生效时置 cancelled
     *
     * @return bool 事件是否被取消
     */
    public function callEventObject(Event $event): bool
    {
        $eventName = $event->getName();
        if (!isset($this->eventHandlers[$eventName])) {
            return false;
        }

        $handlers = $this->eventHandlers[$eventName];
        usort($handlers, fn(EventHandlerEntry $a, EventHandlerEntry $b) => $a->getPriority() <=> $b->getPriority());

        foreach ($handlers as $entry) {
            if (!$entry->isPluginValid()) {
                continue;
            }
            if ($event->cancelled && ($this->ignoreCancelledFlags[spl_object_id($entry)] ?? false)) {
                continue;
            }
            try {
                if (call_user_func($entry->getHandler(), $event) === false) {
                    $event->cancelled = true;
                }
            } catch (\Throwable $e) {
                error_log("Event handler error [$eventName]: " . $e->getMessage());
            }
        }
        return $event->cancelled;
    }

    /** @var array<int, bool> EventHandlerEntry spl id => ignoreCancelled */
    private array $ignoreCancelledFlags = [];

    /* ==========================================================================
     * 命令系统
     * ========================================================================== */

    /**
     * 注册命令
     *
     * @param array $info 命令信息（description, usage, aliases, permission, permission-message, handler）
     * @param object|null $plugin 绑定的插件对象
     */
    public function registerCommand(string $name, array $info, ?object $plugin = null): void
    {
        $handler = $info['handler'] ?? null;
        if ($handler === null) {
            return;
        }

        $this->commands[$name] = new CommandInfo(
            name: $name,
            description: $info['description'] ?? null,
            usage: $info['usage'] ?? null,
            aliases: $info['aliases'] ?? [],
            permission: $info['permission'] ?? null,
            permissionMessage: $info['permission-message'] ?? null,
            handler: $handler,
            plugin: $plugin,
        );

        // 别名索引
        foreach ($info['aliases'] ?? [] as $alias) {
            $this->commandAliases[(string)$alias] = $name;
        }
    }

    /**
     * 从 plugin.yml 的 commands 字段注册命令
     */
    private function registerCommandFromYaml(string $cmdName, array $cmdInfo, object $plugin): void
    {
        $handler = $cmdInfo['handler'] ?? null;
        if ($handler === null) {
            // plugin.yml 命令桥接到 PluginBase::onCommand（对照原版 PluginCommand）
            if ($this->isPluginBase($plugin)) {
                $label = $cmdName;
                $handler = function (CommandSender $sender, array $args) use ($plugin, $label): bool {
                    return $plugin->onCommand($sender, $label, $label, $args);
                };
            } else {
                $handler = function (CommandSender $sender, array $args): bool {
                    return true;
                };
            }
        }

        $this->commands[$cmdName] = new CommandInfo(
            name: $cmdName,
            description: $cmdInfo['description'] ?? null,
            usage: $cmdInfo['usage'] ?? null,
            aliases: $cmdInfo['aliases'] ?? [],
            permission: $cmdInfo['permission'] ?? null,
            permissionMessage: $cmdInfo['permission-message'] ?? null,
            handler: $handler,
            plugin: $plugin,
        );

        // 别名索引
        foreach ($cmdInfo['aliases'] ?? [] as $alias) {
            $this->commandAliases[(string)$alias] = $cmdName;
        }
    }

    /**
     * 执行命令（按主命令名或别名）
     *
     * 处理器签名统一为 handler(CommandSender $sender, array $args): bool
     *
     * @return mixed 命令执行结果（false = 失败/被拒绝）
     */
    public function executeCommand(string $name, array $args = [], ?CommandSender $sender = null): mixed
    {
        // 别名解析
        $primary = $this->commandAliases[$name] ?? $name;

        if (!isset($this->commands[$primary])) {
            return null;
        }

        $cmd = $this->commands[$primary];
        $sender = $sender ?? new ConsoleCommandSender();

        // 权限检查
        if ($cmd->permission !== null && $cmd->permission !== '' && !$sender->hasPermission($cmd->permission)) {
            $sender->sendMessage($cmd->permissionMessage ?? 'You do not have permission to use this command');
            return false;
        }

        if ($cmd->handler === null) {
            return null;
        }

        try {
            return call_user_func($cmd->handler, $sender, $args);
        } catch (\Throwable $e) {
            error_log("Command execution error [$primary]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 解析并执行一条完整命令行（对照原版 SimpleCommandMap::dispatch）
     *
     * @return bool 命令是否执行成功（未知命令/被拒/失败均返回 false）
     */
    public function dispatchCommand(string $line, ?CommandSender $sender = null): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        // 去掉前导 /
        if ($line[0] === '/') {
            $line = substr($line, 1);
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $name = array_shift($parts);
        if ($name === null || $name === '') {
            return false;
        }
        $result = $this->executeCommand($name, $parts, $sender);
        if ($result === null && !isset($this->commands[$this->commandAliases[$name] ?? $name])) {
            $sender?->sendMessage('Unknown command: ' . $name);
            return false;
        }
        return $result === false ? false : true;
    }

    /**
     * 检查命令是否已注册
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->commandAliases[$name]);
    }

    /**
     * 获取命令信息
     */
    public function getCommand(string $name): ?CommandInfo
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * 获取所有已注册命令
     *
     * @return array<string, CommandInfo>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * 获取命令数量
     */
    public function getCommandCount(): int
    {
        return count($this->commands);
    }

    /**
     * 查找命令（支持别名）
     */
    public function resolveCommand(string $name): ?CommandInfo
    {
        if (isset($this->commands[$name])) {
            return $this->commands[$name];
        }

        // 检查别名
        foreach ($this->commands as $cmdInfo) {
            if (in_array($name, $cmdInfo->aliases, true)) {
                return $cmdInfo;
            }
        }

        return null;
    }

    /* ==========================================================================
     * SplObjectStorage 迭代 / \WeakRef 工具
     * ========================================================================== */

    /**
     * 遍历所有插件（回调模式）
     */
    public function forEachPlugin(callable $callback): void
    {
        foreach ($this->pluginStorage as $plugin) {
            $info = $this->pluginStorage[$plugin];
            $callback($plugin, $info);
        }
    }

    /**
     * 创建插件的弱引用（PHP 8: \WeakReference）
     */
    public function createWeakRef(object $plugin): ?\WeakReference
    {
        if ($this->pluginStorage->contains($plugin)) {
            return \WeakReference::create($plugin);
        }
        return null;
    }

    /**
     * 检查插件对象是否在 SplObjectStorage 中
     */
    public function containsPlugin(object $plugin): bool
    {
        return $this->pluginStorage->contains($plugin);
    }

    /* ==========================================================================
     * 清理 / 重置
     * ========================================================================== */

    /**
     * 禁用所有插件
     */
    public function disableAllPlugins(): void
    {
        foreach ($this->pluginNameMap as $name => $plugin) {
            $plugin->enabled = false;
        }
    }

    /**
     * 卸载所有插件
     */
    public function unloadAllPlugins(): void
    {
        $names = array_keys($this->pluginNameMap);
        foreach ($names as $name) {
            $this->unloadPlugin($name);
        }
    }

    /**
     * 清理所有失效的事件处理器
     */
    public function fullCleanup(): int
    {
        $cleaned = $this->cleanupStaleHandlers();
        $this->pluginStorage = new \SplObjectStorage();
        $this->pluginNameMap = [];
        $this->eventHandlers = [];
        $this->commands = [];
        return $cleaned;
    }
}