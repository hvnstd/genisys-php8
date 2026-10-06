<?php
/**
 * Module: InventoryModule
 * 职责：物品栏/合成/容器/NBT序列化（替代 Inventory.php + CraftingManager）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：保持 NBT 序列化，优化物品查找
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\RuntimeCompat;

class InventoryModule
{
    // 网络数据包 ID
    public const PKT_SEND_INVENTORY     = 0xAD;
    public const PKT_CONTAINER_OPEN     = 0xAF;
    public const PKT_CONTAINER_CLOSE    = 0xB0;
    public const PKT_SET_SLOT           = 0xB1;
    public const PKT_CRAFTING_EVENT     = 0x35;

    // 物品堆最大堆叠
    public const MAX_STACK = 64;

    // 容器类型 ID（网络层）
    public const CONTAINER_TYPE_PLAYER       = 0;
    public const CONTAINER_TYPE_CHEST         = 1;
    public const CONTAINER_TYPE_FURNACE        = 2;
    public const CONTAINER_TYPE_CRAFTING       = 3;
    public const CONTAINER_TYPE_ENCHANT        = 4;
    public const CONTAINER_TYPE_BREWING_STAND  = 5;
    public const CONTAINER_TYPE_ANVIL          = 6;
    public const CONTAINER_TYPE_DISPENSER       = 7;
    public const CONTAINER_TYPE_DROPPER         = 8;
    public const CONTAINER_TYPE_HOPPER          = 9;
    public const CONTAINER_TYPE_HOPPER_BLOCKING  = 10;
    public const CONTAINER_TYPE_TRAPPED_CHEST    = 11;
    public const CONTAINER_TYPE_DOUBLE_CHEST     = 12;

    // 容器槽位大小
    private const CONTAINER_SIZES = [
        self::CONTAINER_TYPE_PLAYER       => 44,  // 36 物品 + 4 护甲
        self::CONTAINER_TYPE_CHEST         => 27,
        self::CONTAINER_TYPE_FURNACE        => 3,   // 输入 + 燃料 + 输出
        self::CONTAINER_TYPE_CRAFTING       => 10,  // 9 网格 + 1 结果
        self::CONTAINER_TYPE_ENCHANT        => 2,   // 输入 + 耗材
        self::CONTAINER_TYPE_BREWING_STAND  => 4,   // 1 输入 + 3 药水
        self::CONTAINER_TYPE_ANVIL          => 3,   // 2 输入 + 1 输出
        self::CONTAINER_TYPE_DISPENSER       => 9,
        self::CONTAINER_TYPE_DROPPER         => 9,
        self::CONTAINER_TYPE_HOPPER          => 5,
        self::CONTAINER_TYPE_HOPPER_BLOCKING  => 5,
        self::CONTAINER_TYPE_TRAPPED_CHEST    => 27,
        self::CONTAINER_TYPE_DOUBLE_CHEST     => 54,
    ];

    // 物品栏数据
    private array $inventories = [];    // containerId => items
    private array $containerMeta = [];   // containerId => metadata
    private array $containerViewers = []; // containerId => [playerUUID, ...]
    private int $nextContainerId = 1;

    // 合成配方
    private array $craftingRecipes = []; // recipeId => recipe
    private array $furnaceRecipes = [];   // furnaceRecipes
    private array $brewingRecipes = [];   // brewingRecipes
    private int $nextRecipeId = 1;

    /**
     * 打开容器 (ContainerOpen 0xAF)
     * @param string $playerUUID 玩家 UUID
     * @param int $containerType 容器类型
     * @param string $title 容器标题
     * @param int $windowId 窗口 ID（由网络层分配）
     * @return int 容器 ID
     */
    public function openContainer(string $playerUUID, int $containerType, string $title, int $windowId = 0): int
    {
        $containerId = $this->nextContainerId++;
        $size = $this->getContainerSize($containerType);

        $this->inventories[$containerId] = array_fill(0, $size, null);
        $this->containerMeta[$containerId] = [
            'owner' => $playerUUID,
            'type' => $containerType,
            'title' => $title,
            'windowId' => $windowId,
            'opened' => time(),
        ];

        if (!isset($this->containerViewers[$containerId])) {
            $this->containerViewers[$containerId] = [];
        }
        $this->containerViewers[$containerId][] = $playerUUID;

        return $containerId;
    }

    /**
     * 关闭容器 (ContainerClose 0xB0)
     * @param int $containerId 容器 ID
     * @param string $playerUUID 玩家 UUID
     * @return void
     */
    public function closeContainer(int $containerId, string $playerUUID): void
    {
        if (!isset($this->inventories[$containerId])) {
            return;
        }
        // 移除查看者
        if (isset($this->containerViewers[$containerId])) {
            $this->containerViewers[$containerId] = array_filter(
                $this->containerViewers[$containerId],
                fn($uuid) => $uuid !== $playerUUID
            );
            if (empty($this->containerViewers[$containerId])) {
                unset($this->containerViewers[$containerId]);
                unset($this->inventories[$containerId]);
                unset($this->containerMeta[$containerId]);
            }
        }
    }

    /**
     * 设置物品栏槽位 (SetSlot 0xB1)
     * @param int $containerId 容器 ID
     * @param int $slot 槽位索引
     * @param array|null $item 物品数据 [id, meta, count, nbt]
     * @return bool 是否设置成功
     */
    public function setSlot(int $containerId, int $slot, ?array $item): bool
    {
        if (!isset($this->inventories[$containerId])) {
            return false;
        }
        if ($slot < 0 || $slot >= count($this->inventories[$containerId])) {
            return false;
        }
        // 验证物品数据格式
        if ($item !== null) {
            if (!isset($item['id']) || !isset($item['count'])) {
                return false;
            }
            // 确保元数据存在
            if (!isset($item['meta'])) {
                $item['meta'] = 0;
            }
            // 确保 NBT 数据存在
            if (!isset($item['nbt'])) {
                $item['nbt'] = null;
            }
            // 限制堆叠数量
            if ($item['count'] > self::MAX_STACK) {
                $item['count'] = self::MAX_STACK;
            }
            if ($item['count'] < 0) {
                $item['count'] = 0;
            }
        }
        $this->inventories[$containerId][$slot] = $item;
        return true;
    }

    /**
     * 获取物品栏槽位
     */
    public function getSlot(int $containerId, int $slot): ?array
    {
        return $this->inventories[$containerId][$slot] ?? null;
    }

    /**
     * 获取容器大小
     */
    public function getContainerSize(int $containerType): int
    {
        return self::CONTAINER_SIZES[$containerType] ?? 27;
    }

    /**
     * 发送物品栏数据 (SendInventory 0xAD)
     * @param int $containerId 容器 ID
     * @return array 物品栏数据包
     */
    public function sendInventory(int $containerId): array
    {
        if (!isset($this->inventories[$containerId])) {
            return ['error' => 'Container not found', 'containerId' => $containerId];
        }

        $meta = $this->containerMeta[$containerId];
        $items = [];
        foreach ($this->inventories[$containerId] as $slot => $item) {
            $items[] = [
                'slot' => $slot,
                'item' => $item,
            ];
        }

        return [
            'packetId' => self::PKT_SEND_INVENTORY,
            'containerId' => $containerId,
            'windowId' => $meta['windowId'],
            'containerType' => $meta['type'],
            'title' => $meta['title'],
            'items' => $items,
            'playerUUID' => $meta['owner'],
        ];
    }

    /**
     * 发送单个槽位更新
     */
    public function sendSlot(int $containerId, int $slot): array
    {
        $item = $this->getSlot($containerId, $slot);
        $meta = $this->containerMeta[$containerId] ?? null;
        return [
            'packetId' => self::PKT_SET_SLOT,
            'containerId' => $containerId,
            'windowId' => $meta['windowId'] ?? 0,
            'slot' => $slot,
            'item' => $item,
        ];
    }

    /**
     * 合成物品 (CraftingEvent 0x35)
     * @param int $craftingType 合成类型 (0=shapeless, 1=shaped, 2=furnace, 3=brewing)
     * @param array $ingredients 配方材料 [slot => item]
     * @param array|null $extra 额外数据
     * @return array|null 合成结果或 null
     */
    public function craft(int $craftingType, array $ingredients, ?array $extra = null): ?array
    {
        return match ($craftingType) {
            0 => $this->craftShapeless($ingredients),
            1 => $this->craftShaped($ingredients),
            2 => $this->craftFurnace($ingredients),
            3 => $this->craftBrewing($ingredients, $extra),
            default => null,
        };
    }

    /**
     * 无序合成 (Shapeless)
     */
    private function craftShapeless(array $ingredients): ?array
    {
        // 将材料扁平化为 id=>count 映射
        $ingredientMap = $this->normalizeIngredients($ingredients);

        foreach ($this->craftingRecipes as $recipe) {
            if (($recipe['type'] ?? 0) !== 0) {
                continue;
            }
            if ($this->matchShapelessRecipe($recipe, $ingredientMap)) {
                return $recipe['result'];
            }
        }
        return null;
    }

    /**
     * 有序合成 (Shaped)
     */
    private function craftShaped(array $ingredients): ?array
    {
        foreach ($this->craftingRecipes as $recipe) {
            if (($recipe['type'] ?? 0) !== 1) {
                continue;
            }
            if ($this->matchShapedRecipe($recipe, $ingredients)) {
                return $recipe['result'];
            }
        }
        return null;
    }

    /**
     * 熔炉合成 (Furnace)
     */
    private function craftFurnace(array $ingredients): ?array
    {
        $input = $ingredients[0] ?? null;
        if ($input === null) {
            return null;
        }
        foreach ($this->furnaceRecipes as $recipe) {
            if ($this->matchFurnaceRecipe($recipe, $input)) {
                return $recipe['result'];
            }
        }
        return null;
    }

    /**
     * 酿造合成 (Brewing)
     */
    private function craftBrewing(array $ingredients, ?array $extra): ?array
    {
        $input = $ingredients[0] ?? null;
        $reagent = $extra['reagent'] ?? ($ingredients[1] ?? null);
        if ($input === null || $reagent === null) {
            return null;
        }
        foreach ($this->brewingRecipes as $recipe) {
            if ($this->matchBrewingRecipe($recipe, $input, $reagent)) {
                return $recipe['result'];
            }
        }
        return null;
    }

    /**
     * 将材料扁平化为 id=>count 映射
     */
    private function normalizeIngredients(array $ingredients): array
    {
        $map = [];
        foreach ($ingredients as $item) {
            if ($item === null) {
                continue;
            }
            $key = $item['id'] . ':' . ($item['meta'] ?? 0);
            $map[$key] = ($map[$key] ?? 0) + ($item['count'] ?? 1);
        }
        return $map;
    }

    /**
     * 匹配无序配方
     */
    private function matchShapelessRecipe(array $recipe, array $ingredientMap): bool
    {
        $required = $recipe['ingredients'] ?? [];
        $requiredMap = [];
        foreach ($required as $ingredient) {
            $key = $ingredient['id'] . ':' . $ingredient['meta'];
            $requiredMap[$key] = ($requiredMap[$key] ?? 0) + $ingredient['count'];
        }

        foreach ($requiredMap as $key => $count) {
            if (($ingredientMap[$key] ?? 0) < $count) {
                return false;
            }
        }
        return true;
    }

    /**
     * 匹配有序配方
     */
    private function matchShapedRecipe(array $recipe, array $ingredients): bool
    {
        $shape = $recipe['shape'] ?? [];
        $grid = $recipe['grid'] ?? [];
        $width = $recipe['width'] ?? 3;
        $height = $recipe['height'] ?? 3;

        // 将 ingredients 映射到网格
        $gridMap = [];
        foreach ($ingredients as $slot => $item) {
            if ($item !== null) {
                $gridMap[$slot] = $item;
            }
        }

        // 检查每个网格位置
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pos = $y * $width + $x;
                $expected = $grid[$pos] ?? null;
                $actual = $gridMap[$pos] ?? null;

                if ($expected === null && $actual === null) {
                    continue;
                }
                if ($expected === null || $actual === null) {
                    return false;
                }
                if ($expected['id'] !== $actual['id'] || $expected['meta'] !== $actual['meta']) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * 匹配熔炉配方
     */
    private function matchFurnaceRecipe(array $recipe, array $input): bool
    {
        $expected = $recipe['input'] ?? null;
        if ($expected === null) {
            return false;
        }
        return $expected['id'] === $input['id'] && $expected['meta'] === ($input['meta'] ?? 0);
    }

    /**
     * 匹配酿造配方
     */
    private function matchBrewingRecipe(array $recipe, array $input, array $reagent): bool
    {
        $expectedInput = $recipe['input'] ?? null;
        $expectedReagent = $recipe['reagent'] ?? null;
        if ($expectedInput === null || $expectedReagent === null) {
            return false;
        }
        return $expectedInput['id'] === $input['id']
            && $expectedReagent['id'] === $reagent['id'];
    }

    /**
     * 注册合成配方
     */
    public function registerRecipe(array $recipe): void
    {
        $recipe['id'] = $this->nextRecipeId++;
        if (!isset($recipe['type'])) {
            $recipe['type'] = isset($recipe['shape']) ? 1 : 0;
        }
        $this->craftingRecipes[] = $recipe;
    }

    /**
     * 注册熔炉配方
     */
    public function registerFurnaceRecipe(array $recipe): void
    {
        $this->furnaceRecipes[] = $recipe;
    }

    /**
     * 注册酿造配方
     */
    public function registerBrewingRecipe(array $recipe): void
    {
        $this->brewingRecipes[] = $recipe;
    }

    /**
     * 物品堆序列化（NBT 格式）
     * NBT 格式：Tag Compound { id: Int, Damage: Short, Count: Byte, tag: Compound }
     * @param array|null $item 物品数据 [id, meta, count, nbt]
     * @return string|null NBT 序列化字符串
     */
    public function serializeItemStack(?array $item): ?string
    {
        if ($item === null) {
            return null;
        }

        $nbt = $this->serializeNBT([
            'id' => $item['id'],
            'Damage' => $item['meta'] ?? 0,
            'Count' => $item['count'],
            'tag' => $item['nbt'] ?? null,
        ]);

        return $nbt;
    }

    /**
     * 物品堆反序列化（NBT 格式）
     * @param string|null $data NBT 序列化字符串
     * @return array|null 物品数据 [id, meta, count, nbt]
     */
    public function unserializeItemStack(?string $data): ?array
    {
        if ($data === null || $data === '') {
            return null;
        }

        $nbt = $this->unserializeNBT($data);
        if ($nbt === null) {
            return null;
        }

        return [
            'id' => $nbt['id'] ?? 0,
            'meta' => $nbt['Damage'] ?? 0,
            'count' => $nbt['Count'] ?? 1,
            'nbt' => $nbt['tag'] ?? null,
        ];
    }

    /**
     * NBT 序列化（简化版 NBT 格式）
     * 支持 Tag Compound, Tag Byte, Tag Short, Tag Int, Tag Long, Tag String, Tag List, Tag Compound
     */
    public function serializeNBT(array $data): string
    {
        $output = '';
        foreach ($data as $key => $value) {
            $output .= $this->serializeNBTEntry($key, $value);
        }
        return $output;
    }

    /**
     * 序列化单个 NBT 条目
     */
    private function serializeNBTEntry(string $key, mixed $value): string
    {
        if ($value === null) {
            return ''; // 跳过 null 值
        }

        $type = $this->getNBTType($value);
        $output = chr($type);
        $output .= $this->serializeNBTString($key);

        switch ($type) {
            case 0: // Tag_End
                break;
            case 1: // Tag_Byte
                $output .= chr((int) $value & 0xFF);
                break;
            case 2: // Tag_Short
                $output .= pack('n', (int) $value & 0xFFFF);
                break;
            case 3: // Tag_Int
                $output .= pack('N', (int) $value);
                break;
            case 4: // Tag_Long
                $output .= pack('N', (int) ($value >> 32) & 0xFFFFFFFF);
                $output .= pack('N', (int) $value & 0xFFFFFFFF);
                break;
            case 5: // Tag_Float
                $output .= pack('G', (float) $value);
                break;
            case 6: // Tag_Double
                $output .= pack('E', (float) $value);
                break;
            case 7: // Tag_Byte_Array
                $output .= pack('N', count($value));
                foreach ($value as $byte) {
                    $output .= chr((int) $byte & 0xFF);
                }
                break;
            case 8: // Tag_String
                $output .= $this->serializeNBTString((string) $value);
                break;
            case 9: // Tag_List
                $output .= chr($this->getNBTType($value[0] ?? null));
                $output .= pack('N', count($value));
                foreach ($value as $item) {
                    $output .= $this->serializeNBTValue($item);
                }
                break;
            case 10: // Tag_Compound
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $output .= $this->serializeNBTEntry($subKey, $subValue);
                    }
                }
                $output .= chr(0); // Tag_End
                break;
            case 11: // Tag_Int_Array
                $output .= pack('N', count($value));
                foreach ($value as $int) {
                    $output .= pack('N', (int) $int);
                }
                break;
            case 12: // Tag_Long_Array
                $output .= pack('N', count($value));
                foreach ($value as $long) {
                    $output .= pack('N', (int) ($long >> 32) & 0xFFFFFFFF);
                    $output .= pack('N', (int) $long & 0xFFFFFFFF);
                }
                break;
        }
        return $output;
    }

    /**
     * 序列化 NBT 字符串
     */
    private function serializeNBTString(string $str): string
    {
        $len = strlen($str);
        return pack('n', $len) . $str;
    }

    /**
     * 序列化 NBT 值（无键名）
     */
    private function serializeNBTValue(mixed $value): string
    {
        $type = $this->getNBTType($value);
        $output = chr($type);
        switch ($type) {
            case 1: // Tag_Byte
                $output .= chr((int) $value & 0xFF);
                break;
            case 2: // Tag_Short
                $output .= pack('n', (int) $value & 0xFFFF);
                break;
            case 3: // Tag_Int
                $output .= pack('N', (int) $value);
                break;
            case 4: // Tag_Long
                $output .= pack('N', (int) ($value >> 32) & 0xFFFFFFFF);
                $output .= pack('N', (int) $value & 0xFFFFFFFF);
                break;
            case 5: // Tag_Float
                $output .= pack('G', (float) $value);
                break;
            case 6: // Tag_Double
                $output .= pack('E', (float) $value);
                break;
            case 7: // Tag_Byte_Array
                $output .= pack('N', count($value));
                foreach ($value as $byte) {
                    $output .= chr((int) $byte & 0xFF);
                }
                break;
            case 8: // Tag_String
                $output .= $this->serializeNBTString((string) $value);
                break;
            case 9: // Tag_List
                $output .= chr($this->getNBTType($value[0] ?? null));
                $output .= pack('N', count($value));
                foreach ($value as $item) {
                    $output .= $this->serializeNBTValue($item);
                }
                break;
            case 10: // Tag_Compound
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $output .= $this->serializeNBTEntry($subKey, $subValue);
                    }
                }
                $output .= chr(0); // Tag_End
                break;
        }
        return $output;
    }

    /**
     * 获取 NBT 类型 ID
     */
    private function getNBTType(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_bool($value)) {
            return 1; // Tag_Byte
        }
        if (is_int($value)) {
            return 3; // Tag_Int
        }
        if (is_float($value)) {
            return 6; // Tag_Double
        }
        if (is_string($value)) {
            return 8; // Tag_String
        }
        if (is_array($value)) {
            if (array_keys($value) === range(0, count($value) - 1)) {
                // 索引数组
                if (count($value) > 0) {
                    $first = $value[0];
                    if (is_int($first)) {
                        return 11; // Tag_Int_Array
                    }
                    if (is_string($first)) {
                        return 7; // Tag_Byte_Array
                    }
                }
                return 9; // Tag_List
            }
            return 10; // Tag_Compound
        }
        return 0;
    }

    /**
     * NBT 反序列化
     * @param string $data NBT 序列化字符串
     * @return array|null 反序列化后的数据
     */
    public function unserializeNBT(string $data): ?array
    {
        if ($data === '' || $data === null) {
            return null;
        }
        $offset = 0;
        $result = $this->unserializeNBTCompound($data, $offset);
        return $result;
    }

    /**
     * 反序列化 NBT Compound
     */
    private function unserializeNBTCompound(string $data, int &$offset): ?array
    {
        $result = [];
        while ($offset < strlen($data)) {
            $type = ord($data[$offset]);
            $offset++;

            if ($type === 0) { // Tag_End
                break;
            }

            // 读取键名
            $keyLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $key = substr($data, $offset, $keyLen);
            $offset += $keyLen;

            $value = $this->unserializeNBTValue($data, $offset, $type);
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * 反序列化 NBT 值
     */
    private function unserializeNBTValue(string $data, int &$offset, int $type): mixed
    {
        switch ($type) {
            case 0: // Tag_End
                return null;
            case 1: // Tag_Byte
                $val = ord($data[$offset]);
                $offset++;
                return $val;
            case 2: // Tag_Short
                $val = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                return $val;
            case 3: // Tag_Int
                $val = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                return $val;
            case 4: // Tag_Long
                $high = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                $low = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                return ($high << 32) | $low;
            case 5: // Tag_Float
                $val = unpack('G', substr($data, $offset, 4))[1];
                $offset += 4;
                return $val;
            case 6: // Tag_Double
                $val = unpack('E', substr($data, $offset, 8))[1];
                $offset += 8;
                return $val;
            case 7: // Tag_Byte_Array
                $len = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                $val = [];
                for ($i = 0; $i < $len; $i++) {
                    $val[] = ord($data[$offset]);
                    $offset++;
                }
                return $val;
            case 8: // Tag_String
                $len = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                $val = substr($data, $offset, $len);
                $offset += $len;
                return $val;
            case 9: // Tag_List
                $listType = ord($data[$offset]);
                $offset++;
                $len = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                $val = [];
                for ($i = 0; $i < $len; $i++) {
                    $val[] = $this->unserializeNBTValue($data, $offset, $listType);
                }
                return $val;
            case 10: // Tag_Compound
                return $this->unserializeNBTCompound($data, $offset);
            case 11: // Tag_Int_Array
                $len = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                $val = [];
                for ($i = 0; $i < $len; $i++) {
                    $val[] = unpack('N', substr($data, $offset, 4))[1];
                    $offset += 4;
                }
                return $val;
            case 12: // Tag_Long_Array
                $len = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                $val = [];
                for ($i = 0; $i < $len; $i++) {
                    $high = unpack('N', substr($data, $offset, 4))[1];
                    $offset += 4;
                    $low = unpack('N', substr($data, $offset, 4))[1];
                    $offset += 4;
                    $val[] = ($high << 32) | $low;
                }
                return $val;
            default:
                return null;
        }
    }

    /**
     * 获取容器元数据
     */
    public function getContainerMeta(int $containerId): ?array
    {
        return $this->containerMeta[$containerId] ?? null;
    }

    /**
     * 获取容器查看者
     */
    public function getContainerViewers(int $containerId): array
    {
        return $this->containerViewers[$containerId] ?? [];
    }

    /**
     * 获取容器物品
     */
    public function getContainerContents(int $containerId): array
    {
        return $this->inventories[$containerId] ?? [];
    }

    /**
     * 设置容器物品
     */
    public function setContainerContents(int $containerId, array $items): bool
    {
        if (!isset($this->inventories[$containerId])) {
            return false;
        }
        $this->inventories[$containerId] = $items;
        return true;
    }

    /**
     * 添加物品到容器
     */
    public function addItem(int $containerId, array $item): array
    {
        if (!isset($this->inventories[$containerId])) {
            return [$item];
        }
        $remaining = $item;
        $maxStack = self::MAX_STACK;

        // 尝试合并到已有槽位
        for ($i = 0; $i < count($this->inventories[$containerId]); $i++) {
            $slot = $this->inventories[$containerId][$i];
            if ($slot !== null
                && $slot['id'] === $remaining['id']
                && $slot['meta'] === ($remaining['meta'] ?? 0)
                && $slot['count'] < $maxStack
            ) {
                $add = min($maxStack - $slot['count'], $remaining['count']);
                $this->inventories[$containerId][$i]['count'] += $add;
                $remaining['count'] -= $add;
                if ($remaining['count'] <= 0) {
                    return [];
                }
            }
        }

        // 放入空槽位
        for ($i = 0; $i < count($this->inventories[$containerId]); $i++) {
            if ($this->inventories[$containerId][$i] === null) {
                $add = min($maxStack, $remaining['count']);
                $this->inventories[$containerId][$i] = [
                    'id' => $remaining['id'],
                    'meta' => $remaining['meta'] ?? 0,
                    'count' => $add,
                ];
                $remaining['count'] -= $add;
                if ($remaining['count'] <= 0) {
                    return [];
                }
            }
        }

        return [$remaining];
    }

    /**
     * 从容器移除物品
     */
    public function removeItem(int $containerId, array $item): array
    {
        if (!isset($this->inventories[$containerId])) {
            return [$item];
        }
        $remaining = $item;
        for ($i = 0; $i < count($this->inventories[$containerId]); $i++) {
            $slot = $this->inventories[$containerId][$i];
            if ($slot !== null
                && $slot['id'] === $remaining['id']
                && $slot['meta'] === ($remaining['meta'] ?? 0)
                && $slot['count'] > 0
            ) {
                $remove = min($slot['count'], $remaining['count']);
                $slot['count'] -= $remove;
                $remaining['count'] -= $remove;
                if ($slot['count'] <= 0) {
                    $this->inventories[$containerId][$i] = null;
                }
                if ($remaining['count'] <= 0) {
                    return [];
                }
            }
        }
        return [$remaining];
    }

    /**
     * 检查容器是否包含物品
     */
    public function contains(int $containerId, array $item): bool
    {
        if (!isset($this->inventories[$containerId])) {
            return false;
        }
        foreach ($this->inventories[$containerId] as $slot) {
            if ($slot !== null
                && $slot['id'] === $item['id']
                && $slot['meta'] === ($item['meta'] ?? 0)
                && $slot['count'] >= ($item['count'] ?? 1)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 查找第一个匹配的槽位
     */
    public function first(int $containerId, array $item): int
    {
        if (!isset($this->inventories[$containerId])) {
            return -1;
        }
        foreach ($this->inventories[$containerId] as $i => $slot) {
            if ($slot !== null
                && $slot['id'] === $item['id']
                && $slot['meta'] === ($item['meta'] ?? 0)
                && $slot['count'] >= ($item['count'] ?? 1)
            ) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * 查找第一个空槽位
     */
    public function firstEmpty(int $containerId): int
    {
        if (!isset($this->inventories[$containerId])) {
            return -1;
        }
        foreach ($this->inventories[$containerId] as $i => $slot) {
            if ($slot === null) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * 清空槽位
     */
    public function clearSlot(int $containerId, int $slot): bool
    {
        if (!isset($this->inventories[$containerId])) {
            return false;
        }
        if ($slot < 0 || $slot >= count($this->inventories[$containerId])) {
            return false;
        }
        $this->inventories[$containerId][$slot] = null;
        return true;
    }

    /**
     * 清空所有槽位
     */
    public function clearAll(int $containerId): void
    {
        if (isset($this->inventories[$containerId])) {
            $this->inventories[$containerId] = array_fill(0, count($this->inventories[$containerId]), null);
        }
    }

    /**
     * 获取所有配方
     */
    public function getRecipes(): array
    {
        return $this->craftingRecipes;
    }

    /**
     * 获取熔炉配方
     */
    public function getFurnaceRecipes(): array
    {
        return $this->furnaceRecipes;
    }

    /**
     * 获取酿造配方
     */
    public function getBrewingRecipes(): array
    {
        return $this->brewingRecipes;
    }
}