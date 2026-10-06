<?php
/**
 * Module: MCPE 游戏协议层（protocol 70 / MCPE 0.14.3）
 *
 * 包 ID 常量与包编解码格式逐字段对照原版
 * Genisys 0.14.3（/workspace/genisys-0143/src/pocketmine/network/protocol/）。
 * 数值字段一律小端（与 RakNet 负载约定一致）。
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\BinaryStream;

/**
 * 协议常量（对照原版 protocol/Info.php）
 */
final class MinecraftProtocol
{
    public const CURRENT_PROTOCOL = 70;
    public const ACCEPTED_PROTOCOLS = [45, 46, 60, 70];
    public const MINECRAFT_VERSION = 'v0.14.3';

    public const LOGIN_PACKET                  = 0x8f;
    public const PLAY_STATUS_PACKET            = 0x90;
    public const DISCONNECT_PACKET             = 0x91;
    public const BATCH_PACKET                  = 0x92;
    public const TEXT_PACKET                   = 0x93;
    public const SET_TIME_PACKET               = 0x94;
    public const START_GAME_PACKET             = 0x95;
    public const ADD_PLAYER_PACKET             = 0x96;
    public const REMOVE_PLAYER_PACKET          = 0x97;
    public const ADD_ENTITY_PACKET             = 0x98;
    public const REMOVE_ENTITY_PACKET          = 0x99;
    public const ADD_ITEM_ENTITY_PACKET        = 0x9a;
    public const TAKE_ITEM_ENTITY_PACKET       = 0x9b;
    public const MOVE_ENTITY_PACKET            = 0x9c;
    public const MOVE_PLAYER_PACKET            = 0x9d;
    public const REMOVE_BLOCK_PACKET           = 0x9e;
    public const UPDATE_BLOCK_PACKET           = 0x9f;
    public const ADD_PAINTING_PACKET           = 0xa0;
    public const EXPLODE_PACKET                = 0xa1;
    public const LEVEL_EVENT_PACKET            = 0xa2;
    public const BLOCK_EVENT_PACKET            = 0xa3;
    public const ENTITY_EVENT_PACKET           = 0xa4;
    public const MOB_EFFECT_PACKET             = 0xa5;
    public const UPDATE_ATTRIBUTES_PACKET      = 0xa6;
    public const MOB_EQUIPMENT_PACKET          = 0xa7;
    public const MOB_ARMOR_EQUIPMENT_PACKET    = 0xa8;
    public const INTERACT_PACKET               = 0xa9;
    public const USE_ITEM_PACKET               = 0xaa;
    public const PLAYER_ACTION_PACKET          = 0xab;
    public const HURT_ARMOR_PACKET             = 0xac;
    public const SET_ENTITY_DATA_PACKET        = 0xad;
    public const SET_ENTITY_MOTION_PACKET      = 0xae;
    public const SET_ENTITY_LINK_PACKET        = 0xaf;
    public const SET_HEALTH_PACKET             = 0xb0;
    public const SET_SPAWN_POSITION_PACKET     = 0xb1;
    public const ANIMATE_PACKET                = 0xb2;
    public const RESPAWN_PACKET                = 0xb3;
    public const DROP_ITEM_PACKET              = 0xb4;
    public const CONTAINER_OPEN_PACKET         = 0xb5;
    public const CONTAINER_CLOSE_PACKET        = 0xb6;
    public const CONTAINER_SET_SLOT_PACKET     = 0xb7;
    public const CONTAINER_SET_DATA_PACKET     = 0xb8;
    public const CONTAINER_SET_CONTENT_PACKET  = 0xb9;
    public const CRAFTING_DATA_PACKET          = 0xba;
    public const CRAFTING_EVENT_PACKET         = 0xbb;
    public const ADVENTURE_SETTINGS_PACKET     = 0xbc;
    public const BLOCK_ENTITY_DATA_PACKET      = 0xbd;
    public const PLAYER_INPUT_PACKET           = 0xbe;
    public const FULL_CHUNK_DATA_PACKET        = 0xbf;
    public const SET_DIFFICULTY_PACKET         = 0xc0;
    public const CHANGE_DIMENSION_PACKET       = 0xc1;
    public const SET_PLAYER_GAMETYPE_PACKET    = 0xc2;
    public const PLAYER_LIST_PACKET            = 0xc3;
    public const REQUEST_CHUNK_RADIUS_PACKET   = 0xc8;
    public const CHUNK_RADIUS_UPDATE_PACKET    = 0xc9;
    public const ITEM_FRAME_DROP_ITEM_PACKET   = 0xca;
}

/**
 * 游戏包基类。encode() 返回含 ID 头的完整包；
 * decode() 接收含 ID 头的完整缓冲（对照原版 DataPacket::reset() 后 buffer[0] = ID）。
 */
abstract class GamePacket
{
    abstract public static function pid(): int;

    abstract public function encode(): string;

    abstract public function decode(string $data): void;

    protected static function header(string $body): string
    {
        return chr(static::pid()) . $body;
    }
}

final class LoginPacket extends GamePacket
{
    public string $username = '';
    public int $protocol1 = 0;
    public int $protocol2 = 0;
    public int $clientId = 0;
    public string $clientUUID = '';      // 16 字节原始 UUID
    public string $serverAddress = '';
    public string $clientSecret = '';
    public string $skinName = '';
    public string $skin = '';

    public static function pid(): int { return MinecraftProtocol::LOGIN_PACKET; }

    public function encode(): string
    {
        // 服务端不发送 Login
        return self::header('');
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1); // 跳过 ID
        $this->username = $s->getString();
        $this->protocol1 = $s->getSignedInt();
        $this->protocol2 = $s->getSignedInt();
        $this->clientId = $s->getLong();
        $this->clientUUID = $s->get(16);
        $this->serverAddress = $s->getString();
        $this->clientSecret = $s->getString();
        $this->skinName = $s->getString();
        $this->skin = $s->getString();
    }
}

final class PlayStatusPacket extends GamePacket
{
    public const LOGIN_SUCCESS = 0;
    public const LOGIN_FAILED_CLIENT = 1;
    public const LOGIN_FAILED_SERVER = 2;
    public const PLAYER_SPAWN = 3;

    public int $status = 0;

    public static function pid(): int { return MinecraftProtocol::PLAY_STATUS_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->status);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->status = $s->getSignedInt();
    }
}

final class DisconnectPacket extends GamePacket
{
    public string $message = '';

    public static function pid(): int { return MinecraftProtocol::DISCONNECT_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putString($this->message);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->message = $s->getString();
    }
}

/**
 * Batch：payload = zlib( 逐包拼接 [int LE 长度][ID+包体] )。
 * （对照原版 Network::processBatch / Player::getChunkCacheFromData）
 */
final class BatchPacket extends GamePacket
{
    public string $payload = '';

    public static function pid(): int { return MinecraftProtocol::BATCH_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt(strlen($this->payload));
        $s->put($this->payload);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $size = $s->getSignedInt();
        $this->payload = $s->get($size);
    }

    public static function packPackets(array $rawPackets): string
    {
        $inner = '';
        foreach ($rawPackets as $raw) {
            $inner .= pack('V', strlen($raw)) . $raw;
        }
        return (string)zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
    }

    /** @return string[] 解出的原始包列表（含 ID 头） */
    public static function unpackPackets(string $payload): array
    {
        $decoded = zlib_decode($payload, 64 * 1024 * 1024);
        if ($decoded === false || $decoded === '') {
            return [];
        }
        $s = new BinaryStream($decoded);
        $packets = [];
        while ($s->getOffset() < strlen($decoded)) {
            $len = $s->getSignedInt();
            if ($len <= 0) {
                break;
            }
            $packets[] = $s->get($len);
        }
        return $packets;
    }
}

final class TextPacket extends GamePacket
{
    public const TYPE_RAW = 0;
    public const TYPE_CHAT = 1;
    public const TYPE_TRANSLATION = 2;
    public const TYPE_POPUP = 3;
    public const TYPE_TIP = 4;
    public const TYPE_SYSTEM = 5;

    public int $type = self::TYPE_RAW;
    public string $source = '';
    public string $message = '';
    /** @var string[] */
    public array $parameters = [];

    public static function pid(): int { return MinecraftProtocol::TEXT_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putByte($this->type);
        if ($this->type === self::TYPE_POPUP || $this->type === self::TYPE_CHAT) {
            $s->putString($this->source);
            $s->putString($this->message);
        } elseif ($this->type === self::TYPE_TRANSLATION) {
            $s->putString($this->message);
            $s->putByte(count($this->parameters));
            foreach ($this->parameters as $p) {
                $s->putString($p);
            }
        } else {
            $s->putString($this->message);
        }
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->type = $s->getByte();
        switch ($this->type) {
            case self::TYPE_POPUP:
            case self::TYPE_CHAT:
                $this->source = $s->getString();
                $this->message = $s->getString();
                break;
            case self::TYPE_TRANSLATION:
                $this->message = $s->getString();
                $count = $s->getByte();
                for ($i = 0; $i < $count; ++$i) {
                    $this->parameters[] = $s->getString();
                }
                break;
            default:
                $this->message = $s->getString();
        }
    }
}

final class SetTimePacket extends GamePacket
{
    public int $time = 0;
    public bool $started = true;

    public static function pid(): int { return MinecraftProtocol::SET_TIME_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->time);
        $s->putByte($this->started ? 1 : 0);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->time = $s->getSignedInt();
        $this->started = $s->getByte() === 1;
    }
}

final class StartGamePacket extends GamePacket
{
    public int $seed = 0;
    public int $dimension = 0;
    public int $generator = 0;
    public int $gamemode = 0;
    public int $eid = 0;
    public int $spawnX = 0;
    public int $spawnY = 0;
    public int $spawnZ = 0;
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;
    public string $unknown = '';

    public static function pid(): int { return MinecraftProtocol::START_GAME_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->seed);
        $s->putByte($this->dimension);
        $s->putInt($this->generator);
        $s->putInt($this->gamemode);
        $s->putLong($this->eid);
        $s->putInt($this->spawnX);
        $s->putInt($this->spawnY);
        $s->putInt($this->spawnZ);
        $s->putFloat($this->x);
        $s->putFloat($this->y);
        $s->putFloat($this->z);
        $s->putByte(1);
        $s->putByte(1);
        $s->putByte(0);
        $s->putString($this->unknown);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->seed = $s->getSignedInt();
        $this->dimension = $s->getByte();
        $this->generator = $s->getSignedInt();
        $this->gamemode = $s->getSignedInt();
        $this->eid = $s->getLong();
        $this->spawnX = $s->getSignedInt();
        $this->spawnY = $s->getSignedInt();
        $this->spawnZ = $s->getSignedInt();
        $this->x = $s->getFloat();
        $this->y = $s->getFloat();
        $this->z = $s->getFloat();
        // 尾部 3 字节 + 字符串不再解析
    }
}

final class MovePlayerPacket extends GamePacket
{
    public const MODE_NORMAL = 0;
    public const MODE_RESET = 1;
    public const MODE_ROTATION = 2;

    public int $eid = 0;
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;
    public float $yaw = 0.0;
    public float $bodyYaw = 0.0;
    public float $pitch = 0.0;
    public int $mode = self::MODE_NORMAL;
    public bool $onGround = false;

    public static function pid(): int { return MinecraftProtocol::MOVE_PLAYER_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putLong($this->eid);
        $s->putFloat($this->x);
        $s->putFloat($this->y);
        $s->putFloat($this->z);
        $s->putFloat($this->yaw);
        $s->putFloat($this->bodyYaw);
        $s->putFloat($this->pitch);
        $s->putByte($this->mode);
        $s->putByte($this->onGround ? 1 : 0);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->eid = $s->getLong();
        $this->x = $s->getFloat();
        $this->y = $s->getFloat();
        $this->z = $s->getFloat();
        $this->yaw = $s->getFloat();
        $this->bodyYaw = $s->getFloat();
        $this->pitch = $s->getFloat();
        $this->mode = $s->getByte();
        $this->onGround = $s->getByte() > 0;
    }
}

final class UpdateBlockPacket extends GamePacket
{
    public const FLAG_NONE = 0b0000;
    public const FLAG_NEIGHBORS = 0b0001;
    public const FLAG_NETWORK = 0b0010;
    public const FLAG_NOGRAPHIC = 0b0100;
    public const FLAG_PRIORITY = 0b1000;
    public const FLAG_ALL = self::FLAG_NEIGHBORS | self::FLAG_NETWORK;
    public const FLAG_ALL_PRIORITY = self::FLAG_ALL | self::FLAG_PRIORITY;

    /** @var array<array{0:int,1:int,2:int,3:int,4:int,5:int}> [x, z, y, blockId, meta, flags] */
    public array $records = [];

    public static function pid(): int { return MinecraftProtocol::UPDATE_BLOCK_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt(count($this->records));
        foreach ($this->records as $r) {
            $s->putInt($r[0]);
            $s->putInt($r[1]);
            $s->putByte($r[2]);
            $s->putByte($r[3]);
            $s->putByte((($r[5] & 0x0f) << 4) | ($r[4] & 0x0f));
        }
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $count = $s->getSignedInt();
        for ($i = 0; $i < $count; ++$i) {
            $x = $s->getSignedInt();
            $z = $s->getSignedInt();
            $y = $s->getByte();
            $blockId = $s->getByte();
            $metaFlags = $s->getByte();
            $this->records[] = [$x, $z, $y, $blockId, $metaFlags & 0x0f, $metaFlags >> 4];
        }
    }
}

final class SetSpawnPositionPacket extends GamePacket
{
    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public static function pid(): int { return MinecraftProtocol::SET_SPAWN_POSITION_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->x);
        $s->putInt($this->y);
        $s->putInt($this->z);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->x = $s->getSignedInt();
        $this->y = $s->getSignedInt();
        $this->z = $s->getSignedInt();
    }
}

final class AdventureSettingsPacket extends GamePacket
{
    public int $flags = 0;
    public int $userPermission = 0;
    public int $globalPermission = 0;

    public static function pid(): int { return MinecraftProtocol::ADVENTURE_SETTINGS_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->flags);
        $s->putInt($this->userPermission);
        $s->putInt($this->globalPermission);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->flags = $s->getSignedInt();
        $this->userPermission = $s->getSignedInt();
        $this->globalPermission = $s->getSignedInt();
    }
}

final class FullChunkDataPacket extends GamePacket
{
    public const ORDER_COLUMNS = 0;
    public const ORDER_LAYERED = 1;

    public int $chunkX = 0;
    public int $chunkZ = 0;
    public int $order = self::ORDER_COLUMNS;
    public string $data = '';

    public static function pid(): int { return MinecraftProtocol::FULL_CHUNK_DATA_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->chunkX);
        $s->putInt($this->chunkZ);
        $s->putByte($this->order);
        $s->putInt(strlen($this->data));
        $s->put($this->data);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->chunkX = $s->getSignedInt();
        $this->chunkZ = $s->getSignedInt();
        $this->order = $s->getByte();
        $len = $s->getSignedInt();
        $this->data = $s->get($len);
    }

    /**
     * 对照原版 McRegion::requestChunkTask 的 ORDER_COLUMNS 载荷：
     * blocks(32768) + meta(16384) + skyLight(16384) + blockLight(16384)
     * + heightMap(256) + biomeColors(256×u32 BE) + extraData + tiles
     */
    public static function buildPayload(array $chunk): string
    {
        $blocks = pack('C*', ...array_values($chunk['blocks']));
        return $blocks
            . str_repeat("\x00", 16384)                     // meta
            . str_repeat("\xff", 16384)                     // skyLight
            . str_repeat("\x00", 16384)                     // blockLight
            . pack('C*', ...array_pad(array_values($chunk['heightMap']), 256, 0))
            . pack('N*', ...array_pad(array_values($chunk['biomeColors']), 256, 0))
            . pack('V', 0)                                  // extraData count = 0
            . '';                                           // tiles
    }
}

final class SetDifficultyPacket extends GamePacket
{
    public int $difficulty = 0;

    public static function pid(): int { return MinecraftProtocol::SET_DIFFICULTY_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->difficulty);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->difficulty = $s->getSignedInt();
    }
}

final class RequestChunkRadiusPacket extends GamePacket
{
    public int $radius = 0;

    public static function pid(): int { return MinecraftProtocol::REQUEST_CHUNK_RADIUS_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->radius);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->radius = $s->getSignedInt();
    }
}

final class ChunkRadiusUpdatePacket extends GamePacket
{
    public int $radius = 0;

    public static function pid(): int { return MinecraftProtocol::CHUNK_RADIUS_UPDATE_PACKET; }

    public function encode(): string
    {
        $s = new BinaryStream();
        $s->putInt($this->radius);
        return self::header($s->getBuffer());
    }

    public function decode(string $data): void
    {
        $s = new BinaryStream($data, 1);
        $this->radius = $s->getSignedInt();
    }
}

/**
 * 包注册表：ID → 类
 */
final class PacketRegistry
{
    /** @var array<int, class-string<GamePacket>> */
    private static ?array $map = null;

    /** @return array<int, class-string<GamePacket>> */
    public static function packets(): array
    {
        if (self::$map === null) {
            self::$map = [
                LoginPacket::pid() => LoginPacket::class,
                PlayStatusPacket::pid() => PlayStatusPacket::class,
                DisconnectPacket::pid() => DisconnectPacket::class,
                BatchPacket::pid() => BatchPacket::class,
                TextPacket::pid() => TextPacket::class,
                SetTimePacket::pid() => SetTimePacket::class,
                StartGamePacket::pid() => StartGamePacket::class,
                MovePlayerPacket::pid() => MovePlayerPacket::class,
                UpdateBlockPacket::pid() => UpdateBlockPacket::class,
                SetSpawnPositionPacket::pid() => SetSpawnPositionPacket::class,
                AdventureSettingsPacket::pid() => AdventureSettingsPacket::class,
                FullChunkDataPacket::pid() => FullChunkDataPacket::class,
                SetDifficultyPacket::pid() => SetDifficultyPacket::class,
                RequestChunkRadiusPacket::pid() => RequestChunkRadiusPacket::class,
                ChunkRadiusUpdatePacket::pid() => ChunkRadiusUpdatePacket::class,
            ];
        }
        return self::$map;
    }

    public static function isKnown(int $pid): bool
    {
        return isset(self::packets()[$pid]);
    }

    /** 从含 ID 头的完整缓冲解码；未知 ID 返回 null */
    public static function decode(string $raw): ?GamePacket
    {
        if ($raw === '') {
            return null;
        }
        $cls = self::packets()[ord($raw[0])] ?? null;
        if ($cls === null) {
            return null;
        }
        $pk = new $cls();
        $pk->decode($raw);
        return $pk;
    }
}

/**
 * 协议模块入口
 */
final class ProtocolModule
{
    public const VERSION = '1.0.0';
    public function getName(): string { return 'ProtocolModule'; }
    public function getVersion(): string { return self::VERSION; }
}
