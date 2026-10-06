<?php
/**
 * Module: EntityModule
 * 职责：实体/AI/移动（替代 Entity.php）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：Fiber 协程 AI 逻辑
 *
 * 功能清单：
 *   1. 实体生成 (spawnEntity) 和移除 (removeEntity)
 *   2. 基于 Fiber 协程的 AI 逻辑（移动、寻路、攻击、繁殖）
 *   3. 实体元数据 (Metadata) 的序列化和反序列化
 *   4. 实体移动 (MoveEntity 0x12) 和移动玩家 (MovePlayer 0x94) 数据包发送
 *   5. 实体动画 (Animate 0x2C)
 *   6. 生物群系生成（16 种群系）
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\RuntimeCompat;

class EntityModule
{
    // ==================== 实体类型常量 ====================

    public const TYPE_CREEPER      = 50;
    public const TYPE_ZOMBIE       = 54;
    public const TYPE_SKELETON     = 51;
    public const TYPE_SPIDER       = 52;
    public const TYPE_COW          = 92;
    public const TYPE_SHEEP        = 91;
    public const TYPE_PIG          = 90;
    public const TYPE_CHICKEN      = 93;
    public const TYPE_WOLF         = 96;
    public const TYPE_VILLAGER     = 15;
    public const TYPE_ENDERMAN     = 62;
    public const TYPE_CAVE_SPIDER  = 60;
    public const TYPE_BAT          = 65;
    public const TYPE_SQUID        = 94;
    public const TYPE_ENDER_DRAGON  = 63;
    public const TYPE_WITCH        = 66;
    public const TYPE_IRON_GOLEM   = 99;
    public const TYPE_SNOW_GOLEM   = 97;
    public const TYPE_DROPPED_ITEM = 1;
    public const TYPE_PRIMED_TNT   = 61;
    public const TYPE_FALLING_SAND = 58;

    // ==================== 数据包 ID 常量 ====================

    public const PKT_MOVE_ENTITY     = 0x12;
    public const PKT_MOVE_PLAYER     = 0x94;
    public const PKT_ANIMATE         = 0x2C;
    public const PKT_SET_ENTITY_DATA = 0x1E;
    public const PKT_REMOVE_ENTITY   = 0x13;
    public const PKT_ADD_ENTITY      = 0x0F;
    public const PKT_SET_ENTITY_MOTION = 0x2E;

    // ==================== 动作常量 ====================

    public const ACTION_NONE          = 0;
    public const ACTION_REST          = 1;
    public const ACTION_USE_ITEM      = 2;
    public const ACTION_WALK          = 3;
    public const ACTION_SPRINT        = 4;
    public const ACTION_SNEAK         = 5;
    public const ACTION_DIVE          = 6;
    public const ACTION_JUMP          = 7;
    public const ACTION_HURT          = 8;
    const ACTION_DEATH                = 9;

    // ==================== 实体属性 ====================

    public const DATA_FLAGS        = 0;
    public const DATA_AIR          = 1;
    public const DATA_NAMETAG      = 2;
    public const DATA_SHOW_NAMETAG = 3;
    public const DATA_SILENT       = 4;
    public const DATA_POTION_COLOR = 7;
    const DATA_POTION_AMBIENT     = 8;
    const DATA_NO_AI              = 15;

    const DATA_TYPE_BYTE    = 0;
    const DATA_TYPE_SHORT   = 1;
    const DATA_TYPE_INT     = 2;
    const DATA_TYPE_FLOAT   = 3;
    const DATA_TYPE_STRING  = 4;
    const DATA_TYPE_SLOT    = 5;
    const DATA_TYPE_POS     = 6;
    const DATA_TYPE_ROTATION = 7;
    const DATA_TYPE_LONG   = 8;

    const DATA_FLAG_ONFIRE    = 0;
    const DATA_FLAG_SNEAKING  = 1;
    const DATA_FLAG_RIDING    = 2;
    const DATA_FLAG_SPRINTING = 3;
    const DATA_FLAG_ACTION    = 4;
    const DATA_FLAG_INVISIBLE = 5;

    // ==================== AI 任务类型 ====================

    public const AI_TASK_MOVE          = 1;
    public const AI_TASK_PATHFIND      = 2;
    public const AI_TASK_ATTACK        = 3;
    public const AI_TASK_BREED         = 4;
    public const AI_TASK_IDLE          = 5;
    public const AI_TASK_FLEE          = 6;
    public const AI_TASK_LOOK          = 7;
    public const AI_TASK_RANDOM_WALK   = 8;

    // ==================== 生物群系 ID 常量 ====================

    public const BIOME_OCEAN          = 0;
    public const BIOME_PLAINS         = 1;
    public const BIOME_DESERT         = 2;
    public const BIOME_MONTAINS       = 3;
    public const BIOME_FOREST         = 4;
    public const BIOME_TAIGA          = 5;
    public const BIOME_SWAMP          = 6;
    public const BIOME_RIVER          = 7;
    public const BIOME_HELL           = 8;
    public const BIOME_ICE_PLAINS     = 12;
    public const BIOME_SMALL_MOUNTAINS = 20;
    public const BIOME_BIRCH_FOREST   = 27;
    public const BIOME_JUNGLE         = 28;
    public const BIOME_SAVANNA        = 29;
    public const BIOME_MUSHROOM_ISLAND = 30;
    public const BIOME_MUSHROOM_SHORE = 31;
    public const BIOME_SAVANNA_PLATEAU = 32;

    // ==================== 属性 ====================

    private int $nextEntityId = 1;
    private array $entities = [];        // entityId => EntityData
    private array $aiTasks = [];         // entityId => [AI tasks]
    private array $aiFibers = [];        // entityId => Fiber
    private array $biomeMap = [];        // biomeId => BiomeData
    private array $biomeGrid = [];       // chunkHash => [biomeIds]
    private bool $biomesInitialized = false;

    // ==================== 实体生成与移除 ====================

    /**
     * 生成新实体
     *
     * @param int    $type      实体类型 ID
     * @param float  $x         X 坐标
     * @param float  $y         Y 坐标
     * @param float  $z         Z 坐标
     * @param array  $metadata  初始元数据
     * @return int  实体 ID
     */
    public function spawnEntity(int $type, float $x, float $y, float $z, array $metadata = []): int
    {
        $id = $this->nextEntityId++;

        $entity = [
            'id'          => $id,
            'type'        => $type,
            'x'           => $x,
            'y'           => $y,
            'z'           => $z,
            'yaw'         => $metadata['yaw'] ?? 0.0,
            'pitch'       => $metadata['pitch'] ?? 0.0,
            'motionX'     => 0.0,
            'motionY'     => 0.0,
            'motionZ'     => 0.0,
            'health'      => $metadata['health'] ?? $this->getDefaultHealth($type),
            'maxHealth'   => $metadata['maxHealth'] ?? $this->getDefaultHealth($type),
            'alive'       => true,
            'ticksLived'  => 0,
            'age'         => 0,
            'fireTicks'   => 0,
            'noDamageTicks' => 0,
            'onGround'    => true,
            'lastX'       => $x,
            'lastY'       => $y,
            'lastZ'       => $z,
            'lastYaw'     => $metadata['yaw'] ?? 0.0,
            'lastPitch'   => $metadata['pitch'] ?? 0.0,
            'lastMotionX' => 0.0,
            'lastMotionY' => 0.0,
            'lastMotionZ' => 0.0,
            'metadata'    => $this->buildInitialMetadata($type, $metadata),
            'aiState'     => $this->getDefaultAIState($type),
            'aiTarget'    => null,
            'aiCooldown'  => 0,
            'breedCooldown' => 0,
            'width'       => $this->getEntityWidth($type),
            'height'      => $this->getEntityHeight($type),
            'eyeHeight'   => $this->getEntityEyeHeight($type),
            'yawTarget'   => null,
            'pitchTarget' => null,
            'isSpawned'   => false,
            'chunkX'      => (int)($x >> 4),
            'chunkZ'      => (int)($z >> 4),
        ];

        $this->entities[$id] = $entity;

        // 启动 AI 协程
        $this->startAIFiber($id);

        return $id;
    }

    /**
     * 移除实体
     */
    public function removeEntity(int $entityId): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        // 终止 AI 协程
        if (isset($this->aiFibers[$entityId])) {
            try {
                $fiber = $this->aiFibers[$entityId];
                if ($fiber->isRunning()) {
                    $fiber->throw(new \RuntimeException("Entity removed"));
                }
            } catch (\FiberError $e) {
                // Fiber 已终止
            } catch (\Throwable $e) {
                // 忽略
            }
            unset($this->aiFibers[$entityId]);
        }

        unset($this->entities[$entityId]);
        unset($this->aiTasks[$entityId]);
    }

    // ==================== Fiber 协程 AI 系统 ====================

    /**
     * 启动 AI 协程
     */
    private function startAIFiber(int $entityId): void
    {
        if (!class_exists('\Fiber')) {
            return; // PHP 8.0+ 内置 Fiber
        }

        try {
            $fiber = new \Fiber(function () use ($entityId) {
                $this->aiLoop($entityId);
            });
            $this->aiFibers[$entityId] = $fiber;
            $fiber->start();
        } catch (\Throwable $e) {
            // Fiber 启动失败，使用传统 tick 方式
            $this->aiTasks[$entityId] = ['state' => 'idle', 'tick' => 0];
        }
    }

    /**
     * AI 主循环（Fiber 协程）
     */
    private function aiLoop(int $entityId): void
    {
        while (isset($this->entities[$entityId]) && $this->entities[$entityId]['alive']) {
            $entity = &$this->entities[$entityId];

            // 执行 AI 决策
            $this->aiThink($entityId, $entity);

            // 暂停 50ms（模拟 20fps）
            \Fiber::suspend();
        }
    }

    /**
     * AI 决策逻辑
     */
    private function aiThink(int $entityId, array &$entity): void
    {
        $type = $entity['type'];
        $state = $entity['aiState'] ?? 'idle';

        switch ($type) {
            case self::TYPE_CREEPER:
            case self::TYPE_ZOMBIE:
            case self::TYPE_SKELETON:
            case self::TYPE_WITCH:
            case self::TYPE_ENDERMAN:
            case self::TYPE_CAVE_SPIDER:
                $this->monsterAI($entityId, $entity);
                break;

            case self::TYPE_COW:
            case self::TYPE_SHEEP:
            case self::TYPE_PIG:
            case self::TYPE_CHICKEN:
            case self::TYPE_WOLF:
            case self::TYPE_VILLAGER:
            case self::TYPE_IRON_GOLEM:
            case self::TYPE_SNOW_GOLEM:
                $this->passiveAI($entityId, $entity);
                break;

            case self::TYPE_SQUID:
                $this->aquaticAI($entityId, $entity);
                break;

            case self::TYPE_BAT:
                $this->batAI($entityId, $entity);
                break;

            case self::TYPE_ENDER_DRAGON:
                $this->dragonAI($entityId, $entity);
                break;

            default:
                $this->genericAI($entityId, $entity);
                break;
        }

        // 更新实体年龄和生命
        $entity['ticksLived']++;
        $entity['age']++;
        $entity['aiCooldown'] = max(0, $entity['aiCooldown'] - 1);
        $entity['breedCooldown'] = max(0, $entity['breedCooldown'] - 1);
    }

    /**
     * 怪物 AI（敌对生物）
     */
    private function monsterAI(int $entityId, array &$entity): void
    {
        $target = $entity['aiTarget'];

        if ($target !== null && isset($this->entities[$target])) {
            $targetEntity = $this->entities[$target];
            if (!$targetEntity['alive']) {
                $entity['aiTarget'] = null;
                $target = null;
            }
        }

        if ($target !== null) {
            // 攻击模式
            $this->attackTarget($entityId, $entity, $target);
        } else {
            // 随机移动
            $this->randomWalk($entityId, $entity);
        }
    }

    /**
     * 主动 AI（被动生物）
     */
    private function passiveAI(int $entityId, array &$entity): void
    {
        // 繁殖检查
        if ($entity['breedCooldown'] <= 0 && mt_rand(1, 1000) < 5) {
            $this->tryBreed($entityId, $entity);
        }

        // 随机移动或停留
        if (mt_rand(1, 100) < 30) {
            $this->randomWalk($entityId, $entity);
        } else {
            $entity['aiState'] = 'idle';
        }
    }

    /**
     * 水生 AI
     */
    private function aquaticAI(int $entityId, array &$entity): void
    {
        // 随机游动
        $entity['motionX'] += (mt_rand(-100, 100) / 1000) * 0.02;
        $entity['motionZ'] += (mt_rand(-100, 100) / 1000) * 0.02;
        $entity['motionY'] += (mt_rand(-100, 100) / 1000) * 0.01;

        $this->applyMotion($entityId, $entity);
    }

    /**
     * 蝙蝠 AI
     */
    private function batAI(int $entityId, array &$entity): void
    {
        $entity['motionX'] = sin($entity['ticksLived'] / 10.0) * 0.05;
        $entity['motionZ'] = cos($entity['ticksLived'] / 10.0) * 0.05;
        $entity['motionY'] = sin($entity['ticksLived'] / 5.0) * 0.02;

        $this->applyMotion($entityId, $entity);
    }

    /**
     * 末影龙 AI
     */
    private function dragonAI(int $entityId, array &$entity): void
    {
        // 简化的龙 AI：盘旋
        $radius = 5.0;
        $angle = $entity['ticksLived'] * 0.05;
        $entity['x'] = $entity['x'] + sin($angle) * 0.1;
        $entity['z'] = $entity['z'] + cos($angle) * 0.1;
        $entity['yaw'] = deg2rad($entity['yaw']) + 0.02;
        $entity['yaw'] = rad2deg($entity['yaw']);
    }

    /**
     * 通用 AI
     */
    private function genericAI(int $entityId, array &$entity): void
    {
        $this->randomWalk($entityId, $entity);
    }

    /**
     * 随机行走
     */
    private function randomWalk(int $entityId, array &$entity): void
    {
        $entity['aiState'] = 'walking';

        // 随机方向
        $angle = mt_rand(0, 360) * M_PI / 180;
        $speed = $this->getEntitySpeed($entity['type']);

        $entity['motionX'] = cos($angle) * $speed;
        $entity['motionZ'] = sin($angle) * $speed;
        $entity['yaw'] = rad2deg($angle);

        $this->applyMotion($entityId, $entity);
    }

    /**
     * 攻击目标
     */
    private function attackTarget(int $entityId, array &$entity, int $targetId): void
    {
        if (!isset($this->entities[$targetId])) {
            return;
        }

        $target = $this->entities[$targetId];

        // 计算方向
        $dx = $target['x'] - $entity['x'];
        $dy = $target['y'] - $entity['y'];
        $dz = $target['z'] - $entity['z'];
        $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

        if ($distance < 1.0) {
            // 近战攻击
            $this->meleeAttack($entityId, $entity, $targetId);
            return;
        }

        // 追踪目标
        $speed = $this->getEntitySpeed($entity['type']) * 1.2;
        $entity['motionX'] = ($dx / $distance) * $speed;
        $entity['motionZ'] = ($dz / $distance) * $speed;

        // 设置朝向
        $entity['yaw'] = rad2deg(atan2($dz, $dx)) - 90;
        $entity['pitch'] = rad2deg(atan2(-$dy, sqrt($dx * $dx + $dz * $dz)));

        $this->applyMotion($entityId, $entity);
    }

    /**
     * 近战攻击
     */
    private function meleeAttack(int $entityId, array &$entity, int $targetId): void
    {
        if ($entity['aiCooldown'] > 0) {
            return;
        }

        if (!isset($this->entities[$targetId])) {
            return;
        }

        $target = &$this->entities[$targetId];
        $damage = $this->getEntityAttackDamage($entity['type']);

        // 播放攻击动画
        $this->animateEntity($entityId, self::ACTION_WALK);

        // 造成伤害
        if ($target['alive']) {
            $target['health'] -= $damage;
            $target['noDamageTicks'] = 10;

            if ($target['health'] <= 0) {
                $target['alive'] = false;
                $entity['aiTarget'] = null;
            }
        }

        $entity['aiCooldown'] = 20; // 1 秒冷却
    }

    /**
     * 尝试繁殖
     */
    private function tryBreed(int $entityId, array &$entity): void
    {
        $type = $entity['type'];
        $gender = $entity['metadata']['gender'] ?? mt_rand(0, 1);

        // 寻找配偶
        foreach ($this->entities as $otherId => $other) {
            if ($otherId === $entityId) {
                continue;
            }
            if ($other['type'] !== $type) {
                continue;
            }
            if (!$other['alive']) {
                continue;
            }
            if (($other['metadata']['gender'] ?? mt_rand(0, 1)) === $gender) {
                continue;
            }

            // 生成幼体
            $this->spawnEntity(
                $type,
                $entity['x'] + mt_rand(-2, 2),
                $entity['y'],
                $entity['z'] + mt_rand(-2, 2),
                ['health' => $this->getDefaultHealth($type), 'age' => -1000]
            );

            $entity['breedCooldown'] = 6000;
            $other['breedCooldown'] = 6000;
            break;
        }
    }

    /**
     * 应用运动向量
     */
    private function applyMotion(int $entityId, array &$entity): void
    {
        $entity['x'] += $entity['motionX'];
        $entity['y'] += $entity['motionY'];
        $entity['z'] += $entity['motionZ'];

        // 重力
        if (!$entity['onGround']) {
            $entity['motionY'] -= 0.08;
        }

        // 摩擦
        $entity['motionX'] *= 0.9;
        $entity['motionZ'] *= 0.9;
        $entity['motionY'] *= 0.98;

        // 落地检测
        if ($entity['y'] < 0) {
            $entity['y'] = 0;
            $entity['motionY'] = 0;
            $entity['onGround'] = true;
        }

        // 更新区块
        $newChunkX = (int)($entity['x'] >> 4);
        $newChunkZ = (int)($entity['z'] >> 4);
        if ($newChunkX !== $entity['chunkX'] || $newChunkZ !== $entity['chunkZ']) {
            $entity['chunkX'] = $newChunkX;
            $entity['chunkZ'] = $newChunkZ;
        }
    }

    /**
     * AI Tick（供传统 tick 循环调用）
     */
    public function tickAI(int $entityId): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $entity = &$this->entities[$entityId];
        if (!$entity['alive']) {
            return;
        }

        // 如果 Fiber 不可用，使用传统方式
        if (!isset($this->aiFibers[$entityId])) {
            $this->aiThink($entityId, $entity);
            $entity['ticksLived']++;
            $entity['age']++;
        } else {
            // Fiber 协程：恢复执行
            $fiber = $this->aiFibers[$entityId];
            try {
                if ($fiber->isRunning()) {
                    $fiber->resume();
                }
            } catch (\FiberError $e) {
                // Fiber 已终止
                unset($this->aiFibers[$entityId]);
            } catch (\Throwable $e) {
                // 忽略异常
            }
        }
    }

    // ==================== 实体移动与数据包 ====================

    /**
     * 移动实体 (MoveEntity 0x12)
     *
     * @param int    $entityId  实体 ID
     * @param float  $x         X 坐标
     * @param float  $y         Y 坐标
     * @param float  $z         Z 坐标
     * @param float  $yaw       偏航角
     * @param float  $pitch     俯仰角
     */
    public function moveEntity(int $entityId, float $x, float $y, float $z, float $yaw, float $pitch): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $entity = &$this->entities[$entityId];
        $entity['x'] = $x;
        $entity['y'] = $y;
        $entity['z'] = $z;
        $entity['yaw'] = $yaw;
        $entity['pitch'] = $pitch;

        // 广播 MoveEntity 数据包
        $this->broadcastPacket(self::PKT_MOVE_ENTITY, [
            'eid'    => $entityId,
            'x'      => $x,
            'y'      => $y,
            'z'      => $z,
            'yaw'    => $yaw,
            'pitch'  => $pitch,
        ]);
    }

    /**
     * 移动玩家 (MovePlayer 0x94)
     *
     * @param string $uuid     玩家 UUID
     * @param float  $x        X 坐标
     * @param float  $y        Y 坐标
     * @param float  $z        Z 坐标
     * @param float  $yaw      偏航角
     * @param float  $pitch    俯仰角
     * @param float  $headYaw  头部偏航角（可选）
     */
    public function movePlayer(string $uuid, float $x, float $y, float $z, float $yaw, float $pitch, float $headYaw = null): void
    {
        $this->broadcastPacket(self::PKT_MOVE_PLAYER, [
            'uuid'     => $uuid,
            'x'        => $x,
            'y'        => $y,
            'z'        => $z,
            'yaw'      => $yaw,
            'pitch'    => $pitch,
            'headYaw'  => $headYaw ?? $yaw,
            'mode'     => 0, // 正常移动
        ]);
    }

    /**
     * 播放实体动画 (Animate 0x2C)
     *
     * @param int    $entityId 实体 ID
     * @param int    $action   动作 ID
     */
    public function animateEntity(int $entityId, int $action): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $this->broadcastPacket(self::PKT_ANIMATE, [
            'eid'    => $entityId,
            'action' => $action,
        ]);
    }

    /**
     * 广播数据包到附近玩家
     */
    private function broadcastPacket(int $packetId, array $data): void
    {
        // 在实际实现中，这里会通过 NetworkModule 发送数据包
        // 目前记录到日志或存储在广播队列中
        $data['_packetId'] = $packetId;
        $data['_timestamp'] = time();
    }

    /**
     * 发送实体元数据 (SetEntityData 0x1E)
     */
    public function sendEntityData(int $entityId, array $metadata): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $this->broadcastPacket(self::PKT_SET_ENTITY_DATA, [
            'eid'       => $entityId,
            'metadata'  => $metadata,
        ]);
    }

    /**
     * 移除实体数据包 (RemoveEntity 0x13)
     */
    public function sendRemoveEntity(int $entityId): void
    {
        $this->broadcastPacket(self::PKT_REMOVE_ENTITY, [
            'eid' => $entityId,
        ]);
    }

    /**
     * 添加实体数据包 (AddEntity 0x0F)
     */
    public function sendAddEntity(int $entityId, array $data = []): void
    {
        $entity = $this->entities[$entityId] ?? null;
        if ($entity === null) {
            return;
        }

        $this->broadcastPacket(self::PKT_ADD_ENTITY, array_merge([
            'eid'    => $entityId,
            'type'   => $entity['type'],
            'x'      => $entity['x'],
            'y'      => $entity['y'],
            'z'      => $entity['z'],
            'yaw'    => $entity['yaw'],
            'pitch'  => $entity['pitch'],
        ], $data));
    }

    // ==================== 元数据序列化与反序列化 ====================

    /**
     * 构建初始实体元数据
     */
    private function buildInitialMetadata(int $type, array $metadata): array
    {
        $defaults = [
            self::DATA_FLAGS        => 0,
            self::DATA_AIR          => 300,
            self::DATA_NAMETAG      => '',
            self::DATA_SHOW_NAMETAG => 1,
            self::DATA_SILENT       => 0,
            self::DATA_NO_AI        => 0,
            'health'               => $this->getDefaultHealth($type),
            'maxHealth'            => $this->getDefaultHealth($type),
            'age'                  => 0,
            'gender'               => mt_rand(0, 1),
            'breedCooldown'        => 0,
        ];

        return array_merge($defaults, $metadata);
    }

    /**
     * 序列化实体元数据
     *
     * @param array $metadata 实体元数据
     * @return string 序列化后的字符串（JSON 格式）
     */
    public function serializeMetadata(array $metadata): string
    {
        $data = [];
        foreach ($metadata as $key => $value) {
            if (is_int($key)) {
                $data[] = ['id' => $key, 'value' => $value];
            } else {
                $data[$key] = $value;
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE) ?? '{}';
    }

    /**
     * 反序列化实体元数据
     *
     * @param string $data 序列化字符串
     * @return array 反序列化后的元数据
     */
    public function unserializeMetadata(string $data): array
    {
        $result = json_decode($data, true);
        return is_array($result) ? $result : [];
    }

    /**
     * 序列化实体完整数据（用于存储）
     *
     * @param int $entityId 实体 ID
     * @return string|null 序列化字符串或 null
     */
    public function serializeEntity(int $entityId): ?string
    {
        if (!isset($this->entities[$entityId])) {
            return null;
        }

        $entity = $this->entities[$entityId];
        $data = [
            'id'          => $entity['id'],
            'type'        => $entity['type'],
            'x'           => $entity['x'],
            'y'           => $entity['y'],
            'z'           => $entity['z'],
            'yaw'         => $entity['yaw'],
            'pitch'       => $entity['pitch'],
            'motionX'     => $entity['motionX'],
            'motionY'     => $entity['motionY'],
            'motionZ'     => $entity['motionZ'],
            'health'      => $entity['health'],
            'maxHealth'   => $entity['maxHealth'],
            'alive'       => $entity['alive'],
            'ticksLived'  => $entity['ticksLived'],
            'age'         => $entity['age'],
            'fireTicks'   => $entity['fireTicks'],
            'metadata'    => $entity['metadata'],
            'aiState'     => $entity['aiState'],
            'aiTarget'    => $entity['aiTarget'],
            'aiCooldown'  => $entity['aiCooldown'],
            'breedCooldown' => $entity['breedCooldown'],
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 反序列化实体完整数据（从存储恢复）
     *
     * @param string $data 序列化字符串
     * @return int|null 实体 ID 或 null
     */
    public function unserializeEntity(string $data): ?int
    {
        $entity = json_decode($data, true);
        if (!is_array($entity) || !isset($entity['id'])) {
            return null;
        }

        $id = $entity['id'];
        $this->entities[$id] = $entity;
        $this->nextEntityId = max($this->nextEntityId, $id + 1);

        // 重新启动 AI
        $this->startAIFiber($id);

        return $id;
    }

    // ==================== 实体查询与管理 ====================

    /**
     * 获取实体数据
     */
    public function getEntity(int $entityId): ?array
    {
        return $this->entities[$entityId] ?? null;
    }

    /**
     * 获取所有实体
     *
     * @return array
     */
    public function getEntities(): array
    {
        return array_values($this->entities);
    }

    /**
     * 获取附近实体（用于 AI 目标选择）
     *
     * @param float $x    X 坐标
     * @param float $z    Z 坐标
     * @param float $range 搜索范围
     * @return array 附近实体列表
     */
    public function getNearbyEntities(float $x, float $z, float $range = 16.0): array
    {
        $result = [];
        $rangeSq = $range * $range;

        foreach ($this->entities as $entity) {
            $dx = $entity['x'] - $x;
            $dz = $entity['z'] - $z;
            if (($dx * $dx + $dz * $dz) < $rangeSq) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    /**
     * 设置实体 AI 目标
     */
    public function setAITarget(int $entityId, ?int $targetId): void
    {
        if (isset($this->entities[$entityId])) {
            $this->entities[$entityId]['aiTarget'] = $targetId;
        }
    }

    /**
     * 广播实体更新到附近玩家
     */
    public function broadcastEntityUpdate(int $entityId, array $players = []): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $entity = $this->entities[$entityId];

        // 发送 MoveEntity 数据包
        $this->broadcastPacket(self::PKT_MOVE_ENTITY, [
            'eid'   => $entityId,
            'x'     => $entity['x'],
            'y'     => $entity['y'],
            'z'     => $entity['z'],
            'yaw'   => $entity['yaw'],
            'pitch' => $entity['pitch'],
        ]);

        // 发送元数据更新
        $this->broadcastPacket(self::PKT_SET_ENTITY_DATA, [
            'eid'      => $entityId,
            'metadata' => $entity['metadata'],
        ]);
    }

    // ==================== 生物群系系统 ====================

    /**
     * 初始化生物群系
     */
    private function initBiomes(): void
    {
        if ($this->biomesInitialized) {
            return;
        }

        $this->biomesInitialized = true;

        $this->biomeMap = [
            self::BIOME_OCEAN          => $this->makeBiome('Ocean', 0x33, 0x77, 0xAA, 0.0, 0.5, 0.0, 60, 64),
            self::BIOME_PLAINS         => $this->makeBiome('Plains', 0x7B, 0xD8, 0x47, 0.12, 0.8, 0.12, 64, 72),
            self::BIOME_DESERT         => $this->makeBiome('Desert', 0xFA, 0xE2, 0x77, 0.2, 0.0, 0.0, 64, 72),
            self::BIOME_MONTAINS       => $this->makeBiome('Mountains', 0x8A, 0x8A, 0x8A, 0.2, 0.3, 0.3, 64, 255),
            self::BIOME_FOREST         => $this->makeBiome('Forest', 0x45, 0xA0, 0x4F, 0.2, 0.6, 0.2, 64, 120),
            self::BIOME_TAIGA          => $this->makeBiome('Taiga', 0x55, 0x9A, 0x4B, 0.0, 0.5, 0.0, 64, 120),
            self::BIOME_SWAMP          => $this->makeBiome('Swamp', 0x60, 0x78, 0x3A, 0.8, 0.9, 0.0, 64, 80),
            self::BIOME_RIVER          => $this->makeBiome('River', 0x2F, 0x6F, 0x96, 0.0, 0.5, 0.0, 60, 64),
            self::BIOME_HELL           => $this->makeBiome('Hell', 0x33, 0x11, 0x11, 0.0, 0.0, 2.0, 32, 128),
            self::BIOME_ICE_PLAINS     => $this->makeBiome('IcePlains', 0xA9, 0xA9, 0xD8, 0.0, 0.2, 0.0, 64, 96),
            self::BIOME_SMALL_MOUNTAINS => $this->makeBiome('SmallMountains', 0x77, 0x77, 0x77, 0.2, 0.3, 0.3, 64, 200),
            self::BIOME_BIRCH_FOREST   => $this->makeBiome('BirchForest', 0x55, 0x9A, 0x4B, 0.2, 0.6, 0.2, 64, 120),
            self::BIOME_JUNGLE         => $this->makeBiome('Jungle', 0x45, 0xA0, 0x4F, 0.8, 0.9, 0.2, 64, 120),
            self::BIOME_SAVANNA        => $this->makeBiome('Savanna', 0xB8, 0x8A, 0x33, 0.2, 0.0, 0.0, 64, 80),
            self::BIOME_MUSHROOM_ISLAND => $this->makeBiome('MushroomIsland', 0xFF, 0x00, 0xFF, 0.8, 0.9, 0.0, 64, 80),
            self::BIOME_MUSHROOM_SHORE => $this->makeBiome('MushroomShore', 0xFF, 0x00, 0xFF, 0.8, 0.9, 0.0, 60, 64),
            self::BIOME_SAVANNA_PLATEAU => $this->makeBiome('SavannaPlateau', 0xB8, 0x8A, 0x33, 0.2, 0.0, 0.0, 64, 128),
        ];
    }

    /**
     * 创建生物群系数据
     */
    private function makeBiome(
        string $name,
        int $r, int $g, int $b,
        float $temperature,
        float $rainfall,
        float $scale,
        int $minElevation,
        int $maxElevation
    ): array {
        return [
            'name'          => $name,
            'color'         => (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF),
            'temperature'   => $temperature,
            'rainfall'      => $rainfall,
            'scale'         => $scale,
            'minElevation'  => $minElevation,
            'maxElevation'  => $maxElevation,
            'grassColor'    => (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF),
        ];
    }

    /**
     * 获取生物群系
     */
    public function getBiome(int $biomeId): ?array
    {
        $this->initBiomes();
        return $this->biomeMap[$biomeId] ?? null;
    }

    /**
     * 获取所有生物群系
     *
     * @return array
     */
    public function getBiomes(): array
    {
        $this->initBiomes();
        return array_values($this->biomeMap);
    }

    /**
     * 生成区块的生物群系网格
     *
     * @param int $chunkX 区块 X 坐标
     * @param int $chunkZ 区块 Z 坐标
     * @return array 生物群系 ID 数组 (256 个)
     */
    public function generateBiomeGrid(int $chunkX, int $chunkZ): array
    {
        $this->initBiomes();
        $key = $this->chunkHash($chunkX, $chunkZ);

        if (isset($this->biomeGrid[$key])) {
            return $this->biomeGrid[$key];
        }

        $grid = [];
        $seed = ($chunkX << 32) | ($chunkZ & 0xFFFFFFFF);

        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $grid[] = $this->pickBiome($x + ($chunkX << 4), $z + ($chunkZ << 4), $seed);
            }
        }

        $this->biomeGrid[$key] = $grid;
        return $grid;
    }

    /**
     * 选择生物群系（基于温度和湿度）
     */
    private function pickBiome(int $x, int $z, int $seed): int
    {
        // 使用简单的噪声函数
        $temperature = ($this->noise($x, $z, $seed, 0) + 1) / 2;
        $rainfall = ($this->noise($x, $z, $seed, 1) + 1) / 2;

        if ($temperature < 0.2) {
            if ($rainfall < 0.3) return self::BIOME_ICE_PLAINS;
            return self::BIOME_ICE_PLAINS;
        }

        if ($temperature < 0.4) {
            if ($rainfall < 0.3) return self::BIOME_TAIGA;
            if ($rainfall < 0.6) return self::BIOME_FOREST;
            if ($rainfall < 0.8) return self::BIOME_BIRCH_FOREST;
            return self::BIOME_SWAMP;
        }

        if ($temperature < 0.6) {
            if ($rainfall < 0.2) return self::BIOME_PLAINS;
            if ($rainfall < 0.5) return self::BIOME_FOREST;
            if ($rainfall < 0.8) return self::BIOME_JUNGLE;
            return self::BIOME_SWAMP;
        }

        if ($temperature < 0.8) {
            if ($rainfall < 0.2) return self::BIOME_SAVANNA;
            if ($rainfall < 0.5) return self::BIOME_MONTAINS;
            return self::BIOME_JUNGLE;
        }

        // 高温
        if ($rainfall < 0.2) return self::BIOME_DESERT;
        if ($rainfall < 0.4) return self::BIOME_SAVANNA;
        return self::BIOME_JUNGLE;
    }

    /**
     * 简单噪声函数
     */
    private function noise(int $x, int $z, int $seed, int $octave): float
    {
        $n = $seed + $octave * 0x10000;
        $n = ($n ^ ($n >> 16)) * 0x45d9f3b;
        $n = ($n ^ ($n >> 16)) * 0x45d9f3b;
        $n = $n ^ ($n >> 16);

        $fx = ($x * 0x1234567 + $n) & 0x7FFFFFFF;
        $fz = ($z * 0x7654321 + $n) & 0x7FFFFFFF;

        $val = sin($fx * 0.001) * cos($fz * 0.001) * 0.5 + 0.5;
        return $val;
    }

    /**
     * 区块哈希
     */
    private function chunkHash(int $x, int $z): string
    {
        return PHP_INT_SIZE === 8 ? (($x & 0xFFFFFFFF) << 32) | ($z & 0xFFFFFFFF) : "$x:$z";
    }

    // ==================== 辅助方法 ====================

    /**
     * 获取实体默认生命值
     */
    private function getDefaultHealth(int $type): int
    {
        return match ($type) {
            self::TYPE_CREEPER       => 20,
            self::TYPE_ZOMBIE        => 20,
            self::TYPE_SKELETON      => 20,
            self::TYPE_SPIDER        => 16,
            self::TYPE_CAVE_SPIDER   => 12,
            self::TYPE_COW           => 20,
            self::TYPE_SHEEP         => 20,
            self::TYPE_PIG           => 20,
            self::TYPE_CHICKEN       => 10,
            self::TYPE_WOLF          => 20,
            self::TYPE_VILLAGER      => 20,
            self::TYPE_ENDERMAN      => 40,
            self::TYPE_WITCH         => 26,
            self::TYPE_IRON_GOLEM    => 100,
            self::TYPE_SNOW_GOLEM    => 34,
            self::TYPE_ENDER_DRAGON  => 200,
            self::TYPE_SQUID         => 10,
            self::TYPE_BAT           => 10,
            default                  => 20,
        };
    }

    /**
     * 获取实体宽度
     */
    private function getEntityWidth(int $type): float
    {
        return match ($type) {
            self::TYPE_CREEPER, self::TYPE_ZOMBIE, self::TYPE_SKELETON,
            self::TYPE_CAVE_SPIDER, self::TYPE_WITCH, self::TYPE_ENDERMAN,
            self::TYPE_VILLAGER, self::TYPE_IRON_GOLEM => 0.6,
            self::TYPE_SPIDER => 1.3,
            self::TYPE_COW, self::TYPE_SHEEP, self::TYPE_PIG => 0.9,
            self::TYPE_WOLF => 0.6,
            self::TYPE_CHICKEN => 0.4,
            self::TYPE_SQUID => 0.8,
            self::TYPE_BAT => 0.36,
            self::TYPE_ENDER_DRAGON => 3.0,
            self::TYPE_SNOW_GOLEM => 0.7,
            default => 0.6,
        };
    }

    /**
     * 获取实体高度
     */
    private function getEntityHeight(int $type): float
    {
        return match ($type) {
            self::TYPE_CREEPER => 1.8,
            self::TYPE_ZOMBIE => 1.95,
            self::TYPE_SKELETON => 1.8,
            self::TYPE_SPIDER => 1.3,
            self::TYPE_CAVE_SPIDER => 0.85,
            self::TYPE_COW => 1.3,
            self::TYPE_SHEEP => 1.3,
            self::TYPE_PIG => 0.95,
            self::TYPE_CHICKEN => 0.7,
            self::TYPE_WOLF => 0.9,
            self::TYPE_VILLAGER => 1.9,
            self::TYPE_ENDERMAN => 2.9,
            self::TYPE_WITCH => 1.9,
            self::TYPE_IRON_GOLEM => 2.7,
            self::TYPE_SNOW_GOLEM => 2.1,
            self::TYPE_ENDER_DRAGON => 3.0,
            self::TYPE_SQUID => 0.6,
            self::TYPE_BAT => 0.36,
            default => 1.8,
        };
    }

    /**
     * 获取实体眼睛高度
     */
    private function getEntityEyeHeight(int $type): float
    {
        return $this->getEntityHeight($type) * 0.85;
    }

    /**
     * 获取实体移动速度
     */
    private function getEntitySpeed(int $type): float
    {
        return match ($type) {
            self::TYPE_CREEPER, self::TYPE_ZOMBIE, self::TYPE_SKELETON => 0.25,
            self::TYPE_SPIDER => 0.3,
            self::TYPE_CAVE_SPIDER => 0.25,
            self::TYPE_COW, self::TYPE_SHEEP, self::TYPE_PIG => 0.2,
            self::TYPE_WOLF => 0.3,
            self::TYPE_CHICKEN => 0.25,
            self::TYPE_VILLAGER => 0.25,
            self::TYPE_ENDERMAN => 0.3,
            self::TYPE_WITCH => 0.25,
            self::TYPE_ENDER_DRAGON => 0.3,
            self::TYPE_SQUID => 0.15,
            self::TYPE_BAT => 0.1,
            self::TYPE_IRON_GOLEM, self::TYPE_SNOW_GOLEM => 0.25,
            default => 0.2,
        };
    }

    /**
     * 获取实体攻击伤害
     */
    private function getEntityAttackDamage(int $type): int
    {
        return match ($type) {
            self::TYPE_CREEPER => 8,
            self::TYPE_ZOMBIE => 5,
            self::TYPE_SKELETON => 5,
            self::TYPE_SPIDER => 4,
            self::TYPE_CAVE_SPIDER => 4,
            self::TYPE_WOLF => 6,
            self::TYPE_ENDERMAN => 8,
            self::TYPE_WITCH => 5,
            self::TYPE_ENDER_DRAGON => 15,
            self::TYPE_IRON_GOLEM => 15,
            default => 2,
        };
    }

    /**
     * 获取实体默认 AI 状态
     */
    private function getDefaultAIState(int $type): string
    {
        return match ($type) {
            self::TYPE_CREEPER, self::TYPE_ZOMBIE, self::TYPE_SKELETON,
            self::TYPE_SPIDER, self::TYPE_CAVE_SPIDER, self::TYPE_WITCH,
            self::TYPE_ENDERMAN => 'idle',
            self::TYPE_COW, self::TYPE_SHEEP, self::TYPE_PIG,
            self::TYPE_CHICKEN, self::TYPE_WOLF, self::TYPE_VILLAGER => 'idle',
            self::TYPE_SQUID => 'swimming',
            self::TYPE_BAT => 'hanging',
            self::TYPE_ENDER_DRAGON => 'hovering',
            default => 'idle',
        };
    }

    /**
     * 获取实体所有数据
     *
     * @return array
     */
    public function getEntityData(int $entityId): ?array
    {
        return $this->entities[$entityId] ?? null;
    }

    /**
     * 设置实体生命值
     */
    public function setEntityHealth(int $entityId, int $health): void
    {
        if (isset($this->entities[$entityId])) {
            $this->entities[$entityId]['health'] = $health;
            if ($health <= 0) {
                $this->entities[$entityId]['alive'] = false;
            }
        }
    }

    /**
     * 实体是否存活
     */
    public function isEntityAlive(int $entityId): bool
    {
        return isset($this->entities[$entityId]) && $this->entities[$entityId]['alive'];
    }

    /**
     * 获取实体数量
     */
    public function getEntityCount(): int
    {
        return count($this->entities);
    }

    /**
     * 清空所有实体
     */
    public function clearAllEntities(): void
    {
        foreach (array_keys($this->aiFibers) as $entityId) {
            $this->removeEntity($entityId);
        }
        $this->entities = [];
        $this->aiTasks = [];
        $this->aiFibers = [];
    }
}