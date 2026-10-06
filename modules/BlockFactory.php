<?php
/**
 * Module: 方块运行时对象与原版注册工厂
 *
 * 对照原版 pocketmine/block/Block::get()：
 * VanillaData（tools/extract_vanilla.php 从原版源码提取）提供
 * ID→属性/名字数据，这里提供实例化与查询入口。
 */
declare(strict_types=1);

namespace Genisys\Module;

/**
 * 方块值对象（对照原版 Block 基类的只读部分）
 */
final class Block
{
    public function __construct(
        public int $id,
        public int $meta = 0,
        private ?array $info = null
    ) {
    }

    private function info(): array
    {
        if ($this->info === null) {
            $this->info = BlockFactory::info($this->id);
        }
        return $this->info;
    }

    public function getName(): string
    {
        return BlockFactory::nameFor($this->id, $this->meta);
    }

    public function getHardness(): float
    {
        return $this->info()['hardness'];
    }

    public function getToolType(): int
    {
        return $this->info()['tool'];
    }

    public function getLightLevel(): int
    {
        return $this->info()['light'];
    }

    public function getResistance(): float
    {
        return $this->info()['resistance'];
    }

    public function isSolid(): bool
    {
        return $this->info()['solid'];
    }

    public function isTransparent(): bool
    {
        return $this->info()['transparent'];
    }

    /**
     * 获取掉落物品 ID（对照原版 getDrops 的基础情形：自己掉自己）
     */
    public function getItemId(): int
    {
        return $this->id;
    }
}

/**
 * 原版方块注册工厂
 */
final class BlockFactory
{
    /** @var array<int, array>|null 缓存已解析属性 */
    private static ?array $infoCache = null;

    /** @var array<int, Block> 实例缓存（同 id+meta 复用） */
    private static array $instanceCache = [];

    public static function init(): void
    {
        self::infoCache();
    }

    private static function infoCache(): array
    {
        if (self::$infoCache === null) {
            self::$infoCache = [];
            foreach (VanillaData::BLOCKS as $id => $info) {
                self::$infoCache[(int)$id] = $info;
            }
        }
        return self::$infoCache;
    }

    /**
     * 获取方块（对照原版 Block::get）
     */
    public static function get(int $id, int $meta = 0): Block
    {
        $key = ($id << 4) | ($meta & 0x0F);
        if (!isset(self::$instanceCache[$key])) {
            self::$instanceCache[$key] = new Block($id, $meta & 0x0F, self::info($id));
        }
        return self::$instanceCache[$key];
    }

    /**
     * 获取某 ID 的属性数组；未注册的 ID 返回 UnknownBlock 属性（对照原版 UnknownBlock）
     */
    public static function info(int $id): array
    {
        $info = self::infoCache()[$id] ?? null;
        if ($info === null) {
            return [
                'name' => 'Unknown',
                'solid' => true,
                'transparent' => false,
                'hardness' => 0.0,
                'tool' => 0,
                'light' => 0,
                'resistance' => 0.0,
                'class' => 'UnknownBlock',
            ];
        }
        return $info;
    }

    /**
     * 名字查询（含 meta 变体名 + 掩码）
     */
    public static function nameFor(int $id, int $meta = 0): string
    {
        $metaNames = VanillaData::BLOCK_META_NAMES[$id] ?? null;
        if ($metaNames !== null) {
            $mask = $metaNames['mask'];
            $idx = $meta & $mask;
            if (isset($metaNames['names'][$idx])) {
                return $metaNames['names'][$idx];
            }
        }
        return self::info($id)['name'];
    }

    public static function isRegistered(int $id): bool
    {
        return isset(self::infoCache()[$id]);
    }

    public static function getBlockCount(): int
    {
        return count(self::infoCache());
    }
}
