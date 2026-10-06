<?php
/**
 * Module: 世界存储 Provider 层
 *
 * 对照原版 pocketmine/level/format/io/：
 * - LevelDat        ← BaseLevelProvider 的 level.dat 读写
 *   （gzip 压缩的大端 NBT，root "" 含 "Data" compound）
 * - AnvilProvider   ← region/Anvil.php（.mca，Sections 16³ 列表，zlib）
 * - McRegionProvider← region/McRegion.php（.mcr，整块 32768 字节，zlib）
 *
 * 内存 chunk 结构（LevelModule）与磁盘排布的关系：
 *   flat 索引 = y*256 + z*16 + x，Anvil section 内索引 = (y&15)*256 + z*16 + x
 *   → 按 sectionY*4096 直接切片即可，无需坐标重排。
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\BinaryStream;

/**
 * level.dat 读写（对照原版 BaseLevelProvider::loadLevel/saveLevelData）
 */
final class LevelDat
{
    private string $path;

    public function __construct(string $levelPath)
    {
        $this->path = rtrim($levelPath, '/') . '/level.dat';
    }

    /**
     * LevelDB 世界的 level.dat：8 字节头（version LE int + 长度 LE int）
     * + 未压缩的小端 NBT（对照原版 leveldb/LevelDB.php generate/saveLevelData）
     */
    public function loadLeveldb(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || strlen($raw) < 9) {
            return null;
        }
        $version = unpack('V', substr($raw, 0, 4))[1];
        $length = unpack('V', substr($raw, 4, 4))[1];
        if ($version !== 3) {
            return null; // 0.14.3 固定写 version=3
        }
        $stream = new BinaryStream(substr($raw, 8, $length));
        $root = NBT::readTag($stream, NBT::LITTLE_ENDIAN);
        if (!$root instanceof TagCompound) {
            return null;
        }
        $out = [];
        foreach ($root->getTags() as $tag) {
            $out[$tag->getName()] = $this->tagToValue($tag);
        }
        return $out;
    }

    public function saveLeveldb(array $levelData): void
    {
        $compound = new TagCompound('');
        foreach ($levelData as $key => $value) {
            $compound->set($this->valueToTag((string)$key, $value));
        }
        $stream = new BinaryStream();
        NBT::writeTag($compound, $stream, NBT::LITTLE_ENDIAN);
        $buffer = $stream->getBuffer();
        file_put_contents($this->path, pack('V', 3) . pack('V', strlen($buffer)) . $buffer);
    }

    /**
     * 读取 level.dat 为键值数组；文件不存在或损坏返回 null
     */
    public function load(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = zlib_decode($raw);
        if ($decoded === false || $decoded === null || $decoded === '') {
            return null;
        }
        $stream = new BinaryStream($decoded);
        $root = NBT::readTag($stream, NBT::BIG_ENDIAN);
        if (!$root instanceof TagCompound) {
            return null;
        }
        $data = $root->getTag('Data');
        if (!$data instanceof TagCompound) {
            return null;
        }

        $out = [];
        foreach ($data->getTags() as $tag) {
            $out[$tag->getName()] = $this->tagToValue($tag);
        }
        return $out;
    }

    private function tagToValue(Tag $tag): mixed
    {
        return match (true) {
            $tag instanceof TagCompound => array_map([$this, 'tagToValue'], $tag->getTags()),
            $tag instanceof TagList => array_map([$this, 'tagToValue'], $tag->getValue()),
            $tag instanceof TagByteArray => $tag->getValue(),
            default => $tag->getValue(),
        };
    }

    /**
     * 保存键值数组为 level.dat（对照原版：root "" → Data compound）
     */
    public function save(array $levelData): void
    {
        $data = new TagCompound('Data');
        foreach ($levelData as $key => $value) {
            $data->set($this->valueToTag((string)$key, $value));
        }
        $root = new TagCompound('', ['Data' => $data]);

        $stream = new BinaryStream();
        NBT::writeTag($root, $stream, NBT::BIG_ENDIAN);
        $compressed = zlib_encode($stream->getBuffer(), ZLIB_ENCODING_GZIP, 7);
        if ($compressed === false) {
            throw new \RuntimeException("Failed to compress level.dat");
        }
        file_put_contents($this->path, $compressed);
    }

    private function valueToTag(string $name, mixed $value): Tag
    {
        if (is_array($value)) {
            if (array_is_list($value) && $value !== [] && is_int($value[0]) && count($value) === 256) {
                // 高度图之类的 256 长度 int 数组按 IntArray 存（对照原版 HeightMap）
                return new TagIntArray($name, $value);
            }
            $tags = [];
            foreach ($value as $k => $v) {
                $tags[] = $this->valueToTag((string)$k, $v);
            }
            if ($tags !== []) {
                return new TagList($name, $tags[0]->getType(), $tags);
            }
            return new TagList($name, NBT::TAG_END, []);
        }
        return NBT::create($name, $value);
    }
}

/**
 * Anvil 格式 Provider（对照原版 region/Anvil.php nbtSerialize/nbtDeserialize）
 */
final class AnvilProvider
{
    public const REGION_FILE_EXTENSION = 'mca';

    /**
     * chunk 数组 → Anvil NBT 原始字节（未压缩）
     */
    public static function serializeChunk(array $chunk): string
    {
        $level = new TagCompound('Level', [
            'xPos'             => new TagInt('xPos', (int)$chunk['x']),
            'zPos'             => new TagInt('zPos', (int)$chunk['z']),
            'V'                => new TagByte('V', 1),
            'LastUpdate'       => new TagLong('LastUpdate', (int)($chunk['lastUpdate'] ?? 0)),
            'InhabitedTime'    => new TagLong('InhabitedTime', 0),
            'TerrainPopulated' => new TagByte('TerrainPopulated', !empty($chunk['populated']) ? 1 : 0),
            'LightPopulated'   => new TagByte('LightPopulated', 1),
        ]);

        // Sections：只写非空 section（对照原版：空 section 省略）
        $blocks = $chunk['blocks']; // 32768 ints
        $sections = [];
        for ($sy = 0; $sy < 8; ++$sy) {
            $slice = array_slice($blocks, $sy * 4096, 4096);
            if (max($slice) === 0) {
                continue; // 空 section 省略（读取端视为全空气）
            }
            $sections[] = new TagCompound('', [
                'Y'          => new TagByte('Y', $sy),
                'Blocks'     => new TagByteArray('Blocks', pack('C*', ...$slice)),
                'Data'       => new TagByteArray('Data', str_repeat("\x00", 2048)),
                'SkyLight'   => new TagByteArray('SkyLight', str_repeat("\xff", 2048)),
                'BlockLight' => new TagByteArray('BlockLight', str_repeat("\x00", 2048)),
            ]);
        }
        $level->set(new TagList('Sections', NBT::TAG_COMPOUND, $sections));

        $level->set(new TagByteArray('Biomes', pack('C*', ...array_values($chunk['biomes']))));
        $level->set(new TagIntArray('HeightMap', array_values($chunk['heightMap'])));
        $level->set(new TagList('Entities', NBT::TAG_COMPOUND, []));
        $level->set(new TagList('TileEntities', NBT::TAG_COMPOUND, []));

        $root = new TagCompound('', ['Level' => $level]);
        $stream = new BinaryStream();
        NBT::writeTag($root, $stream, NBT::BIG_ENDIAN);
        return $stream->getBuffer();
    }

    /**
     * Anvil NBT 原始字节 → chunk 数组；格式不符返回 null
     */
    public static function deserializeChunk(string $nbtPayload): ?array
    {
        $stream = new BinaryStream($nbtPayload);
        $root = NBT::readTag($stream, NBT::BIG_ENDIAN);
        if (!$root instanceof TagCompound) {
            return null;
        }
        $level = $root->getTag('Level');
        if (!$level instanceof TagCompound) {
            return null;
        }

        $blocks = array_fill(0, 32768, 0);
        $sections = $level->getTag('Sections');
        if ($sections instanceof TagList) {
            foreach ($sections->getValue() as $sectionTag) {
                if (!$sectionTag instanceof TagCompound) {
                    continue;
                }
                $sy = $sectionTag->getTag('Y')?->getValue() ?? 0;
                $blocksTag = $sectionTag->getTag('Blocks');
                if ($sy < 0 || $sy > 7 || !$blocksTag instanceof TagByteArray) {
                    continue;
                }
                $bytes = $blocksTag->getValue();
                for ($i = 0; $i < 4096; ++$i) {
                    $blocks[$sy * 4096 + $i] = ord($bytes[$i]);
                }
            }
        }

        $x = $level->getTag('xPos')?->getValue() ?? 0;
        $z = $level->getTag('zPos')?->getValue() ?? 0;

        $biomes = array_fill(0, 256, 0);
        $biomesTag = $level->getTag('Biomes');
        if ($biomesTag instanceof TagByteArray) {
            $bytes = $biomesTag->getValue();
            for ($i = 0; $i < min(256, strlen($bytes)); ++$i) {
                $biomes[$i] = ord($bytes[$i]);
            }
        }

        $heightMap = array_fill(0, 256, 0);
        $hmTag = $level->getTag('HeightMap');
        if ($hmTag instanceof TagIntArray) {
            foreach ($hmTag->getValue() as $i => $h) {
                if ($i < 256) {
                    $heightMap[$i] = (int)$h;
                }
            }
        }

        return [
            'x'          => (int)$x,
            'z'          => (int)$z,
            'blocks'     => $blocks,
            'biomes'     => $biomes,
            'heightMap'  => $heightMap,
            'biomeColors' => array_fill(0, 256, 0),
            'entities'   => [],
            'tiles'      => [],
            'generated'  => true,
            'populated'  => (bool)($level->getTag('TerrainPopulated')?->getValue() ?? 1),
        ];
    }
}

/**
 * McRegion 格式 Provider（对照原版 region/McRegion.php）
 */
final class McRegionProvider
{
    public const REGION_FILE_EXTENSION = 'mcr';

    public static function serializeChunk(array $chunk): string
    {
        $level = new TagCompound('Level', [
            'xPos'             => new TagInt('xPos', (int)$chunk['x']),
            'zPos'             => new TagInt('zPos', (int)$chunk['z']),
            'LastUpdate'       => new TagLong('LastUpdate', (int)($chunk['lastUpdate'] ?? 0)),
            'TerrainPopulated' => new TagByte('TerrainPopulated', !empty($chunk['populated']) ? 1 : 0),
            // McRegion：整块 32768 字节（YZX），nibble 数组 16384 字节
            'Blocks'           => new TagByteArray('Blocks', pack('C*', ...array_values($chunk['blocks']))),
            'Data'             => new TagByteArray('Data', str_repeat("\x00", 16384)),
            'SkyLight'         => new TagByteArray('SkyLight', str_repeat("\xff", 16384)),
            'BlockLight'       => new TagByteArray('BlockLight', str_repeat("\x00", 16384)),
            'Biomes'           => new TagByteArray('Biomes', pack('C*', ...array_values($chunk['biomes']))),
            'HeightMap'        => new TagIntArray('HeightMap', array_values($chunk['heightMap'])),
            'Entities'         => new TagList('Entities', NBT::TAG_COMPOUND, []),
            'TileEntities'     => new TagList('TileEntities', NBT::TAG_COMPOUND, []),
        ]);

        $root = new TagCompound('', ['Level' => $level]);
        $stream = new BinaryStream();
        NBT::writeTag($root, $stream, NBT::BIG_ENDIAN);
        return $stream->getBuffer();
    }

    public static function deserializeChunk(string $nbtPayload): ?array
    {
        $stream = new BinaryStream($nbtPayload);
        $root = NBT::readTag($stream, NBT::BIG_ENDIAN);
        if (!$root instanceof TagCompound) {
            return null;
        }
        $level = $root->getTag('Level');
        if (!$level instanceof TagCompound) {
            return null;
        }

        $blocks = array_fill(0, 32768, 0);
        $blocksTag = $level->getTag('Blocks');
        if ($blocksTag instanceof TagByteArray) {
            $bytes = $blocksTag->getValue();
            for ($i = 0; $i < min(32768, strlen($bytes)); ++$i) {
                $blocks[$i] = ord($bytes[$i]);
            }
        }

        $biomes = array_fill(0, 256, 0);
        $biomesTag = $level->getTag('Biomes');
        if ($biomesTag instanceof TagByteArray) {
            $bytes = $biomesTag->getValue();
            for ($i = 0; $i < min(256, strlen($bytes)); ++$i) {
                $biomes[$i] = ord($bytes[$i]);
            }
        }

        $heightMap = array_fill(0, 256, 0);
        $hmTag = $level->getTag('HeightMap');
        if ($hmTag instanceof TagIntArray) {
            foreach ($hmTag->getValue() as $i => $h) {
                if ($i < 256) {
                    $heightMap[$i] = (int)$h;
                }
            }
        }

        return [
            'x'          => (int)($level->getTag('xPos')?->getValue() ?? 0),
            'z'          => (int)($level->getTag('zPos')?->getValue() ?? 0),
            'blocks'     => $blocks,
            'biomes'     => $biomes,
            'heightMap'  => $heightMap,
            'biomeColors' => array_fill(0, 256, 0),
            'entities'   => [],
            'tiles'      => [],
            'generated'  => true,
            'populated'  => (bool)($level->getTag('TerrainPopulated')?->getValue() ?? 1),
        ];
    }
}

/**
 * LevelDB 格式 Provider（对照原版 leveldb/LevelDB.php + leveldb/Chunk.php）
 *
 * 键结构：chunkIndex = LE int32 X + LE int32 Z（8 字节），条目后缀：
 *   "0" 地形 blob（69632 字节）、"f" 标志位（generated|populated|lightPopulated）、
 *   "v" 版本（"\x02"）、"1" 方块实体、"2" 实体、"3" ticks、"4" extraData。
 * level.dat：8 字节头（version=3 LE + 长度 LE）+ 未压缩小端 NBT。
 *
 * 依赖 ext-leveldb（\LevelDB 类）；扩展缺失时抛出带安装提示的异常。
 */
final class LevelDBProvider
{
    public const ENTRY_TERRAIN = '0';
    public const ENTRY_FLAGS = 'f';
    public const ENTRY_VERSION = 'v';
    public const FLAG_GENERATED = 0x01;
    public const FLAG_POPULATED = 0x02;
    public const FLAG_LIGHT_POPULATED = 0x04;

    /** 地形 blob 长度：32768 + 16384×3 + 256 + 256×4 */
    public const TERRAIN_SIZE = 83200;

    /**
     * 打开（或创建）数据库句柄
     * @return \LevelDB
     */
    public static function openDatabase(string $levelPath)
    {
        if (!class_exists('\LevelDB')) {
            throw new \RuntimeException(
                'ext-leveldb 未安装：pecl/git 安装 php-leveldb（reeze/php-leveldb）后重启 PHP'
            );
        }
        $dbPath = rtrim($levelPath, '/') . '/db';
        if (!is_dir($dbPath)) {
            mkdir($dbPath, 0777, true);
        }
        // 注：原版 Genisys 使用 MCPE 私有 leveldb fork 的 ZLIB 压缩；标准 leveldb 库
        // 不支持该压缩类型，这里用扩展默认压缩（仅影响写侧编码，键结构不变）。
        return new \LevelDB($dbPath);
    }

    public static function chunkIndex(int $x, int $z): string
    {
        return pack('V', $x) . pack('V', $z);
    }

    /**
     * chunk 数组 → 地形 blob（69632 字节；内存 flat 数组 YZX 与磁盘一致）
     */
    public static function serializeChunk(array $chunk): string
    {
        return pack('C*', ...array_values($chunk['blocks']))
            . str_repeat("\x00", 16384)   // meta
            . str_repeat("\xff", 16384)   // skyLight
            . str_repeat("\x00", 16384)   // blockLight
            . pack('C*', ...array_pad(array_values($chunk['heightMap']), 256, 0))
            . pack('N*', ...array_pad(array_values($chunk['biomeColors']), 256, 0));
    }

    /**
     * 地形 blob + 标志位 → chunk 数组
     */
    public static function deserializeChunk(string $terrain, int $flags, int $x, int $z): ?array
    {
        if (strlen($terrain) < self::TERRAIN_SIZE) {
            return null;
        }
        $blocks = [];
        for ($i = 0; $i < 32768; ++$i) {
            $blocks[$i] = ord($terrain[$i]);
        }
        $biomes = array_fill(0, 256, 0);
        $heightMap = [];
        $off = 32768 + 16384 * 3;
        for ($i = 0; $i < 256; ++$i) {
            $heightMap[$i] = ord($terrain[$off + $i]);
        }
        $biomeColors = [];
        $off += 256;
        for ($i = 0; $i < 256; ++$i) {
            $v = unpack('N', substr($terrain, $off + $i * 4, 4))[1];
            $biomeColors[$i] = $v >= 0x80000000 ? $v - 0x100000000 : $v;
        }

        return [
            'x'          => $x,
            'z'          => $z,
            'blocks'     => $blocks,
            'biomes'     => $biomes,
            'heightMap'  => $heightMap,
            'biomeColors' => $biomeColors,
            'entities'   => [],
            'tiles'      => [],
            'generated'  => ($flags & self::FLAG_GENERATED) !== 0,
            'populated'  => ($flags & self::FLAG_POPULATED) !== 0,
        ];
    }
}
