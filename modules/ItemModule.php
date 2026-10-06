<?php

declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\BinaryStream;

/**
 * 物品基类
 */
abstract class Item {
    public const MAX_STACK_SIZE = 64;

    public function __construct(
        public int $id = 0,
        public int $meta = 0,
        public int $count = 1,
        public ?TagCompound $nbt = null,
        public string $customName = "",
        public array $lore = [],
        public array $enchants = []
    ) {}

    public function getId(): int { return $this->id; }
    public function getMeta(): int { return $this->meta; }
    public function getCount(): int { return $this->count; }
    public function getNbt(): ?TagCompound { return $this->nbt; }
    public function getCustomName(): string { return $this->customName; }
    public function getLore(): array { return $this->lore; }
    public function getEnchants(): array { return $this->enchants; }

    public function getName(): string { return $this->customName !== "" ? $this->customName : $this->getDefaultName(); }
    abstract protected function getDefaultName(): string;

    public function getMaxStackSize(): int { return self::MAX_STACK_SIZE; }
    public function getMaxDurability(): int { return 0; }
    public function isTool(): bool { return false; }
    public function isSword(): bool { return false; }
    public function isPickaxe(): bool { return false; }
    public function isAxe(): bool { return false; }
    public function isShovel(): bool { return false; }
    public function isHoe(): bool { return false; }
    public function isArmor(): bool { return false; }
    public function isFood(): bool { return false; }
    public function isBlock(): bool { return false; }
    public function getFuelTime(): int { return 0; }

    public function setCustomName(string $name): Item {
        return new static($this->id, $this->meta, $this->count, $this->nbt, $name, $this->lore, $this->enchants);
    }

    public function setLore(array $lore): Item {
        return new static($this->id, $this->meta, $this->count, $this->nbt, $this->customName, $lore, $this->enchants);
    }

    public function addEnchantment(Enchantment $enchant, int $level): Item {
        $newEnchants = $this->enchants;
        $newEnchants[$enchant->getId()] = $level;
        return new static($this->id, $this->meta, $this->count, $this->nbt, $this->customName, $this->lore, $newEnchants);
    }

    public function removeEnchantment(Enchantment $enchant): Item {
        $newEnchants = $this->enchants;
        unset($newEnchants[$enchant->getId()]);
        return new static($this->id, $this->meta, $this->count, $this->nbt, $this->customName, $this->lore, $newEnchants);
    }

    public function hasEnchantment(Enchantment $enchant): bool {
        return isset($this->enchants[$enchant->getId()]);
    }

    public function getEnchantmentLevel(Enchantment $enchant): int {
        return $this->enchants[$enchant->getId()] ?? 0;
    }

    public function clone(): Item {
        return new static($this->id, $this->meta, $this->count, $this->nbt, $this->customName, $this->lore, $this->enchants);
    }

    public function equals(mixed $other, bool $checkMeta = true, bool $checkNbt = true): bool {
        if (!$other instanceof Item) return false;
        if ($this->id !== $other->id) return false;
        if ($checkMeta && $this->meta !== $other->meta) return false;
        if ($checkNbt && (($this->nbt === null) !== ($other->nbt === null))) return false;
        if ($checkNbt && $this->nbt !== null && $other->nbt !== null && !$this->nbt->equals($other->nbt)) return false;
        return true;
    }

    public static function writeItem(BinaryStream $stream, Item $item): void {
        $stream->putShort($item->id);
        $stream->putByte($item->count);
        $stream->putShort($item->meta);
        if ($item->nbt !== null) {
            $stream->putByte(1);
            NBT::writeTag($item->nbt, $stream);
        } else {
            $stream->putByte(0);
        }
    }

    public static function readItem(BinaryStream $stream): Item {
        $id = $stream->getShort();
        $count = $stream->getByte();
        $meta = $stream->getShort();
        $hasNbt = $stream->getByte();
        $nbt = $hasNbt ? NBT::readTag($stream) : null;
        return ItemFactory::get($id, $meta, $count, $nbt instanceof TagCompound ? $nbt : null);
    }
}

/**
 * 物品工厂
 */
/**
 * 通用具体物品（注册表中未特化的 ID 的兜底，避免实例化抽象 Item）。
 * 名字按原版 VanillaData 表查询（含 meta 变体名）。
 */
final class ItemGeneric extends Item {
    protected function getDefaultName(): string {
        return ItemFactory::vanillaName($this->id, $this->meta) ?? "Unknown Item";
    }
}

/**
 * 通用方块物品兜底
 */
final class ItemBlockGeneric extends ItemBlock {
    protected function getDefaultName(): string {
        return ItemFactory::vanillaName($this->id, $this->meta) ?? "Unknown Block";
    }
}

final class ItemFactory {
    /** @var array<int, Item> */
    private static array $items = [];

    /** @var array<int, class-string<Item>> */
    private static array $customItems = [];

    /** @var bool 原版注册表是否已加载 */
    private static bool $vanillaLoaded = false;

    /** @var array<int, string>|null id => name 缓存 */
    private static ?array $nameCache = null;

    /** @var array<class-string, class-string> 原版物品类名 → php8 具体类映射 */
    private const CLASS_MAP = [
        'Shears' => ItemShears::class,
        'FishingRod' => ItemFishingRod::class,
        'FlintAndSteel' => ItemFlintAndSteel::class,
        'Bow' => ItemBow::class,
        'Shield' => ItemShield::class,
        'Elytra' => ItemElytra::class,
        'Skull' => ItemSkull::class,
        'Map' => ItemMap::class,
        'Banner' => ItemBanner::class,
        'Trident' => ItemTrident::class,
        'Arrow' => ItemArrow::class,
        'ExpBottle' => ItemExpBottle::class,
        'Firework' => ItemFirework::class,
    ];

    public static function init(): void {
        if (self::$vanillaLoaded) {
            return;
        }
        self::$vanillaLoaded = true;

        // 注册原版物品（对照原版 Item::init 的 $list）
        self::registerItem(0, Air::class);
        foreach (VanillaData::ITEMS as $id => $entry) {
            $id = (int)$id;
            if ($id === 0) {
                continue; // Air 已注册
            }
            $class = self::mapOriginalClass((string)$entry['class'], $id);
            if ($class !== null) {
                self::registerItem($id, $class);
            } elseif ($id < 256) {
                self::registerItem($id, ItemBlockGeneric::class);
            } else {
                self::registerItem($id, ItemGeneric::class);
            }
        }
    }

    /**
     * 原版物品类名 → php8 工具/防具类（构造签名兼容 ($id,$meta,$count,$nbt)）。
     * 无对应特化类时返回 null，由调用方落到 Generic。
     */
    private static function mapOriginalClass(string $origClass, int $id): ?string {
        foreach (self::CLASS_MAP as $suffix => $phpClass) {
            if (str_ends_with($origClass, $suffix)) {
                return $phpClass;
            }
        }
        if (str_ends_with($origClass, 'Sword')) return ItemSword::class;
        if (str_ends_with($origClass, 'Pickaxe')) return ItemPickaxe::class;
        if (str_ends_with($origClass, 'Axe') && !str_ends_with($origClass, 'Pickaxe')) return ItemAxe::class;
        if (str_ends_with($origClass, 'Shovel')) return ItemShovel::class;
        if (str_ends_with($origClass, 'Hoe')) return ItemHoe::class;
        if (str_ends_with($origClass, 'Helmet')) return ItemHelmet::class;
        if (str_ends_with($origClass, 'Chestplate')) return ItemChestplate::class;
        if (str_ends_with($origClass, 'Leggings')) return ItemLeggings::class;
        if (str_ends_with($origClass, 'Boots')) return ItemBoots::class;
        return null;
    }

    /**
     * 原版名字查询：meta 条件名 → 基础名 → 方块物品名（id<256）→ null
     */
    public static function vanillaName(int $id, int $meta = 0): ?string {
        if (self::$nameCache === null) {
            self::$nameCache = [];
            foreach (VanillaData::ITEMS as $vid => $entry) {
                self::$nameCache[(int)$vid] = (string)$entry['name'];
            }
        }
        $metaNames = VanillaData::ITEM_META_NAMES[$id] ?? null;
        if ($metaNames !== null && isset($metaNames[$meta])) {
            return $metaNames[$meta];
        }
        $name = self::$nameCache[$id] ?? null;
        if ($name !== null) {
            return $name;
        }
        // 方块物品（0-255）未在原版 Item::$list 里，名字取自方块注册表
        if ($id > 0 && $id < 256) {
            return BlockFactory::nameFor($id, $meta);
        }
        return null;
    }

    public static function registerItem(int $id, string $class): void {
        if (is_subclass_of($class, Item::class) === false && $class !== Item::class) {
            throw new \InvalidArgumentException("$class is not an Item subclass");
        }
        if ((new \ReflectionClass($class))->isAbstract()) {
            throw new \InvalidArgumentException("$class is abstract and cannot be registered");
        }
        self::$customItems[$id] = $class;
    }

    public static function registerCustomItem(int $id, int $meta, string $class): void {
        self::$customItems[$id * 4096 + $meta] = $class;
    }

    public static function get(int $id, int $meta = 0, int $count = 1, ?TagCompound $nbt = null): Item {
        if (!self::$vanillaLoaded) {
            self::init();
        }
        $key = $id * 4096 + $meta;
        $class = self::$customItems[$key] ?? self::$customItems[$id] ?? ItemGeneric::class;
        $item = new $class($id, $meta, $count, $nbt);
        // 对照原版：每个原版物品的名字来自注册表（特化类不再写死通用名）
        if ($item->customName === "") {
            $vanilla = self::vanillaName($id, $meta);
            if ($vanilla !== null) {
                $item->customName = $vanilla;
            }
        }
        return $item;
    }

    public static function isValidId(int $id): bool {
        if (!self::$vanillaLoaded) {
            self::init();
        }
        return isset(self::$customItems[$id]);
    }

    public static function getAllItems(): array {
        return self::$customItems;
    }
}

/**
 * 空气
 */
final class Air extends Item {
    protected function getDefaultName(): string { return "Air"; }
    public function getMaxStackSize(): int { return 0; }
}

/**
 * 方块物品基类
 */
abstract class ItemBlock extends Item {
    public function isBlock(): bool { return true; }
    public function getMaxStackSize(): int { return 64; }
}

/**
 * 多纹理方块物品
 */
final class ItemMultiTexture extends ItemBlock {
    private array $names;
    public function __construct(int $id, array $names, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
        $this->names = $names;
    }
    protected function getDefaultName(): string { return $this->names[$this->meta] ?? "Unknown"; }
}

/**
 * 工具基类
 */
abstract class ItemTool extends Item {
    protected int $maxDurability;
    protected float $efficiency;
    protected float $attackDamage;
    protected int $enchantability;
    protected int $repairItemId = 0;

    public function __construct(
        int $id, int $meta, int $count, ?TagCompound $nbt,
        int $maxDurability, float $efficiency, float $attackDamage, int $enchantability
    ) {
        parent::__construct($id, $meta, $count, $nbt);
        $this->maxDurability = $maxDurability;
        $this->efficiency = $efficiency;
        $this->attackDamage = $attackDamage;
        $this->enchantability = $enchantability;
    }

    public function getMaxDurability(): int { return $this->maxDurability; }
    public function getEfficiency(): float { return $this->efficiency; }
    public function getAttackDamage(): float { return $this->attackDamage; }
    public function getEnchantability(): int { return $this->enchantability; }
    public function isTool(): bool { return true; }
    public function getRepairItemId(): int { return $this->repairItemId; }
    public function setRepairItemId(int $id): void { $this->repairItemId = $id; }
    public function canHarvestBlock(Block $block): bool { return false; }
    public function getDestroySpeed(Block $block): float { return 1.0; }
}

/**
 * 剑
 */
final class ItemSword extends ItemTool {
    public function __construct(int $id = 267, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 1561, 4.0, 6.0, 14);
    }
    protected function getDefaultName(): string { return "Sword"; }
    public function isSword(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 镐
 */
final class ItemPickaxe extends ItemTool {
    public function __construct(int $id = 274, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 1561, 6.0, 2.0, 14);
    }
    protected function getDefaultName(): string { return "Pickaxe"; }
    public function isPickaxe(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
    public function canHarvestBlock(Block $block): bool { return $block->getHardness() >= 0; }
}

/**
 * 斧
 */
final class ItemAxe extends ItemTool {
    public function __construct(int $id = 275, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 1561, 8.0, 7.0, 14);
    }
    protected function getDefaultName(): string { return "Axe"; }
    public function isAxe(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 铲
 */
final class ItemShovel extends ItemTool {
    public function __construct(int $id = 269, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 1561, 6.0, 1.5, 14);
    }
    protected function getDefaultName(): string { return "Shovel"; }
    public function isShovel(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 锄
 */
final class ItemHoe extends ItemTool {
    public function __construct(int $id = 292, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 1561, 1.0, 0.0, 14);
    }
    protected function getDefaultName(): string { return "Hoe"; }
    public function isHoe(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 剪刀
 */
final class ItemShears extends ItemTool {
    public function __construct(int $id = 359, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 238, 1.0, 1.0, 14);
    }
    protected function getDefaultName(): string { return "Shears"; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 钓鱼竿
 */
final class ItemFishingRod extends ItemTool {
    public function __construct(int $id = 346, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 64, 1.0, 0.0, 14);
    }
    protected function getDefaultName(): string { return "Fishing Rod"; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 打火石
 */
final class ItemFlintAndSteel extends ItemTool {
    public function __construct(int $id = 259, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 64, 1.0, 0.0, 14);
    }
    protected function getDefaultName(): string { return "Flint and Steel"; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 盔甲基类
 */
abstract class ItemArmor extends Item {
    protected int $maxDurability;
    protected int $protection;
    protected int $enchantability;
    protected BlockFace $slot;
    protected int $repairItemId = 0;

    public function __construct(
        int $id, int $meta, int $count, ?TagCompound $nbt,
        int $maxDurability, int $protection, int $enchantability, BlockFace $slot
    ) {
        parent::__construct($id, $meta, $count, $nbt);
        $this->maxDurability = $maxDurability;
        $this->protection = $protection;
        $this->enchantability = $enchantability;
        $this->slot = $slot;
    }

    public function getMaxDurability(): int { return $this->maxDurability; }
    public function getProtection(): int { return $this->protection; }
    public function getEnchantability(): int { return $this->enchantability; }
    public function getSlot(): BlockFace { return $this->slot; }
    public function isArmor(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
    public function getRepairItemId(): int { return $this->repairItemId; }
}

/**
 * 头盔
 */
final class ItemHelmet extends ItemArmor {
    public function __construct(int $id = 298, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 160, 1, 5, BlockFace::UP);
    }
    protected function getDefaultName(): string { return "Helmet"; }
}

/**
 * 胸甲
 */
final class ItemChestplate extends ItemArmor {
    public function __construct(int $id = 299, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 240, 5, 5, BlockFace::UP);
    }
    protected function getDefaultName(): string { return "Chestplate"; }
}

/**
 * 护腿
 */
final class ItemLeggings extends ItemArmor {
    public function __construct(int $id = 300, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 200, 4, 5, BlockFace::UP);
    }
    protected function getDefaultName(): string { return "Leggings"; }
}

/**
 * 靴子
 */
final class ItemBoots extends ItemArmor {
    public function __construct(int $id = 301, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 130, 1, 5, BlockFace::UP);
    }
    protected function getDefaultName(): string { return "Boots"; }
}

/**
 * 食物基类
 */
final class ItemFood extends Item {
    private int $healAmount;
    private float $saturation;
    private bool $canEatWhenFull;
    private array $effects = [];

    public function __construct(
        int $id, int $meta, int $count, ?TagCompound $nbt,
        int $healAmount, float $saturation, bool $canEatWhenFull = false
    ) {
        parent::__construct($id, $meta, $count, $nbt);
        $this->healAmount = $healAmount;
        $this->saturation = $saturation;
        $this->canEatWhenFull = $canEatWhenFull;
    }

    public function getHealAmount(): int { return $this->healAmount; }
    public function getSaturation(): float { return $this->saturation; }
    public function canEatWhenFull(): bool { return $this->canEatWhenFull; }
    public function isFood(): bool { return true; }
    public function getMaxStackSize(): int { return 64; }
    protected function getDefaultName(): string { return "Food"; }

    public function addEffect(int $effectId, int $duration, int $amplifier, float $probability = 1.0): void {
        $this->effects[] = [$effectId, $duration, $amplifier, $probability];
    }

    public function getEffects(): array { return $this->effects; }
}

/**
 * 弓
 */
final class ItemBow extends ItemTool {
    public function __construct(int $id = 261, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 384, 1.0, 0.0, 1);
    }
    protected function getDefaultName(): string { return "Bow"; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 箭
 */
final class ItemArrow extends Item {
    public function __construct(int $id = 262, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Arrow"; }
    public function getMaxStackSize(): int { return 64; }
}

/**
 * 经验瓶
 */
final class ItemExpBottle extends Item {
    public function __construct(int $id = 384, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Exp Bottle"; }
    public function getMaxStackSize(): int { return 64; }
}

/**
 * 烟花火箭
 */
final class ItemFirework extends Item {
    public function __construct(int $id = 401, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Firework Rocket"; }
    public function getMaxStackSize(): int { return 64; }
}

/**
 * 烟花星
 */
final class ItemFireworkCharge extends Item {
    public function __construct(int $id = 402, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Firework Charge"; }
    public function getMaxStackSize(): int { return 64; }
}

/**
 * 地图
 */
final class ItemMap extends Item {
    public function __construct(int $id = 395, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Map"; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 头颅
 */
final class ItemSkull extends Item {
    public function __construct(int $id = 397, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Skull"; }
    public function getMaxStackSize(): int { return 16; }
}

/**
 * 旗帜
 */
final class ItemBanner extends Item {
    public function __construct(int $id = 425, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Banner"; }
    public function getMaxStackSize(): int { return 16; }
}

/**
 * 盾牌
 */
final class ItemShield extends Item {
    public function __construct(int $id = 442, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Shield"; }
    public function getMaxStackSize(): int { return 1; }
    public function isArmor(): bool { return true; }
}

/**
 * 飞行翼
 */
final class ItemElytra extends Item {
    public function __construct(int $id = 444, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Elytra"; }
    public function getMaxStackSize(): int { return 1; }
    public function isArmor(): bool { return true; }
    public function getSlot(): BlockFace { return BlockFace::UP; }
}

/**
 * 三叉戟
 */
final class ItemTrident extends ItemTool {
    public function __construct(int $id = 450, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt, 250, 1.0, 8.0, 14);
    }
    protected function getDefaultName(): string { return "Trident"; }
    public function isSword(): bool { return true; }
    public function getMaxStackSize(): int { return 1; }
}

/**
 * 门物品
 */
final class ItemDoor extends ItemBlock {
    public function __construct(int $id = 324, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Door"; }
}

/**
 * 种子
 */
final class ItemSeeds extends ItemBlock {
    public function __construct(int $id = 295, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Seeds"; }
}

/**
 * 台阶物品
 */
final class ItemSlab extends ItemBlock {
    public function __construct(int $id = 126, int $meta = 0, int $count = 1, ?TagCompound $nbt = null) {
        parent::__construct($id, $meta, $count, $nbt);
    }
    protected function getDefaultName(): string { return "Slab"; }
}

/**
 * 附魔基类
 */
abstract class Enchantment {
    public const RARITY_COMMON = 0;
    public const RARITY_UNCOMMON = 1;
    public const RARITY_RARE = 2;
    public const RARITY_VERY_RARE = 3;

    private int $id;
    private string $name;
    private int $maxLevel;
    private int $minLevel;
    private int $weight;
    private int $rarity;
    private bool $treasure = false;
    private bool $curse = false;
    /** @var int[] */
    private array $incompatible = [];

    public function __construct(
        int $id, string $name, int $maxLevel, int $minLevel,
        int $weight, int $rarity = self::RARITY_COMMON
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->maxLevel = $maxLevel;
        $this->minLevel = $minLevel;
        $this->weight = $weight;
        $this->rarity = $rarity;
    }

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getMaxLevel(): int { return $this->maxLevel; }
    public function getMinLevel(): int { return $this->minLevel; }
    public function getWeight(): int { return $this->weight; }
    public function getRarity(): int { return $this->rarity; }
    public function isTreasure(): bool { return $this->treasure; }
    public function setTreasure(bool $treasure): void { $this->treasure = $treasure; }
    public function isCurse(): bool { return $this->curse; }
    public function setCurse(bool $curse): void { $this->curse = $curse; }
    public function addIncompatible(int $enchantId): void { $this->incompatible[] = $enchantId; }
    public function isCompatibleWith(Enchantment $other): bool { return !in_array($other->getId(), $this->incompatible); }
    public function getMinEnchantability(int $level): int { return $this->minLevel + ($level - 1) * 11; }
    public function getMaxEnchantability(int $level): int { return $this->minLevel + ($level - 1) * 11 + 20; }
    abstract public function canEnchant(Item $item): bool;
    abstract public function getType(): int; // 0=armor, 1=weapon, 2=tool, 3=bow, 4=fishing, 5=trident
}

/**
 * 护甲附魔
 */
abstract class EnchantmentArmor extends Enchantment {
    public function canEnchant(Item $item): bool { return $item->isArmor() || $item instanceof ItemShield || $item instanceof ItemElytra; }
    public function getType(): int { return 0; }
}

/**
 * 武器附魔
 */
abstract class EnchantmentWeapon extends Enchantment {
    public function canEnchant(Item $item): bool { return $item->isSword() || $item instanceof ItemTrident; }
    public function getType(): int { return 1; }
}

/**
 * 弓附魔
 */
abstract class EnchantmentBow extends Enchantment {
    public function canEnchant(Item $item): bool { return $item instanceof ItemBow; }
    public function getType(): int { return 3; }
}

/**
 * 工具附魔
 */
abstract class EnchantmentTool extends Enchantment {
    public function canEnchant(Item $item): bool { return $item->isTool(); }
    public function getType(): int { return 2; }
}

/**
 * 钓鱼竿附魔
 */
abstract class EnchantmentFishingRod extends Enchantment {
    public function canEnchant(Item $item): bool { return $item instanceof ItemFishingRod; }
    public function getType(): int { return 4; }
}

/**
 * 三叉戟附魔
 */
abstract class EnchantmentTrident extends Enchantment {
    public function canEnchant(Item $item): bool { return $item instanceof ItemTrident; }
    public function getType(): int { return 5; }
}

/**
 * 具体附魔实现
 */
final class EnchantmentProtection extends EnchantmentArmor {
    public function __construct() { parent::__construct(0, "protection", 4, 1, 10); }
}
final class EnchantmentFireProtection extends EnchantmentArmor {
    public function __construct() { parent::__construct(1, "fire_protection", 4, 1, 5); }
}
final class EnchantmentFeatherFalling extends EnchantmentArmor {
    public function __construct() { parent::__construct(2, "feather_falling", 4, 1, 5); }
}
final class EnchantmentBlastProtection extends EnchantmentArmor {
    public function __construct() { parent::__construct(3, "blast_protection", 4, 1, 2); }
}
final class EnchantmentProjectileProtection extends EnchantmentArmor {
    public function __construct() { parent::__construct(4, "projectile_protection", 4, 1, 5); }
}
final class EnchantmentThorns extends EnchantmentArmor {
    public function __construct() { parent::__construct(5, "thorns", 3, 1, 1); }
}
final class EnchantmentRespiration extends EnchantmentArmor {
    public function __construct() { parent::__construct(6, "respiration", 3, 1, 2); }
}
final class EnchantmentDepthStrider extends EnchantmentArmor {
    public function __construct() { parent::__construct(7, "depth_strider", 3, 1, 2); }
}
final class EnchantmentAquaAffinity extends EnchantmentArmor {
    public function __construct() { parent::__construct(8, "aqua_affinity", 1, 1, 2); }
}
final class EnchantmentSharpness extends EnchantmentWeapon {
    public function __construct() { parent::__construct(9, "sharpness", 5, 1, 10); }
}
final class EnchantmentSmite extends EnchantmentWeapon {
    public function __construct() { parent::__construct(10, "smite", 5, 1, 5); }
}
final class EnchantmentBaneOfArthropods extends EnchantmentWeapon {
    public function __construct() { parent::__construct(11, "bane_of_arthropods", 5, 1, 5); }
}
final class EnchantmentKnockback extends EnchantmentWeapon {
    public function __construct() { parent::__construct(12, "knockback", 2, 1, 5); }
}
final class EnchantmentFireAspect extends EnchantmentWeapon {
    public function __construct() { parent::__construct(13, "fire_aspect", 2, 1, 2); }
}
final class EnchantmentLooting extends EnchantmentWeapon {
    public function __construct() { parent::__construct(14, "looting", 3, 1, 2); }
}
final class EnchantmentEfficiency extends EnchantmentTool {
    public function __construct() { parent::__construct(15, "efficiency", 5, 1, 10); }
}
final class EnchantmentSilkTouch extends EnchantmentTool {
    public function __construct() { parent::__construct(16, "silk_touch", 1, 1, 1); }
}
final class EnchantmentUnbreaking extends EnchantmentTool {
    public function __construct() { parent::__construct(17, "unbreaking", 3, 1, 5); }
}
final class EnchantmentFortune extends EnchantmentTool {
    public function __construct() { parent::__construct(18, "fortune", 3, 1, 2); }
}
final class EnchantmentPower extends EnchantmentBow {
    public function __construct() { parent::__construct(19, "power", 5, 1, 10); }
}
final class EnchantmentPunch extends EnchantmentBow {
    public function __construct() { parent::__construct(20, "punch", 2, 1, 5); }
}
final class EnchantmentFlame extends EnchantmentBow {
    public function __construct() { parent::__construct(21, "flame", 1, 1, 2); }
}
final class EnchantmentInfinity extends EnchantmentBow {
    public function __construct() { parent::__construct(22, "infinity", 1, 1, 1); }
}
final class EnchantmentLuckOfTheSea extends EnchantmentFishingRod {
    public function __construct() { parent::__construct(23, "luck_of_the_sea", 3, 1, 2); }
}
final class EnchantmentLure extends EnchantmentFishingRod {
    public function __construct() { parent::__construct(24, "lure", 3, 1, 2); }
}
final class EnchantmentLoyalty extends EnchantmentTrident {
    public function __construct() { parent::__construct(25, "loyalty", 3, 1, 5); }
}
final class EnchantmentImpaling extends EnchantmentTrident {
    public function __construct() { parent::__construct(26, "impaling", 5, 1, 2); }
}
final class EnchantmentRiptide extends EnchantmentTrident {
    public function __construct() { parent::__construct(27, "riptide", 3, 1, 2); }
}
final class EnchantmentChanneling extends EnchantmentTrident {
    public function __construct() { parent::__construct(28, "channeling", 1, 1, 1); }
}
final class EnchantmentMending extends Enchantment {
    public function __construct() { parent::__construct(29, "mending", 1, 1, 2); }
    public function canEnchant(Item $item): bool { return $item->getMaxDurability() > 0; }
    public function getType(): int { return 2; }
}
final class EnchantmentVanishingCurse extends Enchantment {
    public function __construct() { parent::__construct(30, "vanishing_curse", 1, 1, 1); $this->setCurse(true); }
    public function canEnchant(Item $item): bool { return true; }
    public function getType(): int { return 2; }
}
final class EnchantmentBindingCurse extends Enchantment {
    public function __construct() { parent::__construct(31, "binding_curse", 1, 1, 1); $this->setCurse(true); }
    public function canEnchant(Item $item): bool { return $item->isArmor(); }
    public function getType(): int { return 0; }
}

/**
 * 附魔注册表
 */
final class EnchantmentRegistry {
    /** @var array<int, Enchantment> */
    private static array $enchantments = [];

    public static function init(): void {
        self::$enchantments = [
            0 => new EnchantmentProtection(),
            1 => new EnchantmentFireProtection(),
            2 => new EnchantmentFeatherFalling(),
            3 => new EnchantmentBlastProtection(),
            4 => new EnchantmentProjectileProtection(),
            5 => new EnchantmentThorns(),
            6 => new EnchantmentRespiration(),
            7 => new EnchantmentDepthStrider(),
            8 => new EnchantmentAquaAffinity(),
            9 => new EnchantmentSharpness(),
            10 => new EnchantmentSmite(),
            11 => new EnchantmentBaneOfArthropods(),
            12 => new EnchantmentKnockback(),
            13 => new EnchantmentFireAspect(),
            14 => new EnchantmentLooting(),
            15 => new EnchantmentEfficiency(),
            16 => new EnchantmentSilkTouch(),
            17 => new EnchantmentUnbreaking(),
            18 => new EnchantmentFortune(),
            19 => new EnchantmentPower(),
            20 => new EnchantmentPunch(),
            21 => new EnchantmentFlame(),
            22 => new EnchantmentInfinity(),
            23 => new EnchantmentLuckOfTheSea(),
            24 => new EnchantmentLure(),
            25 => new EnchantmentLoyalty(),
            26 => new EnchantmentImpaling(),
            27 => new EnchantmentRiptide(),
            28 => new EnchantmentChanneling(),
            29 => new EnchantmentMending(),
            30 => new EnchantmentVanishingCurse(),
            31 => new EnchantmentBindingCurse(),
        ];
    }

    public static function get(int $id): ?Enchantment {
        return self::$enchantments[$id] ?? null;
    }

    public static function getByName(string $name): ?Enchantment {
        foreach (self::$enchantments as $ench) {
            if ($ench->getName() === $name) return $ench;
        }
        return null;
    }

    public static function getAll(): array { return self::$enchantments; }
}

/**
 * 物品模块入口
 */
final class ItemModule {
    public const VERSION = "1.0.0";
    public function getName(): string { return "ItemModule"; }
    public function getVersion(): string { return self::VERSION; }

    public static function init(): void {
        ItemFactory::init();
        EnchantmentRegistry::init();
    }
}