<?php
/**
 * Module: BlockModule
 * 职责：方块/放置/挖掘/更新/事件/实体数据（替代 Block.php）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：保持位运算逻辑，优化数据结构
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\RuntimeCompat;

class BlockModule
{
    // 网络数据包 ID
    public const PKT_PLACE_BLOCK      = 0x95;
    public const PKT_REMOVE_BLOCK     = 0x96;
    public const PKT_UPDATE_BLOCK     = 0x97;
    public const PKT_BLOCK_EVENT      = 0x1A;
    public const PKT_BLOCK_ENTITY_DATA = 0x38;

    // 方块 ID 常量（与原始 Block.php 对齐的子集）
    public const AIR          = 0;
    public const STONE        = 1;
    public const GRASS        = 2;
    public const DIRT         = 3;
    public const COBBLESTONE  = 4;
    public const PLANKS       = 5;
    public const SAPLING      = 6;
    public const BEDROCK      = 7;
    public const WATER        = 8;
    public const STILL_WATER  = 9;
    public const LAVA         = 10;
    public const STILL_LAVA   = 11;
    public const SAND         = 12;
    public const GRAVEL       = 13;
    public const GOLD_ORE     = 14;
    public const IRON_ORE     = 15;
    public const COAL_ORE     = 16;
    public const WOOD         = 17;
    public const LEAVES       = 18;
    public const SPONGE       = 19;
    public const GLASS        = 20;
    public const LAPIS_ORE    = 21;
    public const LAPIS_BLOCK  = 22;
    public const SANDSTONE    = 24;
    public const DISPENSER    = 23;
    public const NOTEBLOCK    = 25;
    public const BED_BLOCK    = 26;
    public const POWERED_RAIL = 27;
    public const DETECTOR_RAIL = 28;
    public const COBWEB       = 30;
    public const TALL_GRASS   = 31;
    public const DEAD_BUSH    = 32;
    public const WOOL         = 35;
    public const DANDELION    = 37;
    public const RED_FLOWER   = 38;
    public const BROWN_MUSHROOM = 39;
    public const RED_MUSHROOM   = 40;
    public const GOLD_BLOCK    = 41;
    public const IRON_BLOCK    = 42;
    public const DOUBLE_SLAB   = 43;
    public const SLAB          = 44;
    public const BRICKS        = 45;
    public const TNT           = 46;
    public const BOOKSHELF     = 47;
    public const MOSS_STONE    = 48;
    public const OBSIDIAN      = 49;
    public const TORCH         = 50;
    public const FIRE          = 51;
    public const MONSTER_SPAWNER = 52;
    public const WOOD_STAIRS   = 53;
    public const CHEST         = 54;
    public const REDSTONE_WIRE = 55;
    public const DIAMOND_ORE   = 56;
    public const DIAMOND_BLOCK = 57;
    public const CRAFTING_TABLE = 58;
    public const WORKBENCH     = 58;
    public const WHEAT_BLOCK   = 59;
    public const FARMLAND      = 60;
    public const FURNACE       = 61;
    public const BURNING_FURNACE = 62;
    public const LIT_FURNACE   = 62;
    public const SIGN_POST     = 63;
    public const DOOR_BLOCK    = 64;
    public const WOODEN_DOOR_BLOCK = 64;
    public const LADDER        = 65;
    public const COBBLE_STAIRS = 67;
    public const WALL_SIGN     = 68;
    public const IRON_DOOR_BLOCK = 71;
    public const LEVER         = 69;
    public const STONE_PRESSURE_PLATE = 70;
    public const WOODEN_PRESSURE_PLATE = 72;
    public const REDSTONE_ORE  = 73;
    public const GLOWING_REDSTONE_ORE = 74;
    public const LIT_REDSTONE_ORE = 74;
    public const UNLIT_REDSTONE_TORCH = 75;
    public const REDSTONE_TORCH = 76;
    public const STONE_BUTTON  = 77;
    public const SNOW          = 78;
    public const SNOW_LAYER    = 78;
    public const ICE           = 79;
    public const SNOW_BLOCK    = 80;
    public const CACTUS        = 81;
    public const CLAY_BLOCK    = 82;
    public const REEDS         = 83;
    public const SUGARCANE_BLOCK = 83;
    public const FENCE         = 85;
    public const PUMPKIN       = 86;
    public const NETHERRACK    = 87;
    public const SOUL_SAND     = 88;
    public const GLOWSTONE     = 89;
    public const GLOWSTONE_BLOCK = 89;
    public const PORTAL        = 90;
    public const LIT_PUMPKIN   = 91;
    public const JACK_O_LANTERN = 91;
    public const CAKE_BLOCK    = 92;
    public const UNPOWERED_REPEATER = 93;
    public const POWERED_REPEATER   = 94;
    public const TRAPDOOR      = 96;
    public const WOODEN_TRAPDOOR = 96;
    public const MONSTER_EGG_BLOCK = 97;
    public const STONE_BRICKS  = 98;
    public const STONE_BRICK   = 98;
    public const BROWN_MUSHROOM_BLOCK = 99;
    public const RED_MUSHROOM_BLOCK   = 100;
    public const IRON_BAR      = 101;
    public const IRON_BARS     = 101;
    public const GLASS_PANE    = 102;
    public const GLASS_PANEL   = 102;
    public const MELON_BLOCK   = 103;
    public const PUMPKIN_STEM  = 104;
    public const MELON_STEM    = 105;
    public const VINE          = 106;
    public const VINES         = 106;
    public const FENCE_GATE    = 107;
    public const BRICK_STAIRS  = 108;
    public const STONE_BRICK_STAIRS = 109;
    public const MYCELIUM      = 110;
    public const WATER_LILY    = 111;
    public const LILY_PAD      = 111;
    public const NETHER_BRICKS = 112;
    public const NETHER_BRICK_BLOCK = 112;
    public const NETHER_BRICK_FENCE = 113;
    public const NETHER_BRICKS_STAIRS = 114;
    public const NETHER_WART_BLOCK = 115;
    public const ENCHANTING_TABLE = 116;
    public const ENCHANT_TABLE = 116;
    public const ENCHANTMENT_TABLE = 116;
    public const BREWING_STAND_BLOCK = 117;
    public const CAULDRON_BLOCK = 118;
    public const END_PORTAL_FRAME = 120;
    public const END_STONE     = 121;
    public const INACTIVE_REDSTONE_LAMP = 123;
    public const ACTIVE_REDSTONE_LAMP = 124;
    public const DROPPER       = 125;
    public const ACTIVATOR_RAIL = 126;
    public const COCOA_BLOCK   = 127;
    public const COCOA_PODS    = 127;
    public const SANDSTONE_STAIRS = 128;
    public const EMERALD_ORE   = 129;
    public const TRIPWIRE_HOOK = 131;
    public const TRIPWIRE      = 132;
    public const EMERALD_BLOCK = 133;
    public const SPRUCE_WOOD_STAIRS = 134;
    public const SPRUCE_WOODEN_STAIRS = 134;
    public const BIRCH_WOOD_STAIRS = 135;
    public const BIRCH_WOODEN_STAIRS = 135;
    public const JUNGLE_WOOD_STAIRS = 136;
    public const JUNGLE_WOODEN_STAIRS = 136;
    public const COBBLE_WALL   = 139;
    public const STONE_WALL    = 139;
    public const COBBLESTONE_WALL = 139;
    public const FLOWER_POT_BLOCK = 140;
    public const CARROT_BLOCK  = 141;
    public const POTATO_BLOCK  = 142;
    public const WOODEN_BUTTON = 143;
    public const SKULL_BLOCK   = 144;
    public const ANVIL         = 145;
    public const TRAPPED_CHEST = 146;
    public const LIGHT_WEIGHTED_PRESSURE_PLATE = 147;
    public const HEAVY_WEIGHTED_PRESSURE_PLATE = 148;
    public const DAYLIGHT_SENSOR = 151;
    public const DAYLIGHT_SENSOR_INVERTED = 178;
    public const REDSTONE_BLOCK = 152;
    public const NETHER_QUARTZ_ORE = 153;
    public const QUARTZ_BLOCK   = 155;
    public const QUARTZ_STAIRS  = 156;
    public const DOUBLE_WOOD_SLAB = 157;
    public const DOUBLE_WOODEN_SLAB = 157;
    public const DOUBLE_WOOD_SLABS = 157;
    public const DOUBLE_WOODEN_SLABS = 157;
    public const WOOD_SLAB      = 158;
    public const WOODEN_SLAB   = 158;
    public const WOOD_SLABS    = 158;
    public const WOODEN_SLABS  = 158;
    public const STAINED_CLAY  = 159;
    public const STAINED_HARDENED_CLAY = 159;
    public const LEAVES2       = 161;
    public const LEAVE2        = 161;
    public const WOOD2         = 162;
    public const TRUNK2        = 162;
    public const LOG2          = 162;
    public const ACACIA_WOOD_STAIRS = 163;
    public const ACACIA_WOODEN_STAIRS = 163;
    public const DARK_OAK_WOOD_STAIRS = 164;
    public const DARK_OAK_WOODEN_STAIRS = 164;
    public const SLIME_BLOCK   = 165;
    public const IRON_TRAPDOOR = 167;
    public const HAY_BALE      = 170;
    public const CARPET        = 171;
    public const HARDENED_CLAY = 172;
    public const COAL_BLOCK    = 173;
    public const PACKED_ICE    = 174;
    public const DOUBLE_PLANT  = 175;
    public const RED_SANDSTONE = 179;
    public const RED_SANDSTONE_STAIRS = 180;
    public const DOUBLE_RED_SANDSTONE_SLAB = 181;
    public const RED_SANDSTONE_SLAB = 182;
    public const FENCE_GATE_SPRUCE = 183;
    public const FENCE_GATE_BIRCH = 184;
    public const FENCE_GATE_JUNGLE = 185;
    public const FENCE_GATE_DARK_OAK = 186;
    public const FENCE_GATE_ACACIA = 187;
    public const SPRUCE_DOOR_BLOCK = 193;
    public const BIRCH_DOOR_BLOCK = 194;
    public const JUNGLE_DOOR_BLOCK = 195;
    public const ACACIA_DOOR_BLOCK = 196;
    public const DARK_OAK_DOOR_BLOCK = 197;
    public const GRASS_PATH    = 198;
    public const ITEM_FRAME_BLOCK = 199;
    public const PODZOL        = 243;
    public const BEETROOT_BLOCK = 244;
    public const STONECUTTER   = 245;
    public const GLOWING_OBSIDIAN = 246;
    public const NETHER_REACTOR = 247;
    public const GLITCH_STONE  = 255;
    public const CAMERA        = 439;
    public const RAIL          = 66;

    // 方块属性表
    private static ?array $blockProperties = null;

    // 方块数据存储：worldHash => blockData
    private array $blocks = [];
    // 方块实体数据：worldHash => blockEntityData
    private array $blockEntities = [];
    // 方块事件队列：worldHash => events[]
    private array $blockEventQueue = [];
    // 方块更新监听器
    private array $updateListeners = [];
    private int $nextBlockId = 1;

    // 4bit 打包常量
    private const BITS_PER_BLOCK = 4;
    private const BLOCKS_PER_BYTE = 2; // 2^4 = 16, 每字节存 2 个方块
    private const CHUNK_SIZE = 16;
    private const CHUNK_HEIGHT = 256;
    private const CHUNK_VOLUME = self::CHUNK_SIZE * self::CHUNK_SIZE * self::CHUNK_HEIGHT;

    /**
     * 初始化方块属性表
     */
    private static function initProperties(): void
    {
        if (self::$blockProperties !== null) {
            return;
        }
        self::$blockProperties = [
            'solid' => [
                self::STONE, self::GRASS, self::DIRT, self::COBBLESTONE, self::PLANKS,
                self::BEDROCK, self::SAND, self::GRAVEL, self::GOLD_ORE, self::IRON_ORE,
                self::COAL_ORE, self::WOOD, self::LEAVES, self::SPONGE, self::LAPIS_BLOCK,
                self::SANDSTONE, self::DISPENSER, self::NOTEBLOCK, self::BED_BLOCK,
                self::COBWEB, self::WOOL, self::GOLD_BLOCK, self::IRON_BLOCK,
                self::DOUBLE_SLAB, self::SLAB, self::BRICKS, self::TNT, self::BOOKSHELF,
                self::MOSS_STONE, self::OBSIDIAN, self::CHEST, self::DIAMOND_ORE,
                self::DIAMOND_BLOCK, self::CRAFTING_TABLE, self::WHEAT_BLOCK,
                self::FARMLAND, self::FURNACE, self::SIGN_POST, self::DOOR_BLOCK,
                self::COBBLE_STAIRS, self::IRON_DOOR_BLOCK, self::REDSTONE_ORE,
                self::GLOWING_REDSTONE_ORE, self::SNOW_LAYER, self::ICE, self::SNOW_BLOCK,
                self::CACTUS, self::CLAY_BLOCK, self::SUGARCANE_BLOCK, self::FENCE,
                self::PUMPKIN, self::NETHERRACK, self::SOUL_SAND, self::GLOWSTONE_BLOCK,
                self::LIT_PUMPKIN, self::CAKE_BLOCK, self::TRAPDOOR, self::MONSTER_EGG_BLOCK,
                self::STONE_BRICKS, self::BROWN_MUSHROOM_BLOCK, self::RED_MUSHROOM_BLOCK,
                self::IRON_BARS, self::GLASS_PANE, self::MELON_BLOCK, self::PUMPKIN_STEM,
                self::MELON_STEM, self::VINE, self::FENCE_GATE, self::BRICK_STAIRS,
                self::STONE_BRICK_STAIRS, self::MYCELIUM, self::NETHER_BRICKS,
                self::NETHER_BRICKS_STAIRS, self::NETHER_WART_BLOCK, self::ENCHANTING_TABLE,
                self::BREWING_STAND_BLOCK, self::CAULDRON_BLOCK, self::END_PORTAL_FRAME,
                self::END_STONE, self::ACTIVE_REDSTONE_LAMP, self::DROPPER,
                self::COCOA_BLOCK, self::SANDSTONE_STAIRS, self::EMERALD_ORE,
                self::EMERALD_BLOCK, self::SPRUCE_WOOD_STAIRS, self::BIRCH_WOOD_STAIRS,
                self::JUNGLE_WOOD_STAIRS, self::STONE_WALL, self::FLOWER_POT_BLOCK,
                self::CARROT_BLOCK, self::POTATO_BLOCK, self::ANVIL, self::TRAPPED_CHEST,
                self::REDSTONE_BLOCK, self::QUARTZ_BLOCK, self::QUARTZ_STAIRS,
                self::DOUBLE_WOOD_SLAB, self::WOOD_SLAB, self::STAINED_CLAY, self::LEAVES2,
                self::WOOD2, self::ACACIA_WOOD_STAIRS, self::DARK_OAK_WOOD_STAIRS,
                self::SLIME_BLOCK, self::IRON_TRAPDOOR, self::HAY_BALE, self::CARPET,
                self::HARDENED_CLAY, self::COAL_BLOCK, self::PACKED_ICE, self::DOUBLE_PLANT,
                self::RED_SANDSTONE, self::RED_SANDSTONE_STAIRS,
                self::DOUBLE_RED_SANDSTONE_SLAB, self::RED_SANDSTONE_SLAB,
                self::FENCE_GATE_SPRUCE, self::FENCE_GATE_BIRCH, self::FENCE_GATE_JUNGLE,
                self::FENCE_GATE_DARK_OAK, self::FENCE_GATE_ACACIA,
                self::SPRUCE_DOOR_BLOCK, self::BIRCH_DOOR_BLOCK, self::JUNGLE_DOOR_BLOCK,
                self::ACACIA_DOOR_BLOCK, self::DARK_OAK_DOOR_BLOCK, self::GRASS_PATH,
                self::ITEM_FRAME_BLOCK, self::PODZOL, self::BEETROOT_BLOCK,
                self::STONECUTTER, self::GLOWING_OBSIDIAN, self::NETHER_REACTOR,
                self::GLITCH_STONE, self::CAMERA, self::RAIL, self::POWERED_RAIL,
                self::DETECTOR_RAIL, self::ACTIVATOR_RAIL, self::STONE_PRESSURE_PLATE,
                self::WOODEN_PRESSURE_PLATE, self::LIGHT_WEIGHTED_PRESSURE_PLATE,
                self::HEAVY_WEIGHTED_PRESSURE_PLATE, self::REDSTONE_WIRE,
                self::REDSTONE_TORCH, self::UNLIT_REDSTONE_TORCH, self::WOODEN_BUTTON,
                self::STONE_BUTTON, self::LEVER, self::DAYLIGHT_SENSOR,
                self::DAYLIGHT_SENSOR_INVERTED, self::SKULL_BLOCK, self::NETHER_QUARTZ_ORE,
                self::TRIPWIRE_HOOK, self::TRIPWIRE, self::DISPENSER, self::DROPPER,
                self::BREWING_STAND_BLOCK, self::CAULDRON_BLOCK,
            ],
            'transparent' => [
                self::AIR, self::WATER, self::STILL_WATER, self::LAVA, self::STILL_LAVA,
                self::COBWEB, self::TALL_GRASS, self::DEAD_BUSH, self::DANDELION,
                self::RED_FLOWER, self::BROWN_MUSHROOM, self::RED_MUSHROOM,
                self::SAPLING, self::TORCH, self::FIRE, self::POWERED_RAIL,
                self::DETECTOR_RAIL, self::REDSTONE_WIRE, self::LADDER, self::WALL_SIGN,
                self::SIGN_POST, self::SNOW_LAYER, self::REEDS, self::VINE,
                self::WATER_LILY, self::LILY_PAD, self::TRIPWIRE, self::TRIPWIRE_HOOK,
                self::END_PORTAL_FRAME, self::PORTAL, self::NETHER_REACTOR,
                self::GLITCH_STONE, self::CAMERA, self::RAIL, self::ACTIVATOR_RAIL,
                self::STONE_PRESSURE_PLATE, self::WOODEN_PRESSURE_PLATE,
                self::LIGHT_WEIGHTED_PRESSURE_PLATE, self::HEAVY_WEIGHTED_PRESSURE_PLATE,
                self::REDSTONE_TORCH, self::UNLIT_REDSTONE_TORCH, self::WOODEN_BUTTON,
                self::STONE_BUTTON, self::LEVER, self::DAYLIGHT_SENSOR,
                self::DAYLIGHT_SENSOR_INVERTED, self::SKULL_BLOCK, self::FLOWER_POT_BLOCK,
                self::ITEM_FRAME_BLOCK, self::GLOWING_OBSIDIAN,
            ],
            'lightLevel' => [
                self::GLOWSTONE, self::GLOWSTONE_BLOCK, self::LIT_PUMPKIN,
                self::JACK_O_LANTERN, self::ACTIVE_REDSTONE_LAMP, self::FIRE,
                self::TORCH, self::REDSTONE_TORCH, self::UNLIT_REDSTONE_TORCH,
                self::LIT_FURNACE, self::BURNING_FURNACE, self::ENCHANTING_TABLE,
                self::END_PORTAL_FRAME, self::PORTAL,
            ],
            'hardness' => [
                self::BEDROCK => 100, self::OBSIDIAN => 100, self::STONE => 10,
                self::COBBLESTONE => 10, self::WOOD => 10, self::PLANKS => 10,
                self::SANDSTONE => 10, self::BRICKS => 10, self::NETHER_BRICKS => 10,
                self::GOLD_BLOCK => 10, self::IRON_BLOCK => 10, self::DIAMOND_BLOCK => 10,
                self::EMERALD_BLOCK => 10, self::COAL_BLOCK => 10, self::LAPIS_BLOCK => 10,
                self::QUARTZ_BLOCK => 10, self::SNOW_BLOCK => 10, self::ICE => 10,
                self::FROSTED_ICE => 10, self::TERRACOTTA => 10, self::HARDENED_CLAY => 10,
                self::GLASS => 10, self::GLASS_PANE => 10, self::IRON_BARS => 10,
                self::FENCE => 10, self::FENCE_GATE => 10, self::WATER => 100,
                self::LAVA => 100, self::AIR => 0,
            ],
        ];
    }

    /**
     * 方块坐标哈希（4bit 打包：每字节存两个方块）
     */
    private function blockHash(int $x, int $y, int $z): string
    {
        return "{$x}:{$y}:{$z}";
    }

    /**
     * 4bit 数据打包：将方块 ID+meta 打包为 1 字节
     * 高 4bit = blockId (0-15), 低 4bit = meta (0-15)
     * 注意：对于 blockId > 15 的方块，使用扩展存储
     */
    public static function pack4bit(int $blockId, int $meta): int
    {
        return (($blockId & 0x0F) << 4) | ($meta & 0x0F);
    }

    /**
     * 4bit 数据解包：从 1 字节还原 blockId 和 meta
     * @return array{int,int} [blockId, meta]
     */
    public static function unpack4bit(int $packed): array
    {
        return [($packed >> 4) & 0x0F, $packed & 0x0F];
    }

    /**
     * 将方块数据打包为区块字节数组（4bit per block）
     * 每个区块 16x16x256 = 65536 个方块，需要 32768 字节
     * @return string 二进制字符串
     */
    public function packChunkData(int $chunkX, int $chunkZ): string
    {
        $bytes = str_repeat("\0", self::CHUNK_VOLUME / self::BLOCKS_PER_BYTE);
        for ($y = 0; $y < self::CHUNK_HEIGHT; $y++) {
            for ($z = 0; $z < self::CHUNK_SIZE; $z++) {
                for ($x = 0; $x < self::CHUNK_SIZE; $x++) {
                    $globalX = ($chunkX << 4) + $x;
                    $globalZ = ($chunkZ << 4) + $z;
                    $block = $this->getBlock($globalX, $y, $globalZ);
                    $blockId = $block ? $block['id'] : self::AIR;
                    $meta = $block ? $block['meta'] : 0;
                    $packed = self::pack4bit($blockId, $meta);
                    // 计算字节索引：每个方块 4bit，每字节 2 个方块
                    $blockIndex = ($y * self::CHUNK_SIZE + $z) * self::CHUNK_SIZE + $x;
                    $byteIndex = $blockIndex >> 1; // blockIndex / 2
                    $isHighNibble = ($blockIndex & 1) === 0; // 偶数 = 高 4bit
                    $current = ord($bytes[$byteIndex]);
                    if ($isHighNibble) {
                        $bytes[$byteIndex] = chr(($current & 0x0F) | ($packed << 4));
                    } else {
                        $bytes[$byteIndex] = chr(($current & 0xF0) | ($packed & 0x0F));
                    }
                }
            }
        }
        return $bytes;
    }

    /**
     * 从区块字节数组解包方块数据（4bit per block）
     * @param string $bytes 二进制字符串
     * @return array<int> [blockId, meta] 对的数组
     */
    public static function unpackChunkData(string $bytes): array
    {
        $blocks = [];
        $byteLen = strlen($bytes);
        for ($blockIndex = 0; $blockIndex < self::CHUNK_VOLUME; $blockIndex++) {
            $byteIndex = $blockIndex >> 1;
            if ($byteIndex >= $byteLen) {
                $blocks[] = [self::AIR, 0];
                continue;
            }
            $byte = ord($bytes[$byteIndex]);
            $isHighNibble = ($blockIndex & 1) === 0;
            $packed = $isHighNibble ? (($byte >> 4) & 0x0F) : ($byte & 0x0F);
            list($blockId, $meta) = self::unpack4bit($packed);
            $blocks[] = [$blockId, $meta];
        }
        return $blocks;
    }

    /**
     * 放置方块 (PlaceBlock 0x95)
     * @param int $x X 坐标
     * @param int $y Y 坐标
     * @param int $z Z 坐标
     * @param int $blockId 方块 ID
     * @param int $meta 方块元数据 (0-15)
     * @param int $face 放置面 (0-5)
     * @param int $cursorX 光标 X
     * @param int $cursorY 光标 Y
     * @param int $cursorZ 光标 Z
     * @return bool 是否放置成功
     */
    public function placeBlock(int $x, int $y, int $z, int $blockId, int $meta = 0, int $face = 0, int $cursorX = 0, int $cursorY = 0, int $cursorZ = 0): bool
    {
        // 验证方块 ID
        if ($blockId < 0 || $blockId > 255) {
            return false;
        }
        // 验证元数据 (4bit)
        if ($meta < 0 || $meta > 15) {
            $meta = $meta & 0x0F;
        }

        $key = $this->blockHash($x, $y, $z);
        $oldBlock = $this->blocks[$key] ?? null;

        $this->blocks[$key] = [
            'id' => $blockId,
            'meta' => $meta,
            'updated' => time(),
            'face' => $face,
            'cursorX' => $cursorX,
            'cursorY' => $cursorY,
            'cursorZ' => $cursorZ,
        ];

        // 触发方块更新事件
        $this->triggerBlockUpdate($x, $y, $z, $oldBlock, $this->blocks[$key]);

        return true;
    }

    /**
     * 挖掘方块 (RemoveBlock 0x96)
     * @param int $x X 坐标
     * @param int $y Y 坐标
     * @param int $z Z 坐标
     * @param int $face 挖掘面
     * @return array|null 被挖掘的方块数据，失败返回 null
     */
    public function removeBlock(int $x, int $y, int $z, int $face = 0): ?array
    {
        $key = $this->blockHash($x, $y, $z);
        if (!isset($this->blocks[$key])) {
            return null;
        }

        $removedBlock = $this->blocks[$key];
        unset($this->blocks[$key]);

        // 触发方块移除事件
        $this->triggerBlockUpdate($x, $y, $z, $removedBlock, null);

        return $removedBlock;
    }

    /**
     * 更新方块 (UpdateBlock 0x97)
     * @param int $x X 坐标
     * @param int $y Y 坐标
     * @param int $z Z 坐标
     * @param int $blockId 新方块 ID
     * @param int $meta 新元数据
     * @param int $flags 更新标志 (0x01 = 元数据, 0x02 = 区块, 0x04 = 全部)
     * @return void
     */
    public function updateBlock(int $x, int $y, int $z, int $blockId, int $meta, int $flags = 0x04): void
    {
        $key = $this->blockHash($x, $y, $z);
        $oldBlock = $this->blocks[$key] ?? null;

        $this->blocks[$key] = [
            'id' => $blockId,
            'meta' => $meta & 0x0F,
            'updated' => time(),
            'flags' => $flags,
        ];

        // 触发方块更新事件
        $this->triggerBlockUpdate($x, $y, $z, $oldBlock, $this->blocks[$key]);
    }

    /**
     * 获取方块
     */
    public function getBlock(int $x, int $y, int $z): ?array
    {
        $key = $this->blockHash($x, $y, $z);
        return $this->blocks[$key] ?? null;
    }

    /**
     * 设置方块实体数据 (BlockEntityData 0x38)
     * @param int $x X 坐标
     * @param int $y Y 坐标
     * @param int $z Z 坐标
     * @param string $nbtData NBT 格式的实体数据
     * @return bool 是否设置成功
     */
    public function setBlockEntityData(int $x, int $y, int $z, string $nbtData): bool
    {
        $key = $this->blockHash($x, $y, $z);
        if (!isset($this->blocks[$key])) {
            return false;
        }
        $this->blockEntities[$key] = $nbtData;
        $this->blocks[$key]['hasEntity'] = true;
        return true;
    }

    /**
     * 获取方块实体数据
     */
    public function getBlockEntityData(int $x, int $y, int $z): ?string
    {
        $key = $this->blockHash($x, $y, $z);
        return $this->blockEntities[$key] ?? null;
    }

    /**
     * 触发方块事件 (BlockEvent 0x1A)
     * @param int $x X 坐标
     * @param int $y Y 坐标
     * @param int $z Z 坐标
     * @param int $eventID 事件 ID
     * @param int $eventParam 事件参数
     * @return array 事件数据
     */
    public function triggerBlockEvent(int $x, int $y, int $z, int $eventID, int $eventParam = 0): array
    {
        $event = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'eventID' => $eventID,
            'eventParam' => $eventParam,
            'timestamp' => time(),
        ];
        $key = $this->blockHash($x, $y, $z);
        if (!isset($this->blockEventQueue[$key])) {
            $this->blockEventQueue[$key] = [];
        }
        $this->blockEventQueue[$key][] = $event;
        return $event;
    }

    /**
     * 内部方法：方块变更时触发事件
     */
    private function triggerBlockUpdate(int $x, int $y, int $z, ?array $oldBlock, ?array $newBlock): void
    {
        $eventID = 0;
        $eventParam = 0;

        if ($oldBlock === null && $newBlock !== null) {
            // 方块放置
            $eventID = 1;
            $eventParam = $newBlock['id'];
        } elseif ($oldBlock !== null && $newBlock === null) {
            // 方块移除
            $eventID = 2;
            $eventParam = $oldBlock['id'];
        } elseif ($oldBlock !== null && $newBlock !== null) {
            // 方块更新
            $eventID = 3;
            $eventParam = $newBlock['id'];
        }

        $this->triggerBlockEvent($x, $y, $z, $eventID, $eventParam);
    }

    /**
     * 获取待处理的方块事件
     */
    public function getBlockEvents(int $x, int $y, int $z): array
    {
        $key = $this->blockHash($x, $y, $z);
        $events = $this->blockEventQueue[$key] ?? [];
        unset($this->blockEventQueue[$key]);
        return $events;
    }

    /**
     * 获取区块中方块数据（位运算索引）
     */
    public function getChunkBlockData(int $chunkX, int $chunkZ): array
    {
        $data = [];
        for ($y = 0; $y < self::CHUNK_HEIGHT; $y++) {
            for ($z = 0; $z < self::CHUNK_SIZE; $z++) {
                for ($x = 0; $x < self::CHUNK_SIZE; $x++) {
                    $globalX = ($chunkX << 4) + $x;
                    $globalZ = ($chunkZ << 4) + $z;
                    $block = $this->getBlock($globalX, $y, $globalZ);
                    $data[] = $block ? $block['id'] : self::AIR;
                }
            }
        }
        return $data;
    }

    /**
     * 获取区块中方块元数据（位运算索引）
     */
    public function getChunkBlockMeta(int $chunkX, int $chunkZ): array
    {
        $data = [];
        for ($y = 0; $y < self::CHUNK_HEIGHT; $y++) {
            for ($z = 0; $z < self::CHUNK_SIZE; $z++) {
                for ($x = 0; $x < self::CHUNK_SIZE; $x++) {
                    $globalX = ($chunkX << 4) + $x;
                    $globalZ = ($chunkZ << 4) + $z;
                    $block = $this->getBlock($globalX, $y, $globalZ);
                    $data[] = $block ? $block['meta'] : 0;
                }
            }
        }
        return $data;
    }

    /**
     * 检查方块是否实心（对照原版注册表）
     */
    public function isSolid(int $blockId): bool
    {
        return BlockFactory::info($blockId)['solid'];
    }

    /**
     * 检查方块是否透明（对照原版注册表）
     */
    public function isTransparent(int $blockId): bool
    {
        return BlockFactory::info($blockId)['transparent'];
    }

    /**
     * 获取方块光照等级（对照原版注册表）
     */
    public function getLightLevel(int $blockId): int
    {
        return BlockFactory::info($blockId)['light'];
    }

    /**
     * 获取方块硬度（对照原版注册表，原版单位如 Stone=1.5、Bedrock=-1）
     */
    public function getHardness(int $blockId): float
    {
        return BlockFactory::info($blockId)['hardness'];
    }

    /**
     * 注册方块更新监听器
     */
    public function addUpdateListener(callable $listener): void
    {
        $this->updateListeners[] = $listener;
    }

    /**
     * 获取所有方块数据（用于序列化）
     */
    public function getAllBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * 加载方块数据（用于反序列化）
     */
    public function loadBlocks(array $data): void
    {
        foreach ($data as $key => $block) {
            $this->blocks[$key] = $block;
        }
    }

    /**
     * 获取方块名称（对照原版注册表，含 meta 变体名）
     */
    public function getBlockName(int $blockId, int $meta = 0): string
    {
        return BlockFactory::nameFor($blockId, $meta);
    }
}