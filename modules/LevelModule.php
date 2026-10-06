<?php
/**
 * Module: LevelModule
 * 职责：世界/区块/存储（替代 Level.php + Chunk.php）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：SplFixedArray → array, LevelDB 保持
 *
 * 完整世界存储系统：
 *   - Anvil 格式（ChunkSection 位运算索引 + 4bit 数据打包）
 *   - McRegion 格式（区域文件头 4096+4096 字节表）
 *   - LevelDB 格式（键前缀体系 + 4字节LE chunkX/chunkZ）
 *   - 三种格式自动检测（扩展名/魔数）
 *   - 4 种生成器：Normal / Hell / Flat / Void
 *   - 16 种生物群系（基于噪声选择）
 *   - 两阶段生成：地形生成 → 表面填充
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\RuntimeCompat;

// ========================================================================
// 辅助类：Simplex 噪声生成器
// ========================================================================

class SimplexNoise
{
    private array $perm = [];
    private float $offsetX = 0.0;
    private float $offsetY = 0.0;
    private float $offsetZ = 0.0;

    private static array $grad3 = [
        [1, 1, 0], [-1, 1, 0], [1, -1, 0], [-1, -1, 0],
        [1, 0, 1], [-1, 0, 1], [1, 0, -1], [-1, 0, -1],
        [0, 1, 1], [0, -1, 1], [0, 1, -1], [0, -1, -1]
    ];

    public function __construct(int $seed)
    {
        $this->offsetX = ($seed & 0xffff) / 256.0;
        $this->offsetY = (($seed >> 8) & 0xffff) / 256.0;
        $this->offsetZ = (($seed >> 16) & 0xffff) / 256.0;

        $rand = new RandomCompat($seed);
        for ($i = 0; $i < 512; $i++) {
            $this->perm[$i] = 0;
        }
        for ($i = 0; $i < 256; $i++) {
            $this->perm[$i] = $rand->nextBoundedInt(256);
        }
        for ($i = 0; $i < 256; $i++) {
            $pos = $rand->nextBoundedInt(256 - $i) + $i;
            $old = $this->perm[$i];
            $this->perm[$i] = $this->perm[$pos];
            $this->perm[$pos] = $old;
            $this->perm[$i + 256] = $this->perm[$i];
        }
    }

    private static function fade(float $x): float
    {
        return $x * $x * $x * ($x * ($x * 6 - 15) + 10);
    }

    private static function grad(int $hash, float $x, float $y, float $z): float
    {
        $hash &= 15;
        $u = $hash < 8 ? $x : $y;
        $v = $hash < 4 ? $y : (($hash === 12 || $hash === 14) ? $x : $z);
        return (($hash & 1) === 0 ? $u : -$u) + (($hash & 2) === 0 ? $v : -$v);
    }

    public function noise3D(float $x, float $y, float $z): float
    {
        $x += $this->offsetX;
        $y += $this->offsetY;
        $z += $this->offsetZ;

        $floorX = (int) $x;
        $floorY = (int) $y;
        $floorZ = (int) $z;

        $X = $floorX & 0xFF;
        $Y = $floorY & 0xFF;
        $Z = $floorZ & 0xFF;

        $x -= $floorX;
        $y -= $floorY;
        $z -= $floorZ;

        $fX = self::fade($x);
        $fY = self::fade($y);
        $fZ = self::fade($z);

        $A = $this->perm[$X] + $Y;
        $B = $this->perm[$X + 1] + $Y;

        $AA = $this->perm[$A] + $Z;
        $AB = $this->perm[$A + 1] + $Z;
        $BA = $this->perm[$B] + $Z;
        $BB = $this->perm[$B + 1] + $Z;

        $AA1 = self::grad($this->perm[$AA], $x, $y, $z);
        $BA1 = self::grad($this->perm[$BA], $x - 1, $y, $z);
        $AB1 = self::grad($this->perm[$AB], $x, $y - 1, $z);
        $BB1 = self::grad($this->perm[$BB], $x - 1, $y - 1, $z);
        $AA2 = self::grad($this->perm[$AA + 1], $x, $y, $z - 1);
        $BA2 = self::grad($this->perm[$BA + 1], $x - 1, $y, $z - 1);
        $AB2 = self::grad($this->perm[$AB + 1], $x, $y - 1, $z - 1);
        $BB2 = self::grad($this->perm[$BB + 1], $x - 1, $y - 1, $z - 1);

        $xLerp11 = $AA1 + $fX * ($BA1 - $AA1);
        $zLerp1 = $xLerp11 + $fY * ($AB1 + $fX * ($BB1 - $AB1) - $xLerp11);
        $xLerp21 = $AA2 + $fX * ($BA2 - $AA2);

        return $zLerp1 + $fZ * ($xLerp21 + $fY * ($AB2 + $fX * ($BB2 - $AB2) - $xLerp21) - $zLerp1);
    }

    public function noise2D(float $x, float $z): float
    {
        return $this->noise3D($x, 0, $z);
    }
}

// ========================================================================
// 辅助类：随机数兼容类
// ========================================================================

class RandomCompat
{
    private int $seed;

    public function __construct(int $seed = 0)
    {
        $this->setSeed($seed);
    }

    public function setSeed(int $seed): void
    {
        $this->seed = crc32(pack('N', $seed));
    }

    public function nextInt(): int
    {
        return $this->nextSignedInt() & 0x7fffffff;
    }

    public function nextSignedInt(): int
    {
        $t = ((($this->seed * 65535) + 31337) >> 8) + 1337;
        if (PHP_INT_SIZE === 8) {
            $t = $t << 32 >> 32;
        }
        $this->seed ^= $t;
        return $t;
    }

    public function nextFloat(): float
    {
        return $this->nextInt() / 0x7fffffff;
    }

    public function nextBoolean(): bool
    {
        return ($this->nextSignedInt() & 0x01) === 0;
    }

    public function nextBoundedInt(int $bound): int
    {
        return $this->nextInt() % $bound;
    }
}

// ========================================================================
// 辅助类：Anvil ChunkSection（16×16×16 区块段）
// 位运算索引公式：($y<<8)+($z<<4)+$x
// 4bit 数据打包：每字节存两个方块ID/meta
// ========================================================================

class AnvilChunkSection
{
    private int $y;
    private string $blocks;     // 4096 字节（每个方块1字节ID）
    private string $data;       // 2048 字节（每字节存两个4bit meta）
    private string $blockLight; // 2048 字节
    private string $skyLight;   // 2048 字节

    public function __construct(int $sectionY = 0)
    {
        $this->y = $sectionY;
        $this->blocks = str_repeat("\x00", 4096);
        $this->data = str_repeat("\x00", 2048);
        $this->blockLight = str_repeat("\x00", 2048);
        $this->skyLight = str_repeat("\xff", 2048);
    }

    /**
     * 位运算索引公式：($y<<8)+($z<<4)+$x
     */
    private function index(int $x, int $y, int $z): int
    {
        return ($y << 8) + ($z << 4) + $x;
    }

    public function getBlockId(int $x, int $y, int $z): int
    {
        $i = $this->index($x, $y, $z);
        return ord($this->blocks[$i]);
    }

    public function setBlockId(int $x, int $y, int $z, int $id): void
    {
        $i = $this->index($x, $y, $z);
        $this->blocks[$i] = chr($id & 0xff);
    }

    public function getBlockData(int $x, int $y, int $z): int
    {
        $i = ($y << 7) + ($z << 3) + ($x >> 1);
        $m = ord($this->data[$i]);
        if (($x & 1) === 0) {
            return $m & 0x0F;
        } else {
            return $m >> 4;
        }
    }

    public function setBlockData(int $x, int $y, int $z, int $data): void
    {
        $i = ($y << 7) + ($z << 3) + ($x >> 1);
        $oldM = ord($this->data[$i]);
        if (($x & 1) === 0) {
            $this->data[$i] = chr(($oldM & 0xf0) | ($data & 0x0f));
        } else {
            $this->data[$i] = chr((($data & 0x0f) << 4) | ($oldM & 0x0f));
        }
    }

    public function getFullBlock(int $x, int $y, int $z): int
    {
        $blockId = $this->getBlockId($x, $y, $z);
        $meta = $this->getBlockData($x, $y, $z);
        return ($blockId << 4) | $meta;
    }

    public function setFullBlock(int $x, int $y, int $z, int $fullBlock): void
    {
        $blockId = $fullBlock >> 4;
        $meta = $fullBlock & 0x0F;
        $this->setBlockId($x, $y, $z, $blockId);
        $this->setBlockData($x, $y, $z, $meta);
    }

    public function getBlockLight(int $x, int $y, int $z): int
    {
        $i = ($y << 7) + ($z << 3) + ($x >> 1);
        $l = ord($this->blockLight[$i]);
        if (($x & 1) === 0) {
            return $l & 0x0F;
        } else {
            return $l >> 4;
        }
    }

    public function setBlockLight(int $x, int $y, int $z, int $level): void
    {
        $i = ($y << 7) + ($z << 3) + ($x >> 1);
        $oldL = ord($this->blockLight[$i]);
        if (($x & 1) === 0) {
            $this->blockLight[$i] = chr(($oldL & 0xf0) | ($level & 0x0f));
        } else {
            $this->blockLight[$i] = chr((($level & 0x0f) << 4) | ($oldL & 0x0f));
        }
    }

    public function getSkyLight(int $x, int $y, int $z): int
    {
        $i = ($y << 7) + ($z << 3) + ($x >> 1);
        $sl = ord($this->skyLight[$i]);
        if (($x & 1) === 0) {
            return $sl & 0x0F;
        } else {
            return $sl >> 4;
        }
    }

    public function setSkyLight(int $x, int $y, int $z, int $level): void
    {
        $i = ($y << 7) + ($z << 3) + ($x >> 1);
        $oldSl = ord($this->skyLight[$i]);
        if (($x & 1) === 0) {
            $this->skyLight[$i] = chr(($oldSl & 0xf0) | ($level & 0x0f));
        } else {
            $this->skyLight[$i] = chr((($level & 0x0f) << 4) | ($oldSl & 0x0f));
        }
    }

    public function getY(): int { return $this->y; }
    public function getBlocks(): string { return $this->blocks; }
    public function getData(): string { return $this->data; }
    public function getBlockLightArray(): string { return $this->blockLight; }
    public function getSkyLightArray(): string { return $this->skyLight; }

    public function toBinary(): string
    {
        return $this->blocks . $this->data . $this->blockLight . $this->skyLight;
    }

    public static function fromBinary(string $binary, int $sectionY): self
    {
        $section = new self($sectionY);
        $offset = 0;
        $section->blocks = substr($binary, $offset, 4096); $offset += 4096;
        $section->data = substr($binary, $offset, 2048); $offset += 2048;
        $section->blockLight = substr($binary, $offset, 2048); $offset += 2048;
        $section->skyLight = substr($binary, $offset, 2048);
        return $section;
    }
}

// ========================================================================
// 主类：LevelModule
// ========================================================================

class LevelModule
{
    // ========================================================================
    // 常量定义
    // ========================================================================

    // 方块 ID（与 BlockModule 对齐）
    public const AIR           = 0;
    public const STONE         = 1;
    public const GRASS         = 2;
    public const DIRT          = 3;
    public const COBBLESTONE   = 4;
    public const PLANKS        = 5;
    public const SAPLING       = 6;
    public const BEDROCK       = 7;
    public const WATER         = 8;
    public const STILL_WATER   = 9;
    public const LAVA          = 10;
    public const STILL_LAVA    = 11;
    public const SAND          = 12;
    public const GRAVEL        = 13;
    public const GOLD_ORE      = 14;
    public const IRON_ORE      = 15;
    public const COAL_ORE      = 16;
    public const WOOD          = 17;
    public const LEAVES        = 18;
    public const SPONGE        = 19;
    public const GLASS         = 20;
    public const LAPIS_ORE     = 21;
    public const SANDSTONE     = 24;
    public const TALL_GRASS    = 31;
    public const WOOL          = 35;
    public const DANDELION     = 37;
    public const RED_FLOWER    = 38;
    public const BROWN_MUSHROOM = 39;
    public const RED_MUSHROOM   = 40;
    public const REEDS         = 82;
    public const WHEAT         = 59;
    public const CARROTS       = 141;
    public const POTATOES      = 142;
    public const BEETROOT     = 207;
    public const NETHERRACK    = 87;
    public const SOUL_SAND     = 112;
    public const NETHER_QUARTZ_ORE = 110;
    public const GLOWSTONE     = 89;
    public const SNOW          = 78;

    // 生物群系 ID
    public const BIOME_OCEAN          = 0;
    public const BIOME_PLAINS         = 1;
    public const BIOME_DESERT         = 2;
    public const BIOME_MOUNTAINS      = 3;
    public const BIOME_FOREST         = 4;
    public const BIOME_TAIGA          = 5;
    public const BIOME_SWAMP          = 6;
    public const BIOME_RIVER          = 7;
    public const BIOME_HELL           = 8;
    public const BIOME_ICE_PLAINS     = 12;
    public const BIOME_SMALL_MOUNTAINS = 20;
    public const BIOME_BIRCH_FOREST   = 27;
    public const BIOME_JUNGLE         = 21;
    public const BIOME_SAVANNA        = 34;
    public const BIOME_MUSHROOM_ISLAND = 169;
    public const BIOME_MUSHROOM_SHORE = 170;

    // 区块尺寸
    public const CHUNK_SIZE   = 16;
    public const SECTION_SIZE = 16;
    public const CHUNK_HEIGHT = 128;
    public const SECTIONS_PER_CHUNK = 8;

    // McRegion 常量
    public const REGION_SIZE       = 512;
    public const CHUNKS_PER_REGION = 32;
    public const REGION_HEADER_SIZE = 8192;  // 4096 位置表 + 4096 时间表

    // Anvil 常量
    public const ANVIL_MAGIC = 0x414e564c; // "ANVL"

    // LevelDB 键前缀
    public const ENTRY_TERRAIN         = "\x2f";
    public const ENTRY_ENTITIES        = "\x30";
    public const ENTRY_PORTAL          = "\x31";
    public const ENTRY_TILE            = "\x32";
    public const ENTRY_STRUCTURE       = "\x33";
    public const ENTRY_OLD_PLATFORM    = "\x34";
    public const ENTRY_OLD_TERRAIN     = "\x35";
    public const ENTRY_OLD_ENTITIES    = "\x36";
    public const ENTRY_OLD_PORTAL      = "\x37";
    public const ENTRY_OLD_TILE        = "\x38";
    public const ENTRY_OLD_STRUCTURE   = "\x39";
    public const ENTRY_OLD_PLATFORM_2  = "\x3a";
    public const ENTRY_OLD_TERRAIN_2   = "\x3b";
    public const ENTRY_OLD_ENTITIES_2  = "\x3c";
    public const ENTRY_OLD_PORTAL_2    = "\x3d";
    public const ENTRY_OLD_TILE_2      = "\x3e";
    public const ENTRY_OLD_STRUCTURE_2 = "\x3f";
    public const ENTRY_PLATFORM_2      = "\x40";
    public const ENTRY_TERRAIN_2       = "\x41";
    public const ENTRY_ENTITIES_2      = "\x42";
    public const ENTRY_PORTAL_2        = "\x43";
    public const ENTRY_TILE_2          = "\x44";
    public const ENTRY_STRUCTURE_2     = "\x45";

    // 存储格式
    public const FORMAT_ANVIL    = 'anvil';
    public const FORMAT_MCREGION = 'mcregion';
    public const FORMAT_LEVELDB  = 'leveldb';

    // ========================================================================
    // 属性
    // ========================================================================

    private string $name;
    private string $path;
    private string $format;
    private int $seed;
    private string $generatorName;
    private array $generatorOptions;
    private int $spawnX = 0;
    private int $spawnY = 64;
    private int $spawnZ = 0;
    private int $time = 0;
    private array $chunks = [];
    private array $players = [];
    private array $levelData = [];
    private bool $autoSave = true;

    /** @var array<string, RegionLoader> 区域文件句柄缓存（key = regionX:regionZ:ext） */
    private array $regionCache = [];

    /** @var \LevelDB|null LevelDB 数据库句柄（leveldb 格式专用） */
    private $levelDB = null;

    // ========================================================================
    // 构造函数
    // ========================================================================

    public function __construct(string $name, string $path, int $seed = 0, string $generator = 'normal')
    {
        $this->name = $name;
        $this->path = $path;
        $this->seed = $seed;
        $this->generatorName = $generator;

        // 自动检测存储格式
        $this->format = $this->detectFormat($path);

        // 如果不存在则创建
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        $this->loadLevelData();
    }

    // ========================================================================
    // 格式检测
    // ========================================================================

    /**
     * 自动检测存储格式：检查文件扩展名或魔数
     */
    public function detectFormat(string $path): string
    {
        // 1. 检查 Anvil 格式：region/*.mca 文件
        $regionDir = $path . '/region';
        if (is_dir($regionDir)) {
            $files = glob($regionDir . '/*.mca');
            if (is_array($files) && count($files) > 0) {
                return self::FORMAT_ANVIL;
            }
            $mcrFiles = glob($regionDir . '/*.mcr');
            if (is_array($mcrFiles) && count($mcrFiles) > 0) {
                return self::FORMAT_MCREGION;
            }
        }

        // 2. 检查 LevelDB 格式：db/ 目录
        $dbDir = $path . '/db';
        if (is_dir($dbDir)) {
            return self::FORMAT_LEVELDB;
        }

        // 3. 检查 level.dat 魔数
        $levelDat = $path . '/level.dat';
        if (file_exists($levelDat)) {
            $handle = fopen($levelDat, 'rb');
            if ($handle) {
                $magic = fread($handle, 8);
                fclose($handle);
                $magicVal = unpack('N', substr($magic, 0, 4))[1] ?? 0;
                // LevelDB 格式的 level.dat 前 8 字节为版本号+长度
                if ($magicVal > 0 && $magicVal < 100) {
                    return self::FORMAT_LEVELDB;
                }
            }
        }

        // 4. 默认 Anvil
        return self::FORMAT_ANVIL;
    }

    /**
     * 检查文件魔数（用于单个区域文件检测）
     */
    public static function checkMagic(string $data): ?string
    {
        if (strlen($data) < 4) {
            return null;
        }
        $magic = unpack('N', substr($data, 0, 4));
        $val = $magic[1] ?? 0;

        if ($val === 0x4d43524e) { // "MCRN"
            return self::FORMAT_MCREGION;
        }

        return null;
    }

    // ========================================================================
    // Level 数据管理
    // ========================================================================

    private function loadLevelData(): void
    {
        $this->levelData = [
            'hardcore'       => 0,
            'initialized'    => 1,
            'GameType'       => 0,
            'generatorVersion' => 1,
            'SpawnX'         => $this->spawnX,
            'SpawnY'         => $this->spawnY,
            'SpawnZ'         => $this->spawnZ,
            'version'        => 19133,
            'DayTime'        => 0,
            'LastPlayed'     => time() * 1000,
            'RandomSeed'     => $this->seed,
            'SizeOnDisk'     => 0,
            'Time'           => 0,
            'generatorName'  => $this->generatorName,
            'generatorOptions' => '',
            'LevelName'      => $this->name,
        ];

        // level.dat：Anvil/McRegion 为 gzip 大端 NBT；LevelDB 为 8 字节头 + 未压缩小端 NBT
        // （对照原版 BaseLevelProvider 与 leveldb/LevelDB.php）
        $dat = new LevelDat($this->path);
        $loaded = $this->format === self::FORMAT_LEVELDB ? $dat->loadLeveldb() : $dat->load();
        if ($loaded !== null) {
            $this->levelData = array_merge($this->levelData, $loaded);
            $this->spawnX = (int)($this->levelData['SpawnX'] ?? $this->spawnX);
            $this->spawnY = (int)($this->levelData['SpawnY'] ?? $this->spawnY);
            $this->spawnZ = (int)($this->levelData['SpawnZ'] ?? $this->spawnZ);
            $this->time = (int)($this->levelData['Time'] ?? 0);
            $this->seed = (int)($this->levelData['RandomSeed'] ?? $this->seed);
            $this->name = (string)($this->levelData['LevelName'] ?? $this->name);
        }
    }

    public function saveLevelData(): void
    {
        $this->levelData['SpawnX'] = $this->spawnX;
        $this->levelData['SpawnY'] = $this->spawnY;
        $this->levelData['SpawnZ'] = $this->spawnZ;
        $this->levelData['Time'] = $this->time;
        $this->levelData['RandomSeed'] = $this->seed;
        $this->levelData['LevelName'] = $this->name;
        $this->levelData['LastPlayed'] = time() * 1000;

        $dat = new LevelDat($this->path);
        if ($this->format === self::FORMAT_LEVELDB) {
            $dat->saveLeveldb($this->levelData);
        } else {
            $dat->save($this->levelData);
        }
    }

    // ========================================================================
    // 区块加载/生成
    // ========================================================================

    /**
     * 获取区块（自动加载或生成）
     */
    public function getChunk(int $x, int $z): ?array
    {
        $key = $this->chunkHash($x, $z);
        if (!isset($this->chunks[$key])) {
            $this->chunks[$key] = $this->loadChunk($x, $z);
        }
        return $this->chunks[$key];
    }

    /**
     * 保存区块到磁盘
     */
    public function saveChunk(int $x, int $z, array $data): void
    {
        $key = $this->chunkHash($x, $z);
        $this->chunks[$key] = $data;
        $this->writeChunkToDisk($x, $z, $data);
    }

    /**
     * 区块哈希键
     */
    private function chunkHash(int $x, int $z): string
    {
        return "$x:$z";
    }

    /**
     * 加载区块（从存储格式读取或生成）
     */
    private function loadChunk(int $x, int $z): ?array
    {
        switch ($this->format) {
            case self::FORMAT_ANVIL:
                $data = $this->readAnvilChunk($x, $z);
                break;
            case self::FORMAT_MCREGION:
                $data = $this->readMcRegionChunk($x, $z);
                break;
            case self::FORMAT_LEVELDB:
                $data = $this->readLevelDBChunk($x, $z);
                break;
            default:
                $data = null;
        }

        if ($data !== null) {
            return $data;
        }

        return $this->generateChunk($x, $z);
    }

    /**
     * 写入区块到磁盘
     */
    private function writeChunkToDisk(int $x, int $z, array $data): void
    {
        switch ($this->format) {
            case self::FORMAT_ANVIL:
                $this->writeAnvilChunk($x, $z, $data);
                break;
            case self::FORMAT_MCREGION:
                $this->writeMcRegionChunk($x, $z, $data);
                break;
            case self::FORMAT_LEVELDB:
                $this->writeLevelDBChunk($x, $z, $data);
                break;
        }
    }

    // ========================================================================
    // Region 文件（真 Anvil/McRegion 格式，对照原版 RegionLoader）
    // ========================================================================

    /**
     * 获取缓存的区域文件容器
     */
    private function getRegion(int $regionX, int $regionZ, string $ext): RegionLoader
    {
        $key = "$regionX:$regionZ:$ext";
        if (!isset($this->regionCache[$key])) {
            $regionPath = $this->path . "/region/r.$regionX.$regionZ.$ext";
            $this->regionCache[$key] = new RegionLoader($regionPath, true);
        }
        return $this->regionCache[$key];
    }

    /**
     * 读取 Anvil 格式区块（.mca：zlib 压缩的大端 NBT Sections）
     */
    private function readAnvilChunk(int $x, int $z): ?array
    {
        $region = $this->getRegion($x >> 5, $z >> 5, AnvilProvider::REGION_FILE_EXTENSION);
        if (!$region->chunkExists($x, $z)) {
            return null;
        }
        $payload = $region->readChunkDecompressed($x, $z);
        if ($payload === null) {
            return null;
        }
        return AnvilProvider::deserializeChunk($payload);
    }

    /**
     * 写入 Anvil 格式区块
     */
    private function writeAnvilChunk(int $x, int $z, array $data): void
    {
        $regionDir = $this->path . '/region';
        if (!is_dir($regionDir)) {
            mkdir($regionDir, 0777, true);
        }
        $region = $this->getRegion($x >> 5, $z >> 5, AnvilProvider::REGION_FILE_EXTENSION);
        $region->writeChunk($x, $z, AnvilProvider::serializeChunk($data));
    }

    /**
     * 读取 McRegion 格式区块（.mcr：整块 32768 字节布局）
     */
    private function readMcRegionChunk(int $x, int $z): ?array
    {
        $region = $this->getRegion($x >> 5, $z >> 5, McRegionProvider::REGION_FILE_EXTENSION);
        if (!$region->chunkExists($x, $z)) {
            return null;
        }
        $payload = $region->readChunkDecompressed($x, $z);
        if ($payload === null) {
            return null;
        }
        return McRegionProvider::deserializeChunk($payload);
    }

    /**
     * 写入 McRegion 格式区块
     */
    private function writeMcRegionChunk(int $x, int $z, array $data): void
    {
        $regionDir = $this->path . '/region';
        if (!is_dir($regionDir)) {
            mkdir($regionDir, 0777, true);
        }
        $region = $this->getRegion($x >> 5, $z >> 5, McRegionProvider::REGION_FILE_EXTENSION);
        $region->writeChunk($x, $z, McRegionProvider::serializeChunk($data));
    }

    // ========================================================================
    // LevelDB 格式实现（对照原版 leveldb/LevelDB.php，需 ext-leveldb）
    // 键：chunkIndex(LE int32 X + LE int32 Z) + "0"地形/"f"标志/"v"版本
    // ========================================================================

    /**
     * 打开（带缓存）LevelDB 数据库句柄
     */
    private function getLevelDB()
    {
        if ($this->levelDB === null) {
            $this->levelDB = LevelDBProvider::openDatabase($this->path);
        }
        return $this->levelDB;
    }

    /**
     * LevelDB 键格式：chunkIndex + 条目后缀（对照原版 LevelDB::chunkIndex）
     */
    public static function levelDBKey(int $chunkX, int $chunkZ, string $prefix): string
    {
        return LevelDBProvider::chunkIndex($chunkX, $chunkZ) . $prefix;
    }

    /**
     * 读取 LevelDB 格式区块
     */
    private function readLevelDBChunk(int $x, int $z): ?array
    {
        try {
            $db = $this->getLevelDB();
        } catch (\RuntimeException) {
            return null; // 扩展缺失：当作无存档
        }
        $index = LevelDBProvider::chunkIndex($x, $z);
        $terrain = $db->get($index . LevelDBProvider::ENTRY_TERRAIN);
        if ($terrain === false || $terrain === null) {
            return null;
        }
        $flagsRaw = $db->get($index . LevelDBProvider::ENTRY_FLAGS);
        $flags = $flagsRaw !== false && $flagsRaw !== null ? ord($flagsRaw) : 0x03;
        return LevelDBProvider::deserializeChunk($terrain, $flags, $x, $z);
    }

    /**
     * 写入 LevelDB 格式区块
     */
    private function writeLevelDBChunk(int $x, int $z, array $data): void
    {
        $db = $this->getLevelDB();
        $index = LevelDBProvider::chunkIndex($x, $z);
        $db->put($index . LevelDBProvider::ENTRY_TERRAIN, LevelDBProvider::serializeChunk($data));
        $flags = 0;
        $flags |= !empty($data['generated']) ? LevelDBProvider::FLAG_GENERATED : 0;
        $flags |= !empty($data['populated']) ? LevelDBProvider::FLAG_POPULATED : 0;
        $flags |= LevelDBProvider::FLAG_LIGHT_POPULATED;
        $db->put($index . LevelDBProvider::ENTRY_FLAGS, chr($flags));
        $db->put($index . LevelDBProvider::ENTRY_VERSION, "\x02");
    }

    /**
     * 直接访问 LevelDB 数据库（供测试/工具校验原始条目）
     */
    public function getDatabase()
    {
        return $this->getLevelDB();
    }

    // ========================================================================
    // 区块生成 — 4 种生成器 + 16 种生物群系
    // ========================================================================

    /**
     * 生成区块（两阶段：地形生成 → 表面填充）
     */
    private function generateChunk(int $x, int $z): array
    {
        $chunk = $this->createEmptyChunk($x, $z);

        switch ($this->generatorName) {
            case 'hell':
                $this->generateHellTerrain($chunk, $x, $z);
                break;
            case 'flat':
                $this->generateFlatTerrain($chunk, $x, $z);
                break;
            case 'void':
                $this->generateVoidTerrain($chunk, $x, $z);
                break;
            case 'normal':
            default:
                $this->generateNormalTerrain($chunk, $x, $z);
                break;
        }

        // 第二阶段：表面填充
        $this->populateSurface($chunk, $x, $z);

        return $chunk;
    }

    /**
     * 创建空区块结构
     */
    private function createEmptyChunk(int $x, int $z): array
    {
        $blocks = array_fill(0, self::CHUNK_SIZE * self::CHUNK_SIZE * self::CHUNK_HEIGHT, self::AIR);
        $biomes = array_fill(0, self::CHUNK_SIZE * self::CHUNK_SIZE, self::BIOME_PLAINS);
        $heightMap = array_fill(0, self::CHUNK_SIZE * self::CHUNK_SIZE, 0);
        $biomeColors = array_fill(0, self::CHUNK_SIZE * self::CHUNK_SIZE, 0);

        return [
            'x'          => $x,
            'z'          => $z,
            'blocks'     => $blocks,
            'biomes'     => $biomes,
            'heightMap'  => $heightMap,
            'biomeColors' => $biomeColors,
            'entities'   => [],
            'tiles'      => [],
            'generated'  => true,
            'populated'  => false,
        ];
    }

    /**
     * 获取方块 ID（从平铺数组）
     */
    private function getBlock(array &$chunk, int $x, int $y, int $z): int
    {
        $idx = ($y * self::CHUNK_SIZE + $z) * self::CHUNK_SIZE + $x;
        return $chunk['blocks'][$idx] ?? self::AIR;
    }

    /**
     * 设置方块 ID
     */
    private function setBlock(array &$chunk, int $x, int $y, int $z, int $id): void
    {
        $idx = ($y * self::CHUNK_SIZE + $z) * self::CHUNK_SIZE + $x;
        $chunk['blocks'][$idx] = $id;
    }

    /**
     * 设置生物群系
     */
    private function setBiome(array &$chunk, int $x, int $z, int $biomeId): void
    {
        $idx = $z * self::CHUNK_SIZE + $x;
        $chunk['biomes'][$idx] = $biomeId;
    }

    /**
     * 获取生物群系
     */
    private function getBiome(array &$chunk, int $x, int $z): int
    {
        $idx = $z * self::CHUNK_SIZE + $x;
        return $chunk['biomes'][$idx] ?? self::BIOME_PLAINS;
    }

    /**
     * 设置高度图
     */
    private function setHeightMap(array &$chunk, int $x, int $z, int $height): void
    {
        $idx = $z * self::CHUNK_SIZE + $x;
        $chunk['heightMap'][$idx] = $height;
    }

    /**
     * 获取高度图
     */
    private function getHeightMap(array &$chunk, int $x, int $z): int
    {
        $idx = $z * self::CHUNK_SIZE + $x;
        return $chunk['heightMap'][$idx] ?? 0;
    }

    // ========================================================================
    // Normal 生成器
    // ========================================================================

    /**
     * Normal 生成器：基于噪声的地形 + 生物群系选择
     */
    private function generateNormalTerrain(array &$chunk, int $chunkX, int $chunkZ): void
    {
        $seed = $this->seed;
        $rand = new RandomCompat(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $seed);

        $noise = new SimplexNoise($seed ^ ($chunkX * 16) ^ ($chunkZ * 16));

        $waterHeight = 62;

        // 生物群系温度/降雨量噪声
        $tempNoise = new SimplexNoise($seed ^ 0x1111 ^ ($chunkX * 16) ^ ($chunkZ * 16));
        $rainNoise = new SimplexNoise($seed ^ 0x2222 ^ ($chunkX * 16) ^ ($chunkZ * 16));

        for ($x = 0; $x < self::CHUNK_SIZE; ++$x) {
            for ($z = 0; $z < self::CHUNK_SIZE; ++$z) {
                $worldX = $chunkX * 16 + $x;
                $worldZ = $chunkZ * 16 + $z;

                // 基于噪声选择生物群系
                $temperature = ($tempNoise->noise2D($worldX, $worldZ) + 1) / 2;
                $rainfall = ($rainNoise->noise2D($worldX, $worldZ) + 1) / 2;
                $biomeId = $this->selectBiome($temperature, $rainfall);
                $this->setBiome($chunk, $x, $z, $biomeId);

                // 地形高度（基于噪声）
                $maxElevation = $this->getBiomeMaxElevation($biomeId);
                $minElevation = $this->getBiomeMinElevation($biomeId);

                // 高斯平滑邻域
                $smoothHeight = 0;
                $weightSum = 0;
                for ($sx = -2; $sx <= 2; ++$sx) {
                    for ($sz = -2; $sz <= 2; ++$sz) {
                        $wx = $worldX + $sx;
                        $wz = $worldZ + $sz;
                        $temp = ($tempNoise->noise2D($wx, $wz) + 1) / 2;
                        $rain = ($rainNoise->noise2D($wx, $wz) + 1) / 2;
                        $adjBiome = $this->selectBiome($temp, $rain);
                        $weight = 1.0 / (1.0 + sqrt($sx * $sx + $sz * $sz) * 0.5);
                        $smoothHeight += ($this->getBiomeMaxElevation($adjBiome) - 1) * $weight;
                        $weightSum += $weight;
                    }
                }
                $smoothHeight /= $weightSum;

                // 生成地形柱
                $solidLand = false;
                for ($y = self::CHUNK_HEIGHT - 1; $y >= 0; --$y) {
                    if ($y === 0) {
                        $this->setBlock($chunk, $x, $y, $z, self::BEDROCK);
                        continue;
                    }

                    $noiseValue = $noise->noise3D($worldX, $y, $worldZ);
                    $heightDiff = $smoothHeight - $y;

                    // 简化 cave 生成
                    $caveNoise = $noise->noise3D($worldX * 2, $y * 2, $worldZ * 2);
                    $caveThreshold = 0.1;

                    if ($heightDiff > -2 && $noiseValue > -0.3 + $caveNoise * $caveThreshold) {
                        $this->setBlock($chunk, $x, $y, $z, self::STONE);
                        $solidLand = true;
                    } elseif ($y <= $waterHeight && !$solidLand) {
                        $this->setBlock($chunk, $x, $y, $z, self::STILL_WATER);
                    } elseif ($solidLand && $y > $smoothHeight - 1) {
                        // 表面层
                        $surfaceBlock = $this->getBiomeSurfaceBlock($biomeId);
                        $this->setBlock($chunk, $x, $y, $z, $surfaceBlock);
                    } elseif ($solidLand) {
                        $this->setBlock($chunk, $x, $y, $z, self::STONE);
                    }

                    if ($solidLand && $this->getHeightMap($chunk, $x, $z) === 0) {
                        $this->setHeightMap($chunk, $x, $z, $y + 1);
                    }
                }
            }
        }
    }

    /**
     * Hell 生成器
     */
    private function generateHellTerrain(array &$chunk, int $chunkX, int $chunkZ): void
    {
        $seed = $this->seed;
        $rand = new RandomCompat(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $seed);
        $noise = new SimplexNoise($seed ^ ($chunkX * 16) ^ ($chunkZ * 16));

        $waterHeight = 32;
        $emptyHeight = 64;
        $density = 0.5;

        for ($x = 0; $x < self::CHUNK_SIZE; ++$x) {
            for ($z = 0; $z < self::CHUNK_SIZE; ++$z) {
                $worldX = $chunkX * 16 + $x;
                $worldZ = $chunkZ * 16 + $z;

                $this->setBiome($chunk, $x, $z, self::BIOME_HELL);

                for ($y = 0; $y < self::CHUNK_HEIGHT; ++$y) {
                    if ($y === 0 || $y === self::CHUNK_HEIGHT - 1) {
                        $this->setBlock($chunk, $x, $y, $z, self::BEDROCK);
                        continue;
                    }

                    $noiseValue = (abs($emptyHeight - $y) / $emptyHeight) - $noise->noise3D($worldX, $y, $worldZ);
                    $noiseValue -= 1 - $density;

                    if ($noiseValue > 0) {
                        $this->setBlock($chunk, $x, $y, $z, self::NETHERRACK);
                    } elseif ($y <= $waterHeight) {
                        $this->setBlock($chunk, $x, $y, $z, self::STILL_LAVA);
                    }

                    if ($this->getBlock($chunk, $x, $y, $z) !== self::AIR && $this->getHeightMap($chunk, $x, $z) === 0) {
                        $this->setHeightMap($chunk, $x, $z, $y + 1);
                    }
                }
            }
        }
    }

    /**
     * Flat 生成器
     */
    private function generateFlatTerrain(array &$chunk, int $chunkX, int $chunkZ): void
    {
        for ($x = 0; $x < self::CHUNK_SIZE; ++$x) {
            for ($z = 0; $z < self::CHUNK_SIZE; ++$z) {
                $this->setBiome($chunk, $x, $z, self::BIOME_PLAINS);

                // Bedrock 底层
                $this->setBlock($chunk, $x, 0, $z, self::BEDROCK);
                // 泥土层
                for ($y = 1; $y <= 3; ++$y) {
                    $this->setBlock($chunk, $x, $y, $z, self::DIRT);
                }
                // 草顶层
                $this->setBlock($chunk, $x, 4, $z, self::GRASS);

                $this->setHeightMap($chunk, $x, $z, 5);
            }
        }
    }

    /**
     * Void 生成器
     */
    private function generateVoidTerrain(array &$chunk, int $chunkX, int $chunkZ): void
    {
        for ($x = 0; $x < self::CHUNK_SIZE; ++$x) {
            for ($z = 0; $z < self::CHUNK_SIZE; ++$z) {
                $this->setBiome($chunk, $x, $z, self::BIOME_OCEAN);
                $this->setHeightMap($chunk, $x, $z, 0);
            }
        }
    }

    // ========================================================================
    // 生物群系选择
    // ========================================================================

    /**
     * 基于温度和降雨量选择生物群系（16 种）
     */
    private function selectBiome(float $temperature, float $rainfall): int
    {
        if ($rainfall < 0.25) {
            if ($temperature < 0.7) return self::BIOME_OCEAN;
            if ($temperature < 0.85) return self::BIOME_RIVER;
            return self::BIOME_SWAMP;
        } elseif ($rainfall < 0.60) {
            if ($temperature < 0.25) return self::BIOME_ICE_PLAINS;
            if ($temperature < 0.75) return self::BIOME_PLAINS;
            return self::BIOME_DESERT;
        } elseif ($rainfall < 0.80) {
            if ($temperature < 0.25) return self::BIOME_TAIGA;
            if ($temperature < 0.75) return self::BIOME_FOREST;
            return self::BIOME_BIRCH_FOREST;
        } else {
            if ($temperature < 0.25) return self::BIOME_MOUNTAINS;
            if ($temperature < 0.70) return self::BIOME_SMALL_MOUNTAINS;
            return self::BIOME_RIVER;
        }
    }

    /**
     * 获取生物群系最低海拔
     */
    private function getBiomeMinElevation(int $biomeId): float
    {
        switch ($biomeId) {
            case self::BIOME_OCEAN: return 0;
            case self::BIOME_RIVER: return 0;
            case self::BIOME_PLAINS: return 1;
            case self::BIOME_DESERT: return 1;
            case self::BIOME_FOREST: return 1;
            case self::BIOME_TAIGA: return 1;
            case self::BIOME_SWAMP: return 1;
            case self::BIOME_ICE_PLAINS: return 1;
            case self::BIOME_MOUNTAINS: return 5;
            case self::BIOME_SMALL_MOUNTAINS: return 3;
            case self::BIOME_BIRCH_FOREST: return 1;
            case self::BIOME_HELL: return 0;
            default: return 1;
        }
    }

    /**
     * 获取生物群系最高海拔
     */
    private function getBiomeMaxElevation(int $biomeId): float
    {
        switch ($biomeId) {
            case self::BIOME_OCEAN: return 1;
            case self::BIOME_RIVER: return 2;
            case self::BIOME_PLAINS: return 3;
            case self::BIOME_DESERT: return 3;
            case self::BIOME_FOREST: return 4;
            case self::BIOME_TAIGA: return 4;
            case self::BIOME_SWAMP: return 3;
            case self::BIOME_ICE_PLAINS: return 3;
            case self::BIOME_MOUNTAINS: return 8;
            case self::BIOME_SMALL_MOUNTAINS: return 5;
            case self::BIOME_BIRCH_FOREST: return 4;
            case self::BIOME_HELL: return 1;
            default: return 3;
        }
    }

    /**
     * 获取生物群系表面方块
     */
    private function getBiomeSurfaceBlock(int $biomeId): int
    {
        switch ($biomeId) {
            case self::BIOME_OCEAN:
            case self::BIOME_RIVER:
                return self::SAND;
            case self::BIOME_DESERT:
                return self::SAND;
            case self::BIOME_ICE_PLAINS:
                return self::SNOW;
            case self::BIOME_HELL:
                return self::NETHERRACK;
            case self::BIOME_PLAINS:
            case self::BIOME_FOREST:
            case self::BIOME_TAIGA:
            case self::BIOME_SWAMP:
            case self::BIOME_BIRCH_FOREST:
            case self::BIOME_SMALL_MOUNTAINS:
            case self::BIOME_MOUNTAINS:
            default:
                return self::GRASS;
        }
    }

    /**
     * 获取生物群系颜色（用于地图渲染）
     */
    private function getBiomeColor(int $biomeId): int
    {
        switch ($biomeId) {
            case self::BIOME_OCEAN: return 0x0000ff;
            case self::BIOME_RIVER: return 0x0080ff;
            case self::BIOME_PLAINS: return 0x7fd760;
            case self::BIOME_DESERT: return 0xf7e58e;
            case self::BIOME_FOREST: return 0x4a8a3c;
            case self::BIOME_TAIGA: return 0x3a7a3c;
            case self::BIOME_SWAMP: return 0x5a7a3c;
            case self::BIOME_ICE_PLAINS: return 0xb0c8d0;
            case self::BIOME_MOUNTAINS: return 0x7a7a7a;
            case self::BIOME_SMALL_MOUNTAINS: return 0x8a8a8a;
            case self::BIOME_BIRCH_FOREST: return 0x6aa04a;
            case self::BIOME_HELL: return 0x5a1a1a;
            default: return 0x7fd760;
        }
    }

    // ========================================================================
    // 第二阶段：表面填充
    // ========================================================================

    /**
     * 表面填充：在地形生成完成后填充表面植被
     */
    private function populateSurface(array &$chunk, int $chunkX, int $chunkZ): void
    {
        $rand = new RandomCompat(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $this->seed);

        for ($x = 0; $x < self::CHUNK_SIZE; ++$x) {
            for ($z = 0; $z < self::CHUNK_SIZE; ++$z) {
                $biomeId = $this->getBiome($chunk, $x, $z);
                $surfaceY = $this->getHeightMap($chunk, $x, $z);

                if ($surfaceY <= 1) continue;

                $surfaceBlock = $this->getBlock($chunk, $x, $surfaceY, $z);

                // 根据生物群系添加植被
                switch ($biomeId) {
                    case self::BIOME_PLAINS:
                    case self::BIOME_FOREST:
                    case self::BIOME_TAIGA:
                    case self::BIOME_BIRCH_FOREST:
                        if ($surfaceBlock === self::GRASS && $rand->nextBoolean()) {
                            $this->setBlock($chunk, $x, $surfaceY + 1, $z, self::TALL_GRASS);
                        }
                        break;
                    case self::BIOME_DESERT:
                        if ($surfaceBlock === self::SAND && $rand->nextBoundedInt(3) === 0) {
                            $this->setBlock($chunk, $x, $surfaceY + 1, $z, self::DANDELION);
                        }
                        break;
                    case self::BIOME_SWAMP:
                        if ($surfaceBlock === self::GRASS && $rand->nextBoundedInt(2) === 0) {
                            $this->setBlock($chunk, $x, $surfaceY + 1, $z, self::REEDS);
                        }
                        break;
                    case self::BIOME_HELL:
                        // 地狱不添加植被
                        break;
                }
            }
        }

        $chunk['populated'] = true;
    }

    // ========================================================================
    // 公共 API
    // ========================================================================

    public function setSpawnPosition(int $x, int $y, int $z): void
    {
        $this->spawnX = $x;
        $this->spawnY = $y;
        $this->spawnZ = $z;
    }

    public function getSpawnPosition(): array
    {
        return [$this->spawnX, $this->spawnY, $this->spawnZ];
    }

    public function addPlayer(string $uuid, array $playerData): void
    {
        $this->players[$uuid] = $playerData;
    }

    public function removePlayer(string $uuid): void
    {
        unset($this->players[$uuid]);
    }

    public function getPlayers(): array
    {
        return array_values($this->players);
    }

    public function tick(): void
    {
        $this->time++;
    }

    public function getName(): string { return $this->name; }
    public function getPath(): string { return $this->path; }
    public function getFormat(): string { return $this->format; }
    public function getSeed(): int { return $this->seed; }
    public function getGenerator(): string { return $this->generatorName; }
    public function getTime(): int { return $this->time; }
    public function isAutoSave(): bool { return $this->autoSave; }
    public function setAutoSave(bool $autoSave): void { $this->autoSave = $autoSave; }

    /**
     * 保存所有已加载区块
     */
    public function saveChunks(): void
    {
        foreach ($this->chunks as $key => $chunk) {
            [$x, $z] = explode(':', $key);
            $this->writeChunkToDisk((int)$x, (int)$z, $chunk);
        }
        $this->saveLevelData();
    }

    /**
     * 关闭世界
     */
    public function close(): void
    {
        if ($this->autoSave) {
            $this->saveChunks();
        }
        foreach ($this->regionCache as $region) {
            $region->close();
        }
        $this->regionCache = [];
        if ($this->levelDB !== null) {
            @$this->levelDB->close();
            $this->levelDB = null;
        }
        $this->chunks = [];
        $this->players = [];
    }

    /**
     * 检查区块是否已生成
     */
    public function isChunkGenerated(int $x, int $z): bool
    {
        $key = $this->chunkHash($x, $z);
        return isset($this->chunks[$key]) && $this->chunks[$key]['generated'] === true;
    }

    /**
     * 检查区块是否已填充
     */
    public function isChunkPopulated(int $x, int $z): bool
    {
        $key = $this->chunkHash($x, $z);
        return isset($this->chunks[$key]) && $this->chunks[$key]['populated'] === true;
    }

    /**
     * 获取已加载区块数量
     */
    public function getLoadedChunkCount(): int
    {
        return count($this->chunks);
    }

    /**
     * 获取已加载区块列表
     */
    public function getLoadedChunks(): array
    {
        return array_values($this->chunks);
    }

    /**
     * 卸载区块
     */
    public function unloadChunk(int $x, int $z, bool $save = true): bool
    {
        $key = $this->chunkHash($x, $z);
        if (isset($this->chunks[$key])) {
            if ($save) {
                $this->writeChunkToDisk($x, $z, $this->chunks[$key]);
            }
            unset($this->chunks[$key]);
            return true;
        }
        return false;
    }

    /**
     * 卸载所有区块
     */
    public function unloadChunks(bool $save = true): void
    {
        foreach ($this->chunks as $key => $chunk) {
            [$x, $z] = explode(':', $key);
            if ($save) {
                $this->writeChunkToDisk((int)$x, (int)$z, $chunk);
            }
        }
        $this->chunks = [];
    }

    /**
     * 设置方块（世界坐标）
     */
    public function setBlockAt(int $x, int $y, int $z, int $blockId): bool
    {
        $chunkX = $x >> 4;
        $chunkZ = $z >> 4;
        $chunk = $this->getChunk($chunkX, $chunkZ);
        if ($chunk === null) return false;

        $localX = $x & 0x0f;
        $localY = $y & 0x7f;
        $localZ = $z & 0x0f;

        $oldId = $this->getBlock($chunk, $localX, $localY, $localZ);
        $this->setBlock($chunk, $localX, $localY, $localZ, $blockId);

        $key = $this->chunkHash($chunkX, $chunkZ);
        $this->chunks[$key] = $chunk;

        return $oldId !== $blockId;
    }

    /**
     * 获取方块（世界坐标）
     */
    public function getBlockAt(int $x, int $y, int $z): int
    {
        $chunkX = $x >> 4;
        $chunkZ = $z >> 4;
        $chunk = $this->getChunk($chunkX, $chunkZ);
        if ($chunk === null) return self::AIR;

        $localX = $x & 0x0f;
        $localY = $y & 0x7f;
        $localZ = $z & 0x0f;

        return $this->getBlock($chunk, $localX, $localY, $localZ);
    }
}