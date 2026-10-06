<?php
/**
 * Module: PlayerModule
 * 职责：玩家/认证/聊天/物品栏（替代 Player.php）
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：Fiber 协程玩家处理
 *
 * MCPE 0.14.x 协议包 ID 对照：
 *   LOGIN_PACKET            = 0x8f  (JWT 认证登录)
 *   TEXT_PACKET             = 0x93  (聊天消息)
 *   MOVE_PLAYER_PACKET      = 0x9d  (玩家位置同步)
 *   PLAYER_ACTION_PACKET    = 0xab  (玩家动作)
 *   SET_HEALTH_PACKET       = 0xb0  (生命值同步)
 *   RESPAWN_PACKET          = 0xb3  (重生)
 *   CONTAINER_SET_CONTENT   = 0xb9  (发送物品栏)
 */
declare(strict_types=1);

namespace Genisys\Module;

class PlayerModule
{
    private const PROTOCOL_MCPE_0_14 = 70;
    private const ACCEPTED_PROTOCOLS = [45, 46, 60, 70];

    // 协议包 ID
    private const PK_LOGIN             = 0x8f;
    private const PK_TEXT              = 0x93;
    private const PK_MOVE_PLAYER       = 0x9d;
    private const PK_PLAYER_ACTION     = 0xab;
    private const PK_SET_HEALTH        = 0xb0;
    private const PK_RESPAWN           = 0xb3;
    private const PK_CONTAINER_SET_CONTENT = 0xb9;

    // PlayerAction 动作常量
    private const ACTION_START_BREAK     = 0;
    private const ACTION_ABORT_BREAK     = 1;
    private const ACTION_STOP_BREAK      = 2;
    private const ACTION_RELEASE_ITEM    = 5;
    private const ACTION_STOP_SLEEPING   = 6;
    private const ACTION_RESPAWN         = 7;
    private const ACTION_JUMP            = 8;
    private const ACTION_START_SPRINT    = 9;
    private const ACTION_STOP_SPRINT     = 10;
    private const ACTION_START_SNEAK      = 11;
    private const ACTION_STOP_SNEAK       = 12;
    private const ACTION_DIMENSION_CHANGE = 13;

    // TextPacket 类型
    private const TEXT_TYPE_RAW       = 0;
    private const TEXT_TYPE_CHAT      = 1;
    private const TEXT_TYPE_TRANSLATION = 2;
    private const TEXT_TYPE_POPUP     = 3;
    private const TEXT_TYPE_TIP       = 4;
    private const TEXT_TYPE_SYSTEM    = 5;

    private array $players = [];        // uuid => PlayerData
    private array $sessions = [];       // sessionKey => uuid
    private array $jwtSecrets = [];     // clientId => secret (JWT 签名密钥)
    private int $maxPlayers = 20;

    /**
     * 玩家登录（MCPE 0.14.x JWT 认证流程）
     *
     * 流程：
     *   1. 客户端发送 LOGIN_PACKET (0x8f)，包含 username/clientUUID/clientId/clientSecret/protocol
     *   2. 服务器验证 JWT token（clientSecret 作为签名密钥）
     *   3. 验证通过 -> 触发 PlayerPreLoginEvent -> PlayerLoginEvent
     *   4. 发送 PlayStatusPacket(LOGIN_SUCCESS) + StartGamePacket
     *
     * @param string $uuid       客户端 UUID
     * @param string $username   玩家名
     * @param string $clientData 客户端数据（JWT payload）
     * @param string $clientSecret JWT 签名密钥
     * @param int    $protocol   协议版本
     * @return bool 成功 true，失败 false
     */
    public function onLogin(string $uuid, string $username, string $clientData, string $clientSecret = '', int $protocol = 0): bool
    {
        // 1. 检查是否已在线
        if (isset($this->players[$uuid])) {
            return false;
        }

        // 2. 验证协议版本
        if (!in_array($protocol, self::ACCEPTED_PROTOCOLS, true)) {
            return false;
        }

        // 3. 验证用户名
        $len = strlen($username);
        if ($len < 3 || $len > 16) {
            return false;
        }
        $valid = true;
        for ($i = 0; $i < $len && $valid; $i++) {
            $c = ord($username[$i]);
            if (($c >= ord('a') && $c <= ord('z'))
                || ($c >= ord('A') && $c <= ord('Z'))
                || ($c >= ord('0') && $c <= ord('9'))
                || $c === ord('_')
            ) {
                continue;
            }
            $valid = false;
        }
        if (!$valid) {
            return false;
        }

        // 4. JWT 认证：验证 clientSecret 签名
        if (!$this->verifyJWT($uuid, $clientData, $clientSecret)) {
            return false;
        }

        // 5. 检查在线人数
        $onlineCount = count($this->getOnlinePlayers());
        if ($onlineCount >= $this->maxPlayers) {
            return false;
        }

        // 6. 创建玩家数据
        $this->players[$uuid] = [
            'uuid'           => $uuid,
            'username'       => $username,
            'clientData'     => $clientData,
            'clientSecret'   => $clientSecret,
            'protocol'       => $protocol,
            'gameMode'       => 0, // Survival
            'health'         => 20,
            'maxHealth'      => 20,
            'hungry'         => 20,
            'saturation'     => 20,
            'experience'     => 0,
            'expLevel'       => 0,
            'position'       => [0, 64, 0],
            'yaw'            => 0.0,
            'pitch'          => 0.0,
            'bodyYaw'        => 0.0,
            'inventory'      => array_fill(0, 36, null),
            'hotbarSlot'     => 0,
            'creativeItems'  => [],
            'connected'      => true,
            'loggedIn'       => true,
            'spawned'        => false,
            'lastActivity'   => time(),
            'ip'             => '',
            'port'           => 0,
            'skin'           => '',
            'skinName'       => 'DefaultSkin',
            'permissions'    => [],
        ];

        // 7. 生成 session key
        $sessionKey = $this->generateSessionKey($uuid);
        $this->sessions[$sessionKey] = $uuid;

        return true;
    }

    /**
     * JWT 验证（MCPE 0.14.x 认证）
     *
     * 验证 clientData 的 JWT 签名是否匹配 clientSecret。
     * 在实际部署中，clientSecret 由 Mojang 服务端签发。
     *
     * @param string $uuid
     * @param string $clientData JWT payload（base64 编码）
     * @param string $clientSecret 签名密钥
     * @return bool
     */
    private function verifyJWT(string $uuid, string $clientData, string $clientSecret): bool
    {
        if (empty($clientSecret)) {
            // 开发/测试模式：允许无密钥连接
            return true;
        }

        // 解码 JWT payload
        $parts = explode('.', $clientData);
        if (count($parts) < 2) {
            return false;
        }

        $payloadB64 = $parts[0];
        $signatureB64 = $parts[1] ?? '';

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if ($payload === null) {
            return false;
        }

        // 验证 JWT 过期时间
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        // 验证 iss（签发者）
        if (isset($payload['iss']) && $payload['iss'] !== 'Minecraft') {
            return false;
        }

        // 验证签名
        $expectedSig = hash_hmac('sha256', $payloadB64, $clientSecret, true);
        $actualSig = $this->base64UrlDecode($signatureB64);

        return hash_equals($expectedSig, $actualSig);
    }

    /**
     * Base64 URL 安全解码
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    /**
     * 生成 session key
     */
    private function generateSessionKey(string $uuid): string
    {
        return hash('sha256', $uuid . bin2hex(random_bytes(16)));
    }

    /**
     * 玩家断开连接
     */
    public function onDisconnect(string $uuid): void
    {
        if (isset($this->players[$uuid])) {
            $this->players[$uuid]['connected'] = false;
            $this->players[$uuid]['loggedIn'] = false;
            $this->players[$uuid]['spawned'] = false;
            $this->players[$uuid]['lastActivity'] = time();
        }

        // 清理 session
        foreach ($this->sessions as $key => $suuid) {
            if ($suuid === $uuid) {
                unset($this->sessions[$key]);
            }
        }
    }

    /**
     * 发送聊天消息（TextPacket 0x93 TYPE_CHAT=1）
     *
     * @param string $uuid
     * @param string $message
     * @param string $type  消息类型（TYPE_CHAT/TYPE_RAW/TYPE_SYSTEM）
     */
    public function sendChat(string $uuid, string $message, string $type = 'chat'): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        $packet = $this->buildTextPacket($uuid, $message, $type);

        // 广播给所有在线玩家
        foreach ($this->getOnlinePlayers() as $p) {
            if ($p['uuid'] === $uuid || $this->canSee($p['uuid'], $uuid)) {
                $this->sendPacket($p['uuid'], $packet);
            }
        }
    }

    /**
     * 构建 Text 数据包（0x93）
     */
    private function buildTextPacket(string $uuid, string $message, string $type): array
    {
        $typeMap = [
            'chat'        => self::TEXT_TYPE_CHAT,
            'raw'         => self::TEXT_TYPE_RAW,
            'system'      => self::TEXT_TYPE_SYSTEM,
            'tip'         => self::TEXT_TYPE_TIP,
            'popup'       => self::TEXT_TYPE_POPUP,
            'translation' => self::TEXT_TYPE_TRANSLATION,
        ];

        $textType = $typeMap[$type] ?? self::TEXT_TYPE_SYSTEM;

        return [
            'packetId'  => self::PK_TEXT,
            'type'      => $textType,
            'source'    => $this->players[$uuid]['username'] ?? '',
            'message'   => $message,
            'parameters' => [],
        ];
    }

    /**
     * 处理玩家动作（PlayerActionPacket 0xab）
     *
     * @param string $uuid
     * @param int    $action  动作 ID
     * @param array  $data    位置/面朝方向数据
     */
    public function handleAction(string $uuid, int $action, array $data = []): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        switch ($action) {
            case self::ACTION_START_BREAK:
            case self::ACTION_ABORT_BREAK:
            case self::ACTION_STOP_BREAK:
                // 由 BlockModule 处理挖掘/放置
                break;

            case self::ACTION_RELEASE_ITEM:
                // 扔出物品
                $this->players[$uuid]['lastActivity'] = time();
                break;

            case self::ACTION_STOP_SLEEPING:
                // 停止睡觉
                break;

            case self::ACTION_RESPAWN:
                // 重生
                $this->respawn($uuid);
                break;

            case self::ACTION_JUMP:
                // 跳跃
                break;

            case self::ACTION_START_SPRINT:
                $this->players[$uuid]['sprinting'] = true;
                break;

            case self::ACTION_STOP_SPRINT:
                $this->players[$uuid]['sprinting'] = false;
                break;

            case self::ACTION_START_SNEAK:
                $this->players[$uuid]['sneaking'] = true;
                break;

            case self::ACTION_STOP_SNEAK:
                $this->players[$uuid]['sneaking'] = false;
                break;

            case self::ACTION_DIMENSION_CHANGE:
                // 维度切换
                break;
        }
    }

    /**
     * 重生玩家（RespawnPacket 0xb3）
     *
     * @param string $uuid
     * @param array  $respawnPosition [x, y, z] 可选
     */
    public function respawn(string $uuid, array $respawnPosition = []): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        $player = &$this->players[$uuid];

        // 恢复生命值和饥饿值
        $player['health'] = $player['maxHealth'];
        $player['hungry'] = 20;
        $player['saturation'] = 20;
        $player['experience'] = 0;
        $player['expLevel'] = 0;
        $player['spawned'] = true;
        $player['lastActivity'] = time();

        // 设置重生位置
        if (!empty($respawnPosition)) {
            $player['position'] = $respawnPosition;
        }

        // 发送 RespawnPacket (0xb3)
        $packet = [
            'packetId' => self::PK_RESPAWN,
            'x'        => (float) $player['position'][0],
            'y'        => (float) $player['position'][1],
            'z'        => (float) $player['position'][2],
        ];
        $this->sendPacket($uuid, $packet);
    }

    /**
     * 处理玩家移动（MovePlayerPacket 0x9d）
     *
     * @param string $uuid
     * @param array  $position [x, y, z]
     * @param float  $yaw
     * @param float  $pitch
     * @param float  $bodyYaw
     * @param int    $mode    0=normal, 1=reset, 2=rotation
     */
    public function handleMove(string $uuid, array $position, float $yaw = 0.0, float $pitch = 0.0, float $bodyYaw = 0.0, int $mode = 0): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        $player = &$this->players[$uuid];

        // 更新位置和朝向
        $player['position'] = $position;
        $player['yaw'] = $yaw;
        $player['pitch'] = $pitch;
        $player['bodyYaw'] = $bodyYaw;
        $player['lastActivity'] = time();

        // 广播 MovePlayerPacket (0x9d) 给其他在线玩家
        $packet = [
            'packetId' => self::PK_MOVE_PLAYER,
            'eid'      => $this->getEntityId($uuid),
            'x'        => (float) $position[0],
            'y'        => (float) $position[1],
            'z'        => (float) $position[2],
            'yaw'      => $yaw,
            'bodyYaw'  => $bodyYaw,
            'pitch'    => $pitch,
            'mode'     => $mode,
            'onGround' => true,
        ];

        foreach ($this->getOnlinePlayers() as $p) {
            if ($p['uuid'] !== $uuid && $this->canSee($p['uuid'], $uuid)) {
                $this->sendPacket($p['uuid'], $packet);
            }
        }
    }

    /**
     * 同步玩家数据到客户端
     * 发送 MovePlayer (0x94/0x9d)、SetHealth (0xA9/0xB0)、SendInventory (0xAD/0xB9)
     *
     * @param string $uuid
     */
    public function syncPlayer(string $uuid): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        $player = $this->players[$uuid];

        // 1. 发送 MovePlayer (0x9d) — 位置同步
        $movePacket = [
            'packetId' => self::PK_MOVE_PLAYER,
            'eid'      => $this->getEntityId($uuid),
            'x'        => (float) $player['position'][0],
            'y'        => (float) $player['position'][1],
            'z'        => (float) $player['position'][2],
            'yaw'      => $player['yaw'],
            'bodyYaw'  => $player['bodyYaw'],
            'pitch'    => $player['pitch'],
            'mode'     => 0,
            'onGround' => true,
        ];
        $this->sendPacket($uuid, $movePacket);

        // 2. 发送 SetHealth (0xB0) — 生命值同步
        $healthPacket = [
            'packetId' => self::PK_SET_HEALTH,
            'health'   => (int) $player['health'],
        ];
        $this->sendPacket($uuid, $healthPacket);

        // 3. 发送 SendInventory (CONTAINER_SET_CONTENT_PACKET 0xB9)
        $this->sendInventory($uuid);
    }

    /**
     * 发送物品栏（CONTAINER_SET_CONTENT_PACKET 0xB9）
     *
     * @param string $uuid
     * @param int    $containerId 容器 ID（0=hotbar+inventory）
     */
    public function sendInventory(string $uuid, int $containerId = 0): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        $player = $this->players[$uuid];
        $items = [];

        // 将物品栏数组序列化为客户端格式
        foreach ($player['inventory'] as $slot => $item) {
            if ($item !== null) {
                $items[] = [
                    'slot'   => $slot,
                    'id'     => $item['id'] ?? 0,
                    'damage' => $item['damage'] ?? 0,
                    'count'  => $item['count'] ?? 1,
                ];
            }
        }

        $packet = [
            'packetId'       => self::PK_CONTAINER_SET_CONTENT,
            'containerId'    => $containerId,
            'items'          => $items,
            'hotbarSlot'     => $player['hotbarSlot'] ?? 0,
        ];
        $this->sendPacket($uuid, $packet);
    }

    /**
     * 设置玩家生命值（SetHealth 0xB0）
     *
     * @param string $uuid
     * @param int    $health
     */
    public function setHealth(string $uuid, int $health): void
    {
        if (!isset($this->players[$uuid])) {
            return;
        }

        $this->players[$uuid]['health'] = max(0, min($health, $this->players[$uuid]['maxHealth']));

        $packet = [
            'packetId' => self::PK_SET_HEALTH,
            'health'   => (int) $this->players[$uuid]['health'],
        ];
        $this->sendPacket($uuid, $packet);
    }

    /**
     * 获取玩家数据
     */
    public function getPlayer(string $uuid): ?array
    {
        return $this->players[$uuid] ?? null;
    }

    /**
     * 获取在线玩家列表
     *
     * @return array
     */
    public function getOnlinePlayers(): array
    {
        return array_filter($this->players, fn($p) => $p['connected']);
    }

    /**
     * 设置玩家游戏模式
     */
    public function setGameMode(string $uuid, int $gameMode): void
    {
        if (isset($this->players[$uuid])) {
            $this->players[$uuid]['gameMode'] = $gameMode & 0x03;
            if ($this->players[$uuid]['gameMode'] === 1) {
                // Creative: 满生命值
                $this->players[$uuid]['health'] = $this->players[$uuid]['maxHealth'];
            }
        }
    }

    /**
     * 获取玩家实体 ID
     */
    public function getEntityId(string $uuid): int
    {
        // 简单哈希映射实体 ID
        $sum = 0;
        for ($i = 0, $len = strlen($uuid); $i < $len; $i++) {
            $sum += ord($uuid[$i]);
        }
        return ($sum % 1000) + 100;
    }

    /**
     * 检查玩家 A 是否能看见玩家 B
     */
    private function canSee(string $a, string $b): bool
    {
        return isset($this->players[$a])
            && isset($this->players[$b])
            && $this->players[$a]['connected']
            && $this->players[$b]['connected'];
    }

    /**
     * 发送数据包到指定玩家
     * 此处为抽象接口，实际由 NetworkModule 实现
     */
    private function sendPacket(string $uuid, array $packet): void
    {
        // 由 NetworkModule 接管实际发送
        // 这里仅记录到日志以便调试
        if (defined('DEBUG') && DEBUG) {
            error_log("[PlayerModule] Packet 0x" . dechex($packet['packetId']) . " -> $uuid");
        }
    }

    /**
     * 获取协议版本
     */
    public function getProtocol(string $uuid): int
    {
        return $this->players[$uuid]['protocol'] ?? 0;
    }

    /**
     * 获取在线玩家数量
     */
    public function getOnlineCount(): int
    {
        return count($this->getOnlinePlayers());
    }

    /**
     * 设置最大玩家数
     */
    public function setMaxPlayers(int $max): void
    {
        $this->maxPlayers = max(1, $max);
    }

    // ====================================================================
    // 网络会话管理（Batch 5：与 NetworkModule/RakSession 对接）
    // ====================================================================

    private ?LevelModule $level = null;

    /** @var array<string, PlayerSession> address:port → PlayerSession */
    private array $netSessions = [];

    public function setLevel(LevelModule $level): void
    {
        $this->level = $level;
    }

    public function createSession(RakSession $rak): ?PlayerSession
    {
        if ($this->level === null) {
            return null;
        }
        $session = new PlayerSession($rak, $this, $this->level);
        $this->netSessions[$rak->getAddress() . ":" . $rak->getPort()] = $session;
        return $session;
    }

    public function closeSession(PlayerSession $session): void
    {
        $rak = $session->getRakSession();
        unset($this->netSessions[$rak->getAddress() . ":" . $rak->getPort()]);
        $rak->player = null;
    }

    public function getSessionCount(): int
    {
        return count($this->netSessions);
    }

    /**
     * 向所有已出生玩家广播聊天（TYPE_CHAT）
     */
    public function broadcastMessage(string $source, string $message): void
    {
        foreach ($this->netSessions as $session) {
            if ($session->state === PlayerSession::STATE_SPAWNED) {
                $session->sendChat($source, $message);
            }
        }
    }
}

/**
 * 玩家网络会话：RakNet 连接之上的协议 70 状态机
 *
 * 登录序列（对照原版 Player::processLogin / doFirstSpawn 的最小可行集）：
 *   Login(0x8f) → 协议校验 → PlayStatus(SUCCESS)
 *   → StartGame → 3×3 区块（Batch 包裹 FullChunkData）
 *   → SetSpawnPosition → SetDifficulty → SetTime → AdventureSettings
 *   → PlayStatus(PLAYER_SPAWN)
 */
class PlayerSession
{
    public const STATE_HANDSHAKE = 0; // 等待 Login
    public const STATE_LOGGED_IN = 1;
    public const STATE_SPAWNED = 2;
    public const STATE_CLOSED = 3;

    private static int $eidCounter = 1;

    public string $username = '';
    public int $state = self::STATE_HANDSHAKE;
    public int $eid = 0;

    public function __construct(
        private RakSession $rak,
        private PlayerModule $module,
        private LevelModule $level
    ) {
        $this->eid = self::$eidCounter++;
    }

    public function getRakSession(): RakSession
    {
        return $this->rak;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * 处理一个原始游戏包（含 ID 头）
     */
    public function handlePacket(string $buffer): void
    {
        if ($this->state === self::STATE_CLOSED || $buffer === '') {
            return;
        }
        $pid = ord($buffer[0]);

        if ($pid === MinecraftProtocol::BATCH_PACKET) {
            $batch = new BatchPacket();
            $batch->decode($buffer);
            foreach (BatchPacket::unpackPackets($batch->payload) as $raw) {
                $this->handlePacket($raw);
            }
            return;
        }

        $pk = PacketRegistry::decode($buffer);
        if ($pk === null) {
            return; // 未实现的包：忽略（客户端会发很多非关键包）
        }

        if ($pk instanceof LoginPacket) {
            $this->processLogin($pk);
        } elseif ($pk instanceof RequestChunkRadiusPacket) {
            $resp = new ChunkRadiusUpdatePacket();
            $resp->radius = min(max($pk->radius, 1), 8);
            $this->sendPacket($resp);
        } elseif ($pk instanceof MovePlayerPacket) {
            // 回声广播暂略；本地位置更新在 Batch 6 接入
        }
    }

    private function processLogin(LoginPacket $pk): void
    {
        if ($this->state !== self::STATE_HANDSHAKE) {
            return; // 忽略重复登录
        }

        if (!in_array($pk->protocol1, MinecraftProtocol::ACCEPTED_PROTOCOLS, true)) {
            $this->sendPlayStatus(PlayStatusPacket::LOGIN_FAILED_CLIENT);
            $this->close();
            return;
        }

        $this->username = $pk->username;
        $this->state = self::STATE_LOGGED_IN;

        // 1. 登录成功
        $this->sendPlayStatus(PlayStatusPacket::LOGIN_SUCCESS);

        // 2. StartGame（出生点与种子来自世界）
        [$sx, $sy, $sz] = $this->level->getSpawnPosition();
        $sg = new StartGamePacket();
        $sg->seed = $this->level->getSeed();
        $sg->dimension = 0;
        $sg->generator = 0;
        $sg->gamemode = 0;
        $sg->eid = $this->eid;
        $sg->spawnX = $sx;
        $sg->spawnY = $sy;
        $sg->spawnZ = $sz;
        $sg->x = $sx + 0.5;
        $sg->y = $sy + 0.5;
        $sg->z = $sz + 0.5;
        $sg->unknown = '';
        $this->sendPacket($sg);

        // 3. 出生点周围 3×3 区块（Batch 包裹 FullChunkData，对照原版发送方式）
        for ($cx = -1; $cx <= 1; ++$cx) {
            for ($cz = -1; $cz <= 1; ++$cz) {
                $chunk = $this->level->getChunk($cx, $cz);
                if ($chunk === null) {
                    continue;
                }
                $fcd = new FullChunkDataPacket();
                $fcd->chunkX = $cx;
                $fcd->chunkZ = $cz;
                $fcd->order = FullChunkDataPacket::ORDER_COLUMNS;
                $fcd->data = FullChunkDataPacket::buildPayload($chunk);
                $batch = new BatchPacket();
                $batch->payload = BatchPacket::packPackets([$fcd->encode()]);
                $this->sendPacket($batch);
            }
        }

        // 4. 世界状态
        $sp = new SetSpawnPositionPacket();
        [$sp->x, $sp->y, $sp->z] = $this->level->getSpawnPosition();
        $this->sendPacket($sp);

        $diff = new SetDifficultyPacket();
        $diff->difficulty = 0;
        $this->sendPacket($diff);

        $time = new SetTimePacket();
        $time->time = $this->level->getTime();
        $time->started = true;
        $this->sendPacket($time);

        $adv = new AdventureSettingsPacket();
        $adv->flags = 0;
        $adv->userPermission = 0;
        $adv->globalPermission = 0;
        $this->sendPacket($adv);

        // 5. 出生确认
        $this->sendPlayStatus(PlayStatusPacket::PLAYER_SPAWN);
        $this->state = self::STATE_SPAWNED;
    }

    public function sendPlayStatus(int $status): void
    {
        $pk = new PlayStatusPacket();
        $pk->status = $status;
        $this->sendPacket($pk);
    }

    public function sendPacket(GamePacket $pk): void
    {
        $this->rak->sendGamePacket($pk->encode());
    }

    public function sendChat(string $source, string $message): void
    {
        $pk = new TextPacket();
        $pk->type = TextPacket::TYPE_CHAT;
        $pk->source = $source;
        $pk->message = $message;
        $this->sendPacket($pk);
    }

    public function sendMessage(string $message): void
    {
        $pk = new TextPacket();
        $pk->type = TextPacket::TYPE_RAW;
        $pk->message = $message;
        $this->sendPacket($pk);
    }

    public function close(): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }
        $this->state = self::STATE_CLOSED;
        $dk = new DisconnectPacket();
        $dk->message = 'disconnected';
        $this->rak->sendGamePacket($dk->encode());
        $this->module->closeSession($this);
    }
}