# Genisys PHP 8.x — ReactOS 式架构重构

## 概述

将 Genisys（PHP 7.0 + pthreads 3.1.6 + weakref 0.3.2 + yaml 2.0.0RC7）移植到现代 PHP 8.x，采用 ReactOS 式分阶段架构重构。

## 模块架构

```
genisys-php8/
├── compat/                    # 兼容层（ReactOS HAL 等价物）
│   ├── runtime.php            # 废弃 API 替换（each/create_function/assert）
│   ├── concurrency.php        # pthreads → Fiber/parallel 接口
│   ├── weakref.php            # WeakRef polyfill（PHP 8.0 内置）
│   └── yaml.php               # YAML 解析适配器
├── modules/                   # 独立模块（ReactOS 子系统等价物）
│   ├── NetworkModule.php      # RakNet + RCON + Query 协议栈
│   ├── SchedulerModule.php    # 异步任务调度（Fiber + parallel）
│   ├── MemoryModule.php       # 内存管理 + 泄漏追踪（WeakRef）
│   ├── PluginModule.php       # 插件加载/卸载/事件
│   ├── LevelModule.php        # 世界/区块/存储（Anvil/McRegion/LevelDB）
│   ├── EntityModule.php       # 实体/AI/移动
│   ├── BlockModule.php        # 方块/放置/挖掘
│   ├── PlayerModule.php       # 玩家/认证/聊天/物品栏
│   ├── EventModule.php        # 事件总线（6级优先级责任链）
│   └── InventoryModule.php    # 物品栏/合成/容器
├── index.php                  # 服务端入口
├── composer.json              # 依赖管理
├── start.sh / start.cmd       # 启动脚本
└── tests/                     # 测试套件
```

## 模块互不关联原则

每个模块：
- 只通过 `compat/` 层获取运行时兼容性
- 只通过接口（interface）与其他模块通信
- 不直接 import 其他模块的类
- 自包含：可独立测试

## 移植策略

| 原组件 | PHP 7.0 依赖 | PHP 8.x 替代 |
|--------|-------------|-------------|
| Thread/Worker | pthreads 扩展 | Fiber + parallel |
| ThreadManager | Volatile (pthreads) | parallel\Channel |
| WeakRef | weakref 扩展 | \WeakRef (PHP 8.0 内置) |
| YAML | yaml 扩展 | symfony/yaml |
| each() | PHP 7.0 | foreach (PHP 8.0 已移除) |

## 快速开始

```bash
cd genisys-php8
php index.php        # 无需 composer install：bootstrap.php 自带引导器
php tests/run.php    # 跑测试套件（tests/cases/*.php）
```

## Windows 部署

```powershell
# 1. 准备 PHP 运行时（官方预编译包，推荐；自动下载 + 启用 sockets + 自检）
powershell -ExecutionPolicy Bypass -File windows\setup-php.ps1

# 2. 启动服务端（或直接双击 windows\start-server.cmd）
powershell -ExecutionPolicy Bypass -File windows\start-server.ps1
```

- 需从源码编译 PHP（裁剪扩展/定制）时：`windows\build-php-from-source.ps1`
  （前置：VS2022 C++ 工作负载 + git，走官方 php-sdk 流程，30-90 分钟）
- Windows 无 ext-pcntl/ext-leveldb 均不影响运行（composer.json 已将二者
  降为 suggest；世界格式默认 Anvil）
- 可用环境变量：`GENISYS_PORT`（默认 19132）、`GENISYS_MOTD`、
  `GENISYS_WORLD_DIR`（默认 `worlds/World`）
- 首次启动防火墙会询问放行 UDP 19132，选择允许

## 构建进度（对照原版 /workspace/genisys 的分批路线）

- **Batch 0（地基）— 完成**：bootstrap.php 逐文件引导（模块文件内多类声明，
  无法按类名 autoload）；index.php 修复启动链（SimpleThreadManager 注入）；
  NetworkModule::sendTo() socket_sendto 参数顺序 bug 修复。
  验证：服务端常驻运行，RakNet Unconnected Ping→Pong 往返 + MOTD 应答正确
  （`php tests/ping_smoke.php`）。
- **Batch 1（兼容层加固）— 完成**：新增 compat/BinaryStream.php（小端序，
  与原版 pocketmine NBT 网络格式一致）；compat/SimpleThreadManager.php；
  MiniYaml 降级解析器（无 symfony/yaml、无 yaml 扩展也能读写配置）；
  RuntimeCompat::each() 改为按引用（语义对齐原 PHP）。
  依赖现状：环境仅有 PHP 8.3 + sockets（已编译启用）；ext-parallel 不可用
  （Fiber 可用）；ext-leveldb 缺失（LevelDB 存储批次处理）。
- **Batch 2（纯逻辑模块测试）— 完成**：tests/run.php 轻量测试器 +
  tests/cases/ 20 个用例全通过。测试暴露并修复：
  EventModule::unregisterPlugin() 不删 handlers（真 bug）、
  ItemFactory::get() 实例化抽象类（真 bug，补 ItemGeneric/ItemBlockGeneric）。
- **Batch 3（物品/方块注册表对照补全）— 完成**：新增 `tools/extract_vanilla.php`
  从原版源码静态提取（支持 meta 变体名+掩码、switch 表、三元名、拼接名等
  6 种命名模式），生成 `modules/VanillaData.php`：**184 个方块**（含
  name/solid/transparent/hardness/tool/light/resistance）+ **149 个物品**，
  名字 100% 解析。新增 `modules/BlockFactory.php`（`Block` 值对象 + 工厂，
  未注册 ID 回退 UnknownBlock，对照原版）；`ItemFactory` 全量注册
  （工具/防具/特化类按原版类名映射，其余 Generic，meta 变体名查询）；
  `BlockModule` 五个查询接口（名字/硬度/光照/实心/透明）重接到注册表。
  对照测试 13 个锚点用例全过（Stone 1.5/镐、Bedrock -1、Glowstone 光 15、
  Coal→Charcoal、Raw Salmon、Water Bottle 等）。
- **Batch 4（世界/区块持久化存储）— 完成**：`serialize()` 假格式全部替换为
  真格式。新增 `modules/RegionLoader.php`（对照原版 RegionLoader：
  4096+4096 表头、`(x+z*32)*4` 定位索引、3 字节偏移+1 字节扇区数、
  4 字节 BE 长度 + 压缩字节 + zlib 负载、首次适配扇区分配、原地复用）；
  `modules/LevelProviders.php`（AnvilProvider：root ""→Level→Sections 16³
  列表，内存 flat 数组 YZX 与磁盘排布一致直接切片；McRegionProvider：
  整块 32768 字节布局；LevelDat：gzip 大端 NBT、Data compound，对照
  BaseLevelProvider）；NBTModule 增加大端序支持（BE 缺陷修复如
  (int)"0x03"）。字节级夹具测试验证格式兼容：BE NBT 布局、gzip 魔数、
  Data compound、region 定位表/时间表/压缩帧。跨区块（负坐标区域）往返
  通过。**LevelDB 真格式延后**（需 ext-leveldb，当前为占位实现）。
- **Batch 5（玩家/协议栈端到端）— 完成（协议 70 / MCPE 0.14.3）**：
  参照基准为上游 `iTXTech/Genisys` tag `0.14.3`（检出为 `/workspace/genisys-0143`
  worktree，master 的 1.0.4 检出不受影响）。新增 `modules/ProtocolModule.php`：
  MinecraftProtocol 常量表（CURRENT_PROTOCOL=70、ACCEPTED_PROTOCOLS=[45,46,60,70]、
  0x8f-0xca 全量包 ID）+ GamePacket 基类 + 15 个关键路径包类（Login/PlayStatus/
  Disconnect/Batch/Text/SetTime/StartGame/MovePlayer/UpdateBlock/
  SetSpawnPosition/AdventureSettings/FullChunkData/SetDifficulty/
  RequestChunkRadius/ChunkRadiusUpdate，字段逐一对齐 0.14.3）+ PacketRegistry。
  Batch 内层格式 = zlib([int LE 长度][ID+包体]…)；FullChunkData 载荷 =
  blocks+meta+光+高度图+群系色+extra（对照 McRegion::requestChunkTask）。
  RakNet 底层 PROTOCOL 修正为 6（0.14.3 客户端）；Accepted 改为封装帧发送；
  encapsulated 0x09/0x13 信令与游戏包分流；sendGamePacket 可靠有序发送。
  新增 PlayerSession 状态机：Login→协议校验→PlayStatus(SUCCESS)→StartGame
  →3×3 Batch(FullChunkData)→SetSpawnPosition/SetDifficulty/SetTime/
  AdventureSettings→PlayStatus(PLAYER_SPAWN)。E2E 测试在真实 UDP socket 上
  模拟客户端走完 握手→登录→出生→半径协商→聊天广播 全链。
- **Batch 4b（LevelDB 真格式）— 完成**：环境装好 libleveldb-dev + 编译启用
  php-leveldb（reeze/php-leveldb）。替换文件级占位实现为真 \LevelDB：
  键 = `chunkIndex(LE int32 X + LE int32 Z) + "0"地形/"f"标志/"v"版本`
  （对照 0.14.3 leveldb/LevelDB.php，含 83200 字节标准地形 blob）；
  level.dat = 8 字节头（version=3 LE + 长度 LE）+ 未压缩小端 NBT。
  测试含原始键字节校验（直接读 db 验证 "0"/"f"/"v" 条目）与跨区块往返。
  限制注记：真实 MCPE 设备存档使用 MCPE 私有 leveldb fork 的 ZLIB 压缩，
  标准 leveldb 库不支持，读取此类存档需链接 MCPE fork（本实现用扩展默认
  压缩，键结构不受影响）。顺带修复小端 NBT TAG_INT 无符号读取的存量 bug
  （负数 IntTag 会错成 4294967262）。
- **Batch 6（插件/命令系统收尾）— 完成**：
  - **插件**：主类真正实例化（此前只有 stdClass 载体）——加载器出信息载体，
    `PluginModule::materializeMainClass` 按 `$path/$main.php` 或 `$path/src/`
    约定加载 main 类并实例化 `PluginBase`；onEnable/onDisable 生命周期钩子
    接通；plugin.yml 的 commands 桥接到 `onCommand(sender, command, label, args)`；
    注解式事件自动注册 `registerEvents(listener, plugin)`（@priority /
    @ignoreCancelled，对照原版 PluginManager）+ `callEventObject` 对象事件派发
    （返回 false 取消事件）；loadPlugins 修为顶层目录扫描（原递归迭代器
    永远给不出目录路径的 bug）。
  - **命令**：别名索引（plugin.yml aliases 生效）、`dispatchCommand(行解析)`、
    处理器签名统一为 `(CommandSender, args)`、权限检查与拒绝消息；
    `CommandModule.php` 新增 Event/Listener/PluginBase/CommandSender/
    ConsoleCommandSender/DefaultCommands（version/plugins/list/say/stop，
    对照 command/defaults/）/ConsoleReader（pthreads CommandReader →
    stream_select 非阻塞轮询，可注入流）。
  - **装配**：index.php 加载 plugins/ 目录 + 默认命令 + 控制台轮询挂到
    NetworkModule::$onTick；composer.json 修正（classmap autoload、
    test/start/check 脚本、parallel/leveldb/symfony-yaml 降为 suggest）。
  - **顺带修复**：EventHandlerEntry 用不存在的 `\WeakRef::create`（PHP 8
    fatal）→ \WeakReference；PluginInfo readonly 双重赋值（构造即 fatal）；
    MiniYaml 补行内流式 `[a, b]` / `{k: v}` 支持。

## 模块清单

| 模块 | 文件 | 职责 | PHP 8.x 变化 |
|------|------|------|-------------|
| NetworkModule | modules/NetworkModule.php | RakNet 协议栈 | sockets → Swoole/ReactPHP |
| SchedulerModule | modules/SchedulerModule.php | 异步任务调度 | Thread → Fiber |
| MemoryModule | modules/MemoryModule.php | 内存管理 | weakref → \WeakRef |
| PluginModule | modules/PluginModule.php | 插件系统 | Volatile → SplObjectStorage |
| LevelModule | modules/LevelModule.php | 世界/区块 | SplFixedArray → array |
| EntityModule | modules/EntityModule.php | 实体/AI | 纤程化 AI 逻辑 |
| BlockModule | modules/BlockModule.php | 方块系统 | 位运算保持 |
| PlayerModule | modules/PlayerModule.php | 玩家管理 | 纤程化玩家处理 |
| EventModule | modules/EventModule.php | 事件总线 | 优先级排序优化 |
| InventoryModule | modules/InventoryModule.php | 物品栏/合成 | NBT 序列化保持 |