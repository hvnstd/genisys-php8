<?php
/** 插件/命令系统收尾测试（Batch 6） */
declare(strict_types=1);

use Genisys\Module\PluginModule;
use Genisys\Module\FolderPluginLoader;
use Genisys\Module\ConsoleCommandSender;
use Genisys\Module\CommandSender;
use Genisys\Module\CommandModule;
use Genisys\Module\DefaultCommands;
use Genisys\Module\ConsoleReader;

const FIXTURE_DIR = __DIR__ . '/../fixtures';
const PLUGINS_DIR = FIXTURE_DIR;

function collectingSender(array &$sink): ConsoleCommandSender
{
    return new ConsoleCommandSender(function (string $m) use (&$sink): void {
        $sink[] = $m;
    });
}

test('plugin.yml parsed into PluginInfo with commands and aliases', function () {
    $loader = new FolderPluginLoader();
    $info = $loader->getPluginDescription(PLUGINS_DIR . '/TestPlugin');
    check($info !== null, 'description parsed');
    check_same('TestPlugin', $info->name);
    check_same('1.2.3', $info->version);
    check_same('TestPlugin', $info->main);
    check_same(['Tester'], $info->authors);
    check(isset($info->commands['greet']), 'greet command declared');
    check_same(['hi'], $info->commands['greet']['aliases'] ?? []);
});

test('main class instantiated, onEnable ran, yml commands registered', function () {
    $pm = new PluginModule();
    $loaded = $pm->loadPlugins(PLUGINS_DIR);
    check(in_array('TestPlugin', $loaded, true), 'TestPlugin loaded');
    check_same(true, TestPlugin::$enabledFlag, 'onEnable hook ran');
    check($pm->isPluginEnabled('TestPlugin'));
    check($pm->hasCommand('greet'), 'yml command registered');
    check($pm->hasCommand('hi'), 'yml command alias registered');
});

test('registerEvents annotation scan registers handlers', function () {
    $pm = new PluginModule();
    $pm->loadPlugins(PLUGINS_DIR);
    check_same(4, $pm->getEventHandlerCount('test.custom'), '4 annotated handlers');
});

test('callEventObject priority order and cancellation', function () {
    $pm = new PluginModule();
    $pm->loadPlugins(PLUGINS_DIR);

    // 未取消的事件：全部按优先级执行（MONITOR(0) canceller 返回 true，HIGH(2)，NORMAL(3)，LOW(4)）
    TestListener::$log = [];
    $cancelled = $pm->callEventObject(new TestEvent('go'));
    check_same(['high:go', 'aware', 'plain'], TestListener::$log, 'priority order');
    check(!$cancelled, 'not cancelled when handlers accept');

    // 取消事件：MONITOR canceller 返回 false → @ignoreCancelled true 的 onAware 跳过，LOW 的 onPlain 照常
    TestListener::$log = [];
    $event = new TestEvent('cancel');
    $cancelled = $pm->callEventObject($event);
    check_same(['high:cancel', 'plain'], TestListener::$log, 'ignoreCancelled skipped, others ran');
    check($cancelled, 'canceller returning false cancels event');
    check($event->cancelled, 'cancelled flag set on event object');
});

test('plugin command bridges to onCommand with sender and args', function () {
    $pm = new PluginModule();
    $pm->loadPlugins(PLUGINS_DIR);
    $sink = [];
    $sender = collectingSender($sink);

    $ok = $pm->dispatchCommand('/greet Steve', $sender);
    check($ok, 'greet dispatched');
    check_same([['greet', ['Steve']]], TestPlugin::$commandLog, 'onCommand received args');
    check_same(['Hello Steve'], $sink, 'sender got reply');

    // 别名
    TestPlugin::$commandLog = [];
    $ok = $pm->dispatchCommand('hi Alex', $sender);
    check($ok, 'alias hi dispatched');
    check_same([['greet', ['Alex']]], TestPlugin::$commandLog, 'alias resolves to greet');

    // 未知命令
    $ok = $pm->dispatchCommand('nosuchcmd', $sender);
    check(!$ok, 'unknown command fails');
    check_same('Unknown command: nosuchcmd', $sink[2] ?? '', 'unknown message');
});

test('default commands: version/plugins/say permission', function () {
    $pm = new PluginModule();
    $pm->loadPlugins(PLUGINS_DIR); // TestPlugin 先注册，验证共处
    DefaultCommands::register($pm, null, [
        'name' => 'Genisys PHP8',
        'version' => '1.0.0',
        'protocol' => 70,
        'mcpeVersion' => 'v0.14.3',
    ]);

    $sink = [];
    $sender = collectingSender($sink);
    check($pm->dispatchCommand('ver', $sender), 'version via alias');
    check(str_contains($sink[0] ?? '', 'Genisys PHP8 1.0.0'), 'version text');
    check(str_contains($sink[0] ?? '', 'protocol 70'), 'protocol text');

    // plugins 命令列出 TestPlugin
    $sink = [];
    $pm->dispatchCommand('plugins', $sender);
    check(str_contains($sink[0] ?? '', 'TestPlugin v1.2.3'), 'plugin listed');

    // say 命令：控制台有权限，执行广播（无在线玩家，不报错即可）
    check($pm->dispatchCommand('say hello world', $sender), 'say with permission');
    // say 无参数 → false
    check(!$pm->dispatchCommand('say', $sender), 'say without args fails');
});

test('disablePlugin runs onDisable, unregisters handlers and commands', function () {
    $pm = new PluginModule();
    $pm->loadPlugins(PLUGINS_DIR);
    check_same(4, $pm->getEventHandlerCount('test.custom'));
    check($pm->hasCommand('greet'));

    $pm->disablePlugin('TestPlugin');
    check_same(false, TestPlugin::$enabledFlag, 'onDisable hook ran');
    check_same(0, $pm->getEventHandlerCount('test.custom'), 'handlers unregistered');
    check(!$pm->hasCommand('greet'), 'commands unregistered');
    check(!$pm->hasCommand('hi'), 'alias unregistered');
});

test('console reader polls lines from injected stream', function () {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, "version\nsay hi\n\nstop\n");
    rewind($stream);
    $reader = new ConsoleReader($stream);
    $reader->poll();
    check_same('version', $reader->nextCommand());
    check_same('say hi', $reader->nextCommand());
    check_same('stop', $reader->nextCommand()); // 空行跳过
    check_same(null, $reader->nextCommand(), 'queue exhausted');
    $reader->close();
});

test('command module entry exists', function () {
    $cm = new CommandModule();
    check_same('CommandModule', $cm->getName());
});
