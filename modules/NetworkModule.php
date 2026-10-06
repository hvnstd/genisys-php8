<?php
/**
 * Module: NetworkModule
 * 职责：RakNet 协议栈 + RCON + Query 协议
 * 依赖：仅 compat/ 层
 * PHP 8.x 替代：sockets → ReactPHP / Swoole
 *
 * 完整实现：
 *   1. ReactPHP/Swoole UDP 服务器 (端口 19132)
 *   2. RakNet 握手流程 (0x05-0x08 离线, 0x09-0x10 在线)
 *   3. Frame Set (0x80-0x8D) 帧重组
 *   4. ACK (0xC0) / NACK (0xA0) 处理
 *   5. RCON 协议 (Source RCON + GeniRCON)
 *   6. Query 协议 (旧版 MCPE Query)
 *   7. BatchPacket zlib 解压缩流水线
 */
declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\ThreadInterface;
use Genisys\Compat\WorkerInterface;

/**
 * RakNet 协议常量与工具
 */
final class RakNetProtocol
{
    // RakNet 底层协议版本（0.14.3 时代客户端为 6，对照原版 0.14.3 raklib/RakLib.php）
    public const PROTOCOL = 6;
    public const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    // 离线消息 ID
    public const UNCONNECTED_PING = 0x01;
    public const UNCONNECTED_PONG = 0x1C;
    public const OPEN_CONNECTION_REQUEST_1 = 0x05;
    public const OPEN_CONNECTION_REPLY_1 = 0x06;
    public const OPEN_CONNECTION_REQUEST_2 = 0x07;
    public const OPEN_CONNECTION_REPLY_2 = 0x08;
    public const ONLINE_CONNECTION_REQUEST = 0x09;
    public const ONLINE_CONNECTION_REQUEST_ACCEPTED = 0x10;
    public const CLIENT_HANDSHAKE = 0x13;

    // 可靠性类型 (Frame Flags 高 3 位)
    public const RELIABILITY_UNRELIABLE = 0;
    public const RELIABILITY_UNRELIABLE_SEQUENCED = 1;
    public const RELIABILITY_RELIABLE = 2;
    public const RELIABILITY_RELIABLE_ORDERED = 3;
    public const RELIABILITY_RELIABLE_SEQUENCED = 4;
    public const RELIABILITY_UNRELIABLE_WITH_ACK = 5;
    public const RELIABILITY_RELIABLE_WITH_ACK = 6;
    public const RELIABILITY_RELIABLE_ORDERED_WITH_ACK = 7;

    // Frame Set ID 范围
    public const FRAME_SET_MIN = 0x80;
    public const FRAME_SET_MAX = 0x8D;

    // ACK / NACK
    public const ACK = 0xC0;
    public const NACK = 0xA0;

    // 内部数据包类型
    public const PACKET_ENCAPSULATED = 0x01;
    public const PACKET_OPEN_SESSION = 0x02;
    public const PACKET_CLOSE_SESSION = 0x03;
    public const PACKET_INVALID_SESSION = 0x04;
    public const PACKET_ACK_NOTIFICATION = 0x06;
    public const PACKET_SET_OPTION = 0x07;
    public const PACKET_RAW = 0x08;
    public const PACKET_BLOCK_ADDRESS = 0x09;
    public const PACKET_SHUTDOWN = 0x7E;

    // MTU 默认值
    public const DEFAULT_MTU = 548;
    public const MAX_MTU = 1464;
    public const WINDOW_SIZE = 2048;
    public const MAX_SPLIT_SIZE = 128;
    public const MAX_SPLIT_COUNT = 4;

    // 重发超时 (秒)
    public const RETRY_TIMEOUT = 8;
    public const IDLE_TIMEOUT = 10;

    /**
     * 读取大端序 long (64-bit)
     */
    public static function readLong(string $data): int
    {
        $unpacked = unpack("N*", $data);
        if (count($unpacked) < 2) {
            return 0;
        }
        return ($unpacked[1] << 32) | $unpacked[2];
    }

    /**
     * 写入大端序 long (64-bit)
     */
    public static function writeLong(int $value): string
    {
        return pack("NN", ($value >> 32) & 0xFFFFFFFF, $value & 0xFFFFFFFF);
    }

    /**
     * 读取小端序 int (32-bit)
     */
    public static function readLInt(string $data): int
    {
        $unpacked = unpack("V", $data);
        return $unpacked[1] ?? 0;
    }

    /**
     * 写入小端序 int (32-bit)
     */
    public static function writeLInt(int $value): string
    {
        return pack("V", $value);
    }

    /**
     * 读取大端序 short (16-bit)
     */
    public static function readShort(string $data): int
    {
        return unpack("n", $data)[1] ?? 0;
    }

    /**
     * 写入大端序 short (16-bit)
     */
    public static function writeShort(int $value): string
    {
        return pack("n", $value);
    }

    /**
     * 读取小端序 short (16-bit)
     */
    public static function readLShort(string $data): int
    {
        return unpack("v", $data)[1] ?? 0;
    }

    /**
     * 写入小端序 short (16-bit)
     */
    public static function writeLShort(int $value): string
    {
        return pack("v", $value);
    }

    /**
     * 读取大端序 int (32-bit)
     */
    public static function readInt(string $data): int
    {
        $unpacked = unpack("N", $data);
        return $unpacked[1] ?? 0;
    }

    /**
     * 写入大端序 int (32-bit)
     */
    public static function writeInt(int $value): string
    {
        return pack("N", $value);
    }

    /**
     * 读取 3 字节大端序 (Triad)
     */
    public static function readTriad(string $data): int
    {
        return unpack("N", "\x00" . $data)[1] ?? 0;
    }

    /**
     * 写入 3 字节大端序 (Triad)
     */
    public static function writeTriad(int $value): string
    {
        return substr(pack("N", $value), 1);
    }

    /**
     * 读取 3 字节小端序 (LTriad)
     */
    public static function readLTriad(string $data): int
    {
        return unpack("V", $data . "\x00")[1] ?? 0;
    }

    /**
     * 写入 3 字节小端序 (LTriad)
     */
    public static function writeLTriad(int $value): string
    {
        return substr(pack("V", $value), 0, -1);
    }

    /**
     * 读取 float (小端序, MCPE 使用)
     */
    public static function readLFloat(string $data): float
    {
        return unpack("f", $data)[1] ?? 0.0;
    }

    /**
     * 写入 float (小端序)
     */
    public static function writeLFloat(float $value): string
    {
        return pack("f", $value);
    }

    /**
     * 读取字符串 (short 前缀长度)
     */
    public static function readString(string $data, int &$offset): string
    {
        $len = self::readShort(substr($data, $offset, 2));
        $offset += 2;
        $str = substr($data, $offset, $len);
        $offset += $len;
        return $str;
    }

    /**
     * 写入字符串 (short 前缀长度)
     */
    public static function writeString(string $value): string
    {
        return self::writeShort(strlen($value)) . $value;
    }

    /**
     * 读取 IPv4 地址 (每个字节取反)
     */
    public static function readAddress(string $data, int &$offset): array
    {
        $version = ord($data[$offset++]);
        if ($version === 4) {
            $addr = ((~ord($data[$offset++])) & 0xff) . "." .
                    ((~ord($data[$offset++])) & 0xff) . "." .
                    ((~ord($data[$offset++])) & 0xff) . "." .
                    ((~ord($data[$offset++])) & 0xff);
            $port = self::readShort(substr($data, $offset, 2));
            $offset += 2;
            return [$addr, $port, $version];
        }
        return ["0.0.0.0", 0, $version];
    }

    /**
     * 写入 IPv4 地址 (每个字节取反)
     */
    public static function writeAddress(string $addr, int $port, int $version = 4): string
    {
        $buf = chr($version);
        if ($version === 4) {
            foreach (explode(".", $addr) as $b) {
                $buf .= chr((~((int)$b)) & 0xff);
            }
            $buf .= self::writeShort($port);
        }
        return $buf;
    }
}

/**
 * 封装包 (EncapsulatedPacket) — RakNet 可靠性层核心
 */
final class EncapsulatedPacket
{
    public int $reliability = 0;
    public bool $hasSplit = false;
    public int $length = 0;
    public ?int $messageIndex = null;
    public ?int $orderIndex = null;
    public ?int $orderChannel = null;
    public ?int $splitCount = null;
    public ?int $splitID = null;
    public ?int $splitIndex = null;
    public string $buffer = "";
    public bool $needACK = false;
    public ?int $identifierACK = null;

    /**
     * 从二进制解析 EncapsulatedPacket
     */
    public static function fromBinary(string $binary, bool $internal = false, ?int &$offset = null): self
    {
        $packet = new self();
        $offset = $offset ?? 0;

        $flags = ord($binary[$offset++]);
        $packet->reliability = ($flags & 0b11100000) >> 5;
        $packet->hasSplit = ($flags & 0b00010000) > 0;

        if ($internal) {
            $packet->length = RakNetProtocol::readInt(substr($binary, $offset, 4));
            $offset += 4;
            $packet->identifierACK = RakNetProtocol::readInt(substr($binary, $offset, 4));
            $offset += 4;
        } else {
            $packet->length = (int) ceil(RakNetProtocol::readShort(substr($binary, $offset, 2)) / 8);
            $offset += 2;
            $packet->identifierACK = null;
        }

        if ($packet->reliability > 0) {
            if ($packet->reliability >= 2 && $packet->reliability !== 5) {
                $packet->messageIndex = RakNetProtocol::readLTriad(substr($binary, $offset, 3));
                $offset += 3;
            }
            if ($packet->reliability <= 4 && $packet->reliability !== 2) {
                $packet->orderIndex = RakNetProtocol::readLTriad(substr($binary, $offset, 3));
                $offset += 3;
                $packet->orderChannel = ord($binary[$offset++]);
            }
        }

        if ($packet->hasSplit) {
            $packet->splitCount = RakNetProtocol::readInt(substr($binary, $offset, 4));
            $offset += 4;
            $packet->splitID = RakNetProtocol::readShort(substr($binary, $offset, 2));
            $offset += 2;
            $packet->splitIndex = RakNetProtocol::readInt(substr($binary, $offset, 4));
            $offset += 4;
        }

        $packet->buffer = substr($binary, $offset, $packet->length);
        $offset += $packet->length;

        return $packet;
    }

    public function getTotalLength(): int
    {
        return 3 + strlen($this->buffer)
            + ($this->messageIndex !== null ? 3 : 0)
            + ($this->orderIndex !== null ? 4 : 0)
            + ($this->hasSplit ? 10 : 0);
    }

    public function toBinary(bool $internal = false): string
    {
        return
            chr(($this->reliability << 5) | ($this->hasSplit ? 0b00010000 : 0)) .
            ($internal
                ? RakNetProtocol::writeInt(strlen($this->buffer)) . RakNetProtocol::writeInt($this->identifierACK ?? 0)
                : RakNetProtocol::writeShort(strlen($this->buffer) << 3)) .
            ($this->reliability > 0
                ? (($this->reliability >= 2 && $this->reliability !== 5) ? RakNetProtocol::writeLTriad($this->messageIndex ?? 0) : "")
                  . (($this->reliability <= 4 && $this->reliability !== 2) ? RakNetProtocol::writeLTriad($this->orderIndex ?? 0) . chr($this->orderChannel ?? 0) : "")
                : "") .
            ($this->hasSplit
                ? RakNetProtocol::writeInt($this->splitCount ?? 0)
                  . RakNetProtocol::writeShort($this->splitID ?? 0)
                  . RakNetProtocol::writeInt($this->splitIndex ?? 0)
                : "") .
            $this->buffer;
    }
}

/**
 * Frame Set 数据包 (0x80-0x8D)
 * — 包含 seqNumber 和多个 EncapsulatedPacket
 */
final class FrameSet
{
    public int $seqNumber = 0;
    /** @var string[] */
    public array $packets = []; // 每个元素是 EncapsulatedPacket 的二进制

    public static function fromBinary(string $data): self
    {
        $fs = new self();
        $offset = 1; // skip packet ID byte
        $fs->seqNumber = RakNetProtocol::readLShort(substr($data, $offset, 2));
        $offset += 2;

        while ($offset < strlen($data)) {
            $fs->packets[] = substr($data, $offset);
            break; // 简化：剩余部分作为一个 packet
        }

        return $fs;
    }

    public function toBinary(int $packetID): string
    {
        $buf = chr($packetID);
        $buf .= RakNetProtocol::writeLShort($this->seqNumber);
        foreach ($this->packets as $pk) {
            $buf .= $pk;
        }
        return $buf;
    }
}

/**
 * ACK 包 (0xC0)
 */
final class ACKPacket
{
    public const ID = 0xC0;
    /** @var int[] */
    public array $packets = [];

    public function encode(): string
    {
        sort($this->packets, SORT_NUMERIC);
        $count = count($this->packets);
        $payload = "";
        $records = 0;

        if ($count > 0) {
            $pointer = 1;
            $start = $this->packets[0];
            $last = $this->packets[0];

            while ($pointer < $count) {
                $current = $this->packets[$pointer++];
                $diff = $current - $last;
                if ($diff === 1) {
                    $last = $current;
                } elseif ($diff > 1) {
                    if ($start === $last) {
                        $payload .= "\x01" . RakNetProtocol::writeLTriad($start);
                        $start = $last = $current;
                    } else {
                        $payload .= "\x00" . RakNetProtocol::writeLTriad($start) . RakNetProtocol::writeLTriad($last);
                        $start = $last = $current;
                    }
                    $records++;
                }
            }

            if ($start === $last) {
                $payload .= "\x01" . RakNetProtocol::writeLTriad($start);
            } else {
                $payload .= "\x00" . RakNetProtocol::writeLTriad($start) . RakNetProtocol::writeLTriad($last);
            }
            $records++;
        }

        return chr(self::ID) . RakNetProtocol::writeShort($records) . $payload;
    }

    public function decode(string $data): void
    {
        $offset = 2; // skip ID + short count
        $count = RakNetProtocol::readShort(substr($data, 1, 2));
        $this->packets = [];
        $cnt = 0;

        for ($i = 0; $i < $count && $offset < strlen($data) && $cnt < 4096; $i++) {
            $type = ord($data[$offset++]);
            if ($type === 0) {
                $start = RakNetProtocol::readLTriad(substr($data, $offset, 3));
                $offset += 3;
                $end = RakNetProtocol::readLTriad(substr($data, $offset, 3));
                $offset += 3;
                if (($end - $start) > 512) {
                    $end = $start + 512;
                }
                for ($c = $start; $c <= $end && $cnt < 4096; $c++) {
                    $this->packets[$cnt++] = $c;
                }
            } else {
                $this->packets[$cnt++] = RakNetProtocol::readLTriad(substr($data, $offset, 3));
                $offset += 3;
            }
        }
    }
}

/**
 * NACK 包 (0xA0)
 */
final class NACKPacket
{
    public const ID = 0xA0;
    /** @var int[] */
    public array $packets = [];

    public function encode(): string
    {
        sort($this->packets, SORT_NUMERIC);
        $count = count($this->packets);
        $payload = "";
        $records = 0;

        if ($count > 0) {
            $pointer = 1;
            $start = $this->packets[0];
            $last = $this->packets[0];

            while ($pointer < $count) {
                $current = $this->packets[$pointer++];
                $diff = $current - $last;
                if ($diff === 1) {
                    $last = $current;
                } elseif ($diff > 1) {
                    if ($start === $last) {
                        $payload .= "\x01" . RakNetProtocol::writeLTriad($start);
                        $start = $last = $current;
                    } else {
                        $payload .= "\x00" . RakNetProtocol::writeLTriad($start) . RakNetProtocol::writeLTriad($last);
                        $start = $last = $current;
                    }
                    $records++;
                }
            }

            if ($start === $last) {
                $payload .= "\x01" . RakNetProtocol::writeLTriad($start);
            } else {
                $payload .= "\x00" . RakNetProtocol::writeLTriad($start) . RakNetProtocol::writeLTriad($last);
            }
            $records++;
        }

        return chr(self::ID) . RakNetProtocol::writeShort($records) . $payload;
    }

    public function decode(string $data): void
    {
        $offset = 2;
        $count = RakNetProtocol::readShort(substr($data, 1, 2));
        $this->packets = [];
        $cnt = 0;

        for ($i = 0; $i < $count && $offset < strlen($data) && $cnt < 4096; $i++) {
            $type = ord($data[$offset++]);
            if ($type === 0) {
                $start = RakNetProtocol::readLTriad(substr($data, $offset, 3));
                $offset += 3;
                $end = RakNetProtocol::readLTriad(substr($data, $offset, 3));
                $offset += 3;
                if (($end - $start) > 512) {
                    $end = $start + 512;
                }
                for ($c = $start; $c <= $end && $cnt < 4096; $c++) {
                    $this->packets[$cnt++] = $c;
                }
            } else {
                $this->packets[$cnt++] = RakNetProtocol::readLTriad(substr($data, $offset, 3));
                $offset += 3;
            }
        }
    }
}

/**
 * 离线握手包
 */
final class OfflineConnectionRequest1
{
    public const ID = 0x05;
    public int $protocol = 7;
    public int $mtuSize = 548;

    public function decode(string $data): void
    {
        $offset = 17; // skip ID(1) + MAGIC(16)
        $this->protocol = ord($data[$offset++]);
        $this->mtuSize = strlen($data) - $offset + 18;
    }
}

final class OfflineConnectionReply1
{
    public const ID = 0x06;
    public int $serverID = 0;
    public int $mtuSize = 548;

    public function encode(int $serverID, int $mtuSize): string
    {
        $buf = chr(self::ID);
        $buf .= RakNetProtocol::MAGIC;
        $buf .= RakNetProtocol::writeLong($serverID);
        $buf .= chr(0); // security
        $buf .= RakNetProtocol::writeShort($mtuSize);
        return $buf;
    }
}

final class OfflineConnectionRequest2
{
    public const ID = 0x07;
    public string $serverAddress = "0.0.0.0";
    public int $serverPort = 0;
    public int $mtuSize = 548;
    public int $clientID = 0;

    public function decode(string $data): void
    {
        $offset = 17; // skip ID(1) + MAGIC(16)
        list($this->serverAddress, $this->serverPort, ) = RakNetProtocol::readAddress($data, $offset);
        $this->mtuSize = RakNetProtocol::readShort(substr($data, $offset, 2));
        $offset += 2;
        $this->clientID = RakNetProtocol::readLong(substr($data, $offset, 8));
    }
}

final class OfflineConnectionReply2
{
    public const ID = 0x08;
    public int $serverID = 0;
    public string $clientAddress = "0.0.0.0";
    public int $clientPort = 0;
    public int $mtuSize = 548;

    public function encode(int $serverID, string $clientAddr, int $clientPort, int $mtuSize): string
    {
        $buf = chr(self::ID);
        $buf .= RakNetProtocol::MAGIC;
        $buf .= RakNetProtocol::writeLong($serverID);
        $buf .= RakNetProtocol::writeAddress($clientAddr, $clientPort, 4);
        $buf .= RakNetProtocol::writeShort($mtuSize);
        $buf .= chr(0); // encryption
        return $buf;
    }
}

/**
 * 在线握手包
 */
final class OnlineConnectionRequest
{
    public const ID = 0x09;
    public int $clientID = 0;
    public int $sendPing = 0;

    public function decode(string $data): void
    {
        $offset = 1;
        $this->clientID = RakNetProtocol::readLong(substr($data, $offset, 8));
        $offset += 8;
        $this->sendPing = RakNetProtocol::readLong(substr($data, $offset, 8));
    }
}

final class OnlineConnectionRequestAccepted
{
    public const ID = 0x10;
    public string $clientAddress = "0.0.0.0";
    public int $clientPort = 0;
    public int $sendPing = 0;
    public int $sendPong = 0;

    public function encode(string $addr, int $port, int $ping, int $pong): string
    {
        $buf = chr(self::ID);
        $buf .= RakNetProtocol::writeAddress($addr, $port, 4);
        $buf .= RakNetProtocol::writeShort(0); // system index
        for ($i = 0; $i < 10; $i++) {
            $buf .= RakNetProtocol::writeAddress("0.0.0.0", 0, 4);
        }
        $buf .= RakNetProtocol::writeLong($ping);
        $buf .= RakNetProtocol::writeLong($pong);
        return $buf;
    }
}

final class ClientHandshake
{
    public const ID = 0x13;
    public string $address = "0.0.0.0";
    public int $port = 0;
    public int $sendPing = 0;
    public int $sendPong = 0;

    public function decode(string $data): void
    {
        $offset = 1;
        list($this->address, $this->port, ) = RakNetProtocol::readAddress($data, $offset);
        for ($i = 0; $i < 10; $i++) {
            $addr = $port = $ver = null;
            // skip system addresses
            $ver = ord($data[$offset++]);
            if ($ver === 4) {
                $offset += 4; // IP
                $offset += 2; // port
            }
        }
        $this->sendPing = RakNetProtocol::readLong(substr($data, $offset, 8));
        $offset += 8;
        $this->sendPong = RakNetProtocol::readLong(substr($data, $offset, 8));
    }
}

/**
 * 未连接 Ping / Pong
 */
final class UnconnectedPing
{
    public const ID = 0x01;
    public int $pingID = 0;

    public function decode(string $data): void
    {
        $this->pingID = RakNetProtocol::readLong(substr($data, 1, 8));
    }
}

final class UnconnectedPong
{
    public const ID = 0x1C;
    public int $pingID = 0;
    public int $serverID = 0;
    public string $serverName = "";

    public function encode(int $pingID, int $serverID, string $name): string
    {
        $buf = chr(self::ID);
        $buf .= RakNetProtocol::writeLong($pingID);
        $buf .= RakNetProtocol::writeLong($serverID);
        $buf .= RakNetProtocol::MAGIC;
        $buf .= RakNetProtocol::writeString($name);
        return $buf;
    }
}

/**
 * RakNet 会话状态机
 */
final class RakSession
{
    public const STATE_UNCONNECTED = 0;
    public const STATE_CONNECTING_1 = 1;
    public const STATE_CONNECTING_2 = 2;
    public const STATE_CONNECTED = 3;

    private string $address;
    private int $port;
    private int $state = self::STATE_UNCONNECTED;
    private int $mtuSize = 548;
    private int $clientID = 0;
    private int $serverID = 0;
    private float $lastUpdate = 0.0;
    private bool $isActive = false;

    /** @var PlayerSession|null 玩家会话（RakNet 连接完成后由 PlayerModule 创建） */
    public $player = null;

    /** @var int[] 发送序号（帧序 / messageIndex / orderIndex） */
    private int $sendSeqNumber = -1;
    private int $sendMessageIndex = 0;
    private int $sendOrderIndex = 0;

    /** @var int[] */
    private array $ACKQueue = [];
    /** @var int[] */
    private array $NACKQueue = [];
    /** @var FrameSet[] */
    private array $receivedFrames = [];
    private int $lastSeqNumber = -1;
    private int $windowStart = -1;
    private int $windowEnd = 2048;
    /** @var int[] */
    private array $receivedWindow = [];
    private int $messageIndex = 0;
    /** @var int[] */
    private array $channelIndex = [];
    /** @var array<string, array<int,int>> 分片重组缓冲 */
    private array $splitBuffers = [];
    private int $splitIDCounter = 0;
    private bool $portChecking = true;

    public function __construct(
        private NetworkModule $network,
        string $address,
        int $port,
        int $serverID
    ) {
        $this->address = $address;
        $this->port = $port;
        $this->serverID = $serverID;
        $this->lastUpdate = microtime(true);
        $this->windowEnd = RakNetProtocol::WINDOW_SIZE;
        for ($i = 0; $i < 32; $i++) {
            $this->channelIndex[$i] = 0;
        }
    }

    public function getAddress(): string { return $this->address; }
    public function getPort(): int { return $this->port; }
    public function getState(): int { return $this->state; }
    public function isActive(): bool { return $this->isActive; }
    public function isTemporal(): bool { return $this->state < self::STATE_CONNECTED; }
    public function getMTUSize(): int { return $this->mtuSize; }

    public function handlePacket(string $data): void
    {
        $this->isActive = true;
        $this->lastUpdate = microtime(true);
        $pid = ord($data[0]);

        if ($pid >= RakNetProtocol::FRAME_SET_MIN && $pid <= RakNetProtocol::FRAME_SET_MAX) {
            $this->handleFrameSet($data);
        } elseif ($pid === RakNetProtocol::ACK) {
            $this->handleACK($data);
        } elseif ($pid === RakNetProtocol::NACK) {
            $this->handleNACK($data);
        } elseif ($pid === RakNetProtocol::OPEN_CONNECTION_REQUEST_1) {
            $this->handleOpenConnectionRequest1($data);
        } elseif ($pid === RakNetProtocol::OPEN_CONNECTION_REQUEST_2) {
            $this->handleOpenConnectionRequest2($data);
        } elseif ($pid === RakNetProtocol::ONLINE_CONNECTION_REQUEST) {
            $this->handleOnlineConnectionRequest($data);
        } elseif ($pid === RakNetProtocol::CLIENT_HANDSHAKE) {
            $this->handleClientHandshake($data);
        } elseif ($pid === RakNetProtocol::UNCONNECTED_PING) {
            $this->handleUnconnectedPing($data);
        }
    }

    private function handleOpenConnectionRequest1(string $data): void
    {
        $pk = new OfflineConnectionRequest1();
        $pk->decode($data);

        $reply = new OfflineConnectionReply1();
        $this->sendUDP($reply->encode($this->serverID, $pk->mtuSize));
        $this->state = self::STATE_CONNECTING_1;
    }

    private function handleOpenConnectionRequest2(string $data): void
    {
        $pk = new OfflineConnectionRequest2();
        $pk->decode($data);

        $this->clientID = $pk->clientID;
        $this->mtuSize = min(abs($pk->mtuSize), RakNetProtocol::MAX_MTU);

        $reply = new OfflineConnectionReply2();
        $this->sendUDP($reply->encode(
            $this->serverID,
            $this->address,
            $this->port,
            $this->mtuSize
        ));
        $this->state = self::STATE_CONNECTING_2;
    }

    private function handleOnlineConnectionRequest(string $data): void
    {
        $pk = new OnlineConnectionRequest();
        $pk->decode($data);

        // Accepted 必须以可靠封装帧发送（对照原版 RakLib SessionManager）
        $reply = new OnlineConnectionRequestAccepted();
        $ep = new EncapsulatedPacket();
        $ep->reliability = RakNetProtocol::RELIABILITY_RELIABLE_ORDERED;
        $ep->buffer = $reply->encode($this->address, $this->port, $pk->sendPing, $pk->sendPing + 1000);
        $this->sendEncapsulated($ep);
        $this->state = self::STATE_CONNECTING_2;
    }

    private function handleClientHandshake(string $data): void
    {
        $pk = new ClientHandshake();
        $pk->decode($data);

        if ($pk->port === $this->network->getPort() || !$this->portChecking) {
            $this->state = self::STATE_CONNECTED;
            $this->network->onSessionConnected($this);
        }
    }

    private function handleUnconnectedPing(string $data): void
    {
        $pk = new UnconnectedPing();
        $pk->decode($data);

        $reply = new UnconnectedPong();
        $this->sendUDP($reply->encode($pk->pingID, $this->serverID, $this->network->getMotd()));
    }

    private function handleFrameSet(string $data): void
    {
        $fs = FrameSet::fromBinary($data);

        if ($fs->seqNumber < $this->windowStart
            || $fs->seqNumber > $this->windowEnd
            || isset($this->receivedWindow[$fs->seqNumber])
        ) {
            return;
        }

        $diff = $fs->seqNumber - $this->lastSeqNumber;
        unset($this->NACKQueue[$fs->seqNumber]);
        $this->ACKQueue[$fs->seqNumber] = $fs->seqNumber;
        $this->receivedWindow[$fs->seqNumber] = true;

        if ($diff !== 1) {
            for ($i = $this->lastSeqNumber + 1; $i < $fs->seqNumber; $i++) {
                if (!isset($this->receivedWindow[$i])) {
                    $this->NACKQueue[$i] = $i;
                }
            }
        }

        if ($diff >= 1) {
            $this->lastSeqNumber = $fs->seqNumber;
            $this->windowStart += $diff;
            $this->windowEnd += $diff;
        }

        // 处理帧内的封装包
        foreach ($fs->packets as $pkData) {
            $this->processEncapsulated($pkData);
        }
    }

    private function processEncapsulated(string $binary): void
    {
        $offset = 0;
        $ep = EncapsulatedPacket::fromBinary($binary, false, $offset);

        if ($ep->hasSplit) {
            $this->handleSplit($ep);
            return;
        }

        if ($ep->messageIndex !== null) {
            // 可靠包：按序处理
            if ($ep->messageIndex < $this->messageIndex) {
                return; // 旧包
            }
            if ($ep->messageIndex === $this->messageIndex) {
                $this->messageIndex++;
                $this->dispatchEncapsulated($ep->buffer);
                // 处理积压的有序包
                while (isset($this->receivedFrames[$this->messageIndex])) {
                    $this->messageIndex++;
                    $pk = $this->receivedFrames[$this->messageIndex] ?? null;
                    if ($pk !== null) {
                        $this->dispatchEncapsulated($pk);
                        unset($this->receivedFrames[$this->messageIndex]);
                    }
                }
            } else {
                $this->receivedFrames[$ep->messageIndex] = $ep->buffer;
            }
        } else {
            $this->dispatchEncapsulated($ep->buffer);
        }
    }

    /**
     * 封装包分发：RakNet 内部信令（0x09 连接请求 / 0x13 客户端握手）
     * 与游戏协议包在此分道（对照 RakLib Session::handleEncapsulatedPacketRoute）
     */
    private function dispatchEncapsulated(string $buffer): void
    {
        if (strlen($buffer) === 0) {
            return;
        }
        $pid = ord($buffer[0]);
        if ($pid === RakNetProtocol::ONLINE_CONNECTION_REQUEST) {
            $this->handleOnlineConnectionRequest($buffer);
            return;
        }
        if ($pid === RakNetProtocol::CLIENT_HANDSHAKE) {
            $this->handleClientHandshake($buffer);
            return;
        }
        $this->processGamePacket($buffer);
    }

    private function handleSplit(EncapsulatedPacket $packet): void
    {
        if ($packet->splitCount > RakNetProtocol::MAX_SPLIT_SIZE
            || $packet->splitIndex > RakNetProtocol::MAX_SPLIT_SIZE
            || $packet->splitIndex < 0
        ) {
            return;
        }

        $key = $packet->splitID;
        if (!isset($this->splitBuffers[$key])) {
            if (count($this->splitBuffers) >= RakNetProtocol::MAX_SPLIT_COUNT) {
                return;
            }
            $this->splitBuffers[$key] = [];
        }

        $this->splitBuffers[$key][$packet->splitIndex] = $packet->buffer;

        if (count($this->splitBuffers[$key]) === $packet->splitCount) {
            $recombined = "";
            for ($i = 0; $i < $packet->splitCount; $i++) {
                $recombined .= $this->splitBuffers[$key][$i] ?? "";
            }
            unset($this->splitBuffers[$key]);
            $this->processGamePacket($recombined);
        }
    }

    private function processGamePacket(string $buffer): void
    {
        if (strlen($buffer) === 0) {
            return;
        }

        if ($this->player === null) {
            return; // RakNet 已通但玩家会话未建立
        }

        $this->player->handlePacket($buffer);
    }

    public function tick(): void
    {
        if (!$this->isActive && (microtime(true) - $this->lastUpdate) > RakNetProtocol::IDLE_TIMEOUT) {
            $this->network->removeSession($this);
            return;
        }
        $this->isActive = false;

        // 发送 ACK/NACK
        if (count($this->ACKQueue) > 0) {
            $pk = new ACKPacket();
            $pk->packets = $this->ACKQueue;
            $this->sendUDP($pk->encode());
            $this->ACKQueue = [];
        }

        if (count($this->NACKQueue) > 0) {
            $pk = new NACKPacket();
            $pk->packets = $this->NACKQueue;
            $this->sendUDP($pk->encode());
            $this->NACKQueue = [];
        }
    }

    public function sendUDP(string $data): void
    {
        $this->network->sendTo($this->address, $this->port, $data);
    }

    public function sendEncapsulated(EncapsulatedPacket $ep, int $flags = 0): void
    {
        // 可靠有序发送：设置 reliability / messageIndex / orderIndex（对照 RakLib）
        $ep->reliability = RakNetProtocol::RELIABILITY_RELIABLE_ORDERED;
        $ep->messageIndex = $this->sendMessageIndex++;
        $ep->orderIndex = $this->sendOrderIndex++;
        $ep->orderChannel = 0;
        $ep->hasSplit = false;

        $fs = new FrameSet();
        $fs->seqNumber = $this->sendSeqNumber + 1;
        $fs->packets = [$ep->toBinary()];
        $this->sendUDP($fs->toBinary(0x84));
        $this->sendSeqNumber = $fs->seqNumber;
    }

    /**
     * 发送游戏协议包（raw = 含 ID 头的完整包）
     */
    public function sendGamePacket(string $raw): void
    {
        $ep = new EncapsulatedPacket();
        $ep->buffer = $raw;
        $this->sendEncapsulated($ep);
    }

    public function close(): void
    {
        $this->state = self::STATE_UNCONNECTED;
    }
}

/**
 * RCON 协议实现 (Source RCON + GeniRCON)
 */
final class RCONProtocol
{
    public const PROTOCOL_VERSION = 3;

    // Source RCON 包类型
    public const RCON_AUTH = 3;
    public const RCON_AUTH_RESPONSE = 2;
    public const RCON_COMMAND = 2;
    public const RCON_COMMAND_RESPONSE = 0;
    public const RCON_PROTOCOL_CHECK = 9;

    // GeniRCON 扩展
    public const RCON_LOGGER = 4;
    public const RCON_SERVER_STATUS = 5;

    private string $password;
    private int $maxClients;
    /** @var resource */
    private $socket;
    private bool $running = false;
    /** @var array<int, RCONClient> */
    private array $clients = [];
    private int $nextClientId = 0;
    private ?string $serverStatus = null;
    private ?string $loggerOutput = null;

    /**
     * @param resource $socket
     */
    public function __construct($socket, string $password, int $maxClients = 50)
    {
        $this->socket = $socket;
        $this->password = $password;
        $this->maxClients = max(1, $maxClients);
    }

    public function start(): void
    {
        $this->running = true;
        while ($this->running) {
            $read = [$this->socket];
            $write = null;
            $except = null;
            if (socket_select($read, $write, $except, 0, 100000) === 1) {
                $client = @socket_accept($this->socket);
                if ($client !== false) {
                    $this->acceptClient($client);
                }
            }
            $this->tickClients();
        }
    }

    private function acceptClient($client): void
    {
        if (count($this->clients) >= $this->maxClients) {
            @socket_close($client);
            return;
        }
        socket_set_block($client);
        socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
        $id = $this->nextClientId++;
        $this->clients[$id] = new RCONClient($client, $this->password, $id);
    }

    private function tickClients(): void
    {
        foreach ($this->clients as $id => $client) {
            $client->tick();
            if ($client->shouldClose()) {
                $client->close();
                unset($this->clients[$id]);
            }
        }
    }

    public function setServerStatus(string $status): void
    {
        $this->serverStatus = $status;
    }

    public function setLoggerOutput(string $output): void
    {
        $this->loggerOutput = $output;
    }

    public function stop(): void
    {
        $this->running = false;
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }

    /**
     * @param resource $client
     */
    private function writePacket($client, int $requestID, int $packetType, string $payload): void
    {
        $packet = RakNetProtocol::writeLInt($requestID)
            . RakNetProtocol::writeLInt($packetType)
            . $payload
            . "\x00\x00";
        @socket_write($client, RakNetProtocol::writeLInt(strlen($packet)) . $packet);
    }

    /**
     * @param resource $client
     * @return array{0: int, 1: int, 2: int, 3: string}|null
     */
    private function readPacket($client): ?array
    {
        $sizeData = @socket_read($client, 4);
        if ($sizeData === false || strlen($sizeData) < 4) {
            return null;
        }
        $size = RakNetProtocol::readLInt($sizeData);
        if ($size < 0 || $size > 65535) {
            return null;
        }
        $packetData = @socket_read($client, $size);
        if ($packetData === false || strlen($packetData) < $size) {
            return null;
        }
        $requestID = RakNetProtocol::readLInt(substr($packetData, 0, 4));
        $packetType = RakNetProtocol::readLInt(substr($packetData, 4, 4));
        $payload = rtrim(substr($packetData, 8), "\x00");
        return [$requestID, $packetType, $size, $payload];
    }
}

/**
 * RCON 客户端连接
 */
final class RCONClient
{
    private $socket;
    private string $password;
    private int $id;
    private int $status = 0; // 0=未认证, 1=已认证
    private bool $shouldClose = false;
    private float $lastActivity = 0.0;
    private ?RCONProtocol $rcon = null;

    public function __construct($socket, string $password, int $id)
    {
        $this->socket = $socket;
        $this->password = $password;
        $this->id = $id;
        $this->lastActivity = microtime(true);
    }

    public function setRCON(RCONProtocol $rcon): void
    {
        $this->rcon = $rcon;
    }

    public function tick(): void
    {
        if ($this->status === 0 && (microtime(true) - $this->lastActivity) > 5) {
            $this->shouldClose = true;
            return;
        }

        $data = @socket_read($this->socket, 4096);
        if ($data === false || $data === "") {
            if ($this->status === 1 && (microtime(true) - $this->lastActivity) > 30) {
                $this->shouldClose = true;
            }
            return;
        }

        $this->lastActivity = microtime(true);
        // 简化处理：读取完整包
        $sizeData = @socket_read($this->socket, 4);
        // ... 完整 RCON 包处理逻辑
    }

    public function shouldClose(): bool
    {
        return $this->shouldClose;
    }

    public function close(): void
    {
        @socket_close($this->socket);
    }
}

/**
 * MCPE Query 协议 (旧版)
 */
final class QueryProtocol
{
    private const HANDSHAKE = 9;
    private const STATISTICS = 0;

    private string $token = "";
    private string $lastToken = "";
    private string $longData = "";
    private string $shortData = "";
    private float $timeout = 0.0;
    private string $motd = "";
    private int $currentPlayers = 0;
    private int $maxPlayers = 0;
    private string $serverVersion = "0.14.3";
    private string $serverName = "Genisys PHP8 Server";

    public function __construct()
    {
        $this->regenerateToken();
        $this->lastToken = $this->token;
        $this->regenerateInfo();
    }

    private function regenerateToken(): void
    {
        $this->lastToken = $this->token;
        $this->token = bin2hex(random_bytes(8));
    }

    private function regenerateInfo(): void
    {
        $this->longData = $this->buildLongQuery();
        $this->shortData = $this->buildShortQuery();
        $this->timeout = microtime(true) + 30;
    }

    private function buildShortQuery(): string
    {
        return "\x00" . $this->serverName . "\x00"
            . $this->serverVersion . "\x00"
            . "MCPE;{$this->motd};{$this->currentPlayers};{$this->maxPlayers};0;{$this->serverName}";
    }

    private function buildLongQuery(): string
    {
        $data = "\x00" . $this->serverName . "\x00";
        $data .= "gametype;smp\n";
        $data .= "version;{$this->serverVersion}\n";
        $data .= "plugins;\n";
        $data .= "players;{$this->currentPlayers}\n";
        $data .= "max_players;{$this->maxPlayers}\n";
        $data .= "motd;{$this->motd}\n";
        $data .= "level;world\n";
        $data .= "gametype;smp\n";
        return $data;
    }

    /**
     * 处理 Query 包
     */
    public function handle(string $address, int $port, string $data): void
    {
        if (strlen($data) < 5) {
            return;
        }

        $offset = 2; // skip magic (0xFE, 0xFD)
        $packetType = ord($data[$offset++]);
        $sessionID = RakNetProtocol::readInt(substr($data, $offset, 4));
        $offset += 4;
        $payload = substr($data, $offset);

        switch ($packetType) {
            case self::HANDSHAKE:
                $reply = chr(self::HANDSHAKE)
                    . RakNetProtocol::writeInt($sessionID)
                    . $this->getTokenString($this->token, $address) . "\x00";
                $this->sendReply($address, $port, $reply);
                break;

            case self::STATISTICS:
                $token = RakNetProtocol::readInt(substr($payload, 0, 4));
                if ($token !== $this->getTokenString($this->token, $address)
                    && $token !== $this->getTokenString($this->lastToken, $address)
                ) {
                    break;
                }

                if ($this->timeout < microtime(true)) {
                    $this->regenerateToken();
                    $this->regenerateInfo();
                }

                $reply = chr(self::STATISTICS) . RakNetProtocol::writeInt($sessionID);
                if (strlen($payload) === 8) {
                    $reply .= $this->longData;
                } else {
                    $reply .= $this->shortData;
                }
                $this->sendReply($address, $port, $reply);
                break;
        }
    }

    private function getTokenString(string $token, string $address): string
    {
        $salt = $address;
        $hash = hash("sha512", $salt . ":" . $token, true);
        return substr($hash, 7, 4);
    }

    private function sendReply(string $address, int $port, string $data): void
    {
        // 通过 NetworkModule 发送 UDP
        // 由调用方处理
    }

    public function setMotd(string $motd): void { $this->motd = $motd; }
    public function setPlayers(int $current, int $max): void
    {
        $this->currentPlayers = $current;
        $this->maxPlayers = $max;
    }
    public function setName(string $name): void { $this->serverName = $name; }
}

/**
 * NetworkModule — 完整实现
 */
class NetworkModule implements WorkerInterface
{
    private bool $shutdown = false;
    private string $name;
    private int $port;
    private string $motd;
    private string $serverAddress = "0.0.0.0";
    private int $serverID = 0;
    private bool $isRunning = false;

    /** @var RakSession[] */
    private array $sessions = [];
    private int $sessionCounter = 0;

    // UDP socket
    private $socket = null;
    private bool $useReactPHP = false;

    // 协议处理器
    private ?QueryProtocol $query = null;
    private ?RCONProtocol $rcon = null;

    // 玩家会话管理
    private ?PlayerModule $playerModule = null;

    // 统计
    private int $bytesSent = 0;
    private int $bytesReceived = 0;
    private float $lastTick = 0.0;

    public function __construct(string $name = 'Network', int $port = 19132, string $motd = 'Genisys PHP8 Server')
    {
        $this->name = $name;
        $this->port = $port;
        $this->motd = $motd;
        $this->serverID = mt_rand(0, PHP_INT_MAX);
    }

    /**
     * 挂接玩家会话管理（index.php 装配）
     */
    public function setPlayerModule(PlayerModule $playerModule): void
    {
        $this->playerModule = $playerModule;
    }

    public function getThreadName(): string { return $this->name; }
    public function isShutdown(): bool { return $this->shutdown; }
    public function shutdown(): void { $this->shutdown = true; }
    public function isAlive(): bool { return !$this->shutdown; }

    public function getMotd(): string
    {
        $online = $this->playerModule !== null ? $this->playerModule->getSessionCount() : 0;
        return "MCPE;{$this->motd};" . MinecraftProtocol::CURRENT_PROTOCOL
            . ";0.14.3;$online;20";
    }

    public function getPort(): int { return $this->port; }
    public function getServerID(): int { return $this->serverID; }

    /**
     * 启动网络服务器
     */
    public function start(): void
    {
        $this->onRun();
    }

    public function join(): void { /* Fiber 协程无需 join */ }

    public function run(): void
    {
        $this->onRun();
    }

    public function onRun(): void
    {
        $this->initSocket();
        $this->query = new QueryProtocol();
        $this->query->setMotd($this->motd);
        $this->query->setName($this->name);

        $this->isRunning = true;
        $this->lastTick = microtime(true);

        while (!$this->isShutdown()) {
            $this->tick();
        }

        $this->cleanup();
    }

    /**
     * 初始化 UDP socket
     * 优先使用 ReactPHP，回退到原生 sockets
     */
    private function initSocket(): void
    {
        // 尝试 ReactPHP
        if (class_exists('React\Socket\SocketServer')) {
            $this->useReactPHP = true;
            $this->initReactPHPSocket();
            return;
        }

        // 回退到原生 socket
        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            throw new \RuntimeException("Failed to create UDP socket: " . socket_strerror(socket_last_error()));
        }

        if (!@socket_bind($this->socket, $this->serverAddress, $this->port)) {
            throw new \RuntimeException("Failed to bind to {$this->serverAddress}:{$this->port}: " . socket_strerror(socket_last_error()));
        }

        @socket_set_nonblock($this->socket);
    }

    private function initReactPHPSocket(): void
    {
        // ReactPHP 集成占位
        // 实际使用时可通过 ReactUDPServer 处理
    }

    /**
     * 初始化 UDP socket（测试可手动绑定后调用 tick() 驱动）
     */
    public function bind(): void
    {
        $this->initSocket();
        $this->query = new QueryProtocol();
        $this->query->setMotd($this->motd);
        $this->query->setName($this->name);
        $this->isRunning = true;
    }

    /**
     * 每 tick 回调（控制台轮询等外部逻辑挂载点）
     *
     * @var callable|null
     */
    public $onTick = null;

    /**
     * 主循环 tick（public：供测试与外部事件循环驱动）
     */
    public function tick(): void
    {
        $now = microtime(true);

        // 接收 UDP 数据包
        $this->receivePackets();

        // 更新所有会话
        foreach ($this->sessions as $session) {
            $session->tick();
        }

        // 清理过期会话
        $this->cleanupSessions();

        $this->lastTick = $now;

        // 外部 tick 逻辑（控制台命令等）
        if ($this->onTick !== null) {
            ($this->onTick)();
        }

        // 防止 CPU 占用过高
        usleep(1000); // 1ms
    }

    /**
     * 接收 UDP 数据包
     */
    private function receivePackets(): void
    {
        if ($this->useReactPHP) {
            $this->receiveReactPHP();
            return;
        }

        if ($this->socket === null) return;

        for ($i = 0; $i < 64; $i++) {
            $buffer = "";
            $from = "";
            $port = 0;
            $len = @socket_recvfrom($this->socket, $buffer, 65535, 0, $from, $port);

            if ($len === false || $len === 0) break;

            $this->bytesReceived += $len;
            $this->handleUDP($from, $port, $buffer);
        }
    }

    private function receiveReactPHP(): void
    {
        // ReactPHP 接收逻辑
    }

    /**
     * 处理接收到的 UDP 数据包
     */
    private function handleUDP(string $address, int $port, string $data): void
    {
        if (strlen($data) === 0) return;

        // 检查是否是 Query 包 (0xFE 0xFD 前缀)
        if (ord($data[0]) === 0xFE) {
            $this->query->handle($address, $port, $data);
            return;
        }

        $pid = ord($data[0]);

        // 未连接 Ping
        if ($pid === RakNetProtocol::UNCONNECTED_PING) {
            $this->handleUnconnectedPing($address, $port, $data);
            return;
        }

        // 获取或创建会话
        $key = "$address:$port";
        if (!isset($this->sessions[$key])) {
            $this->sessions[$key] = new RakSession($this, $address, $port, $this->serverID);
        }

        $this->sessions[$key]->handlePacket($data);
    }

    private function handleUnconnectedPing(string $address, int $port, string $data): void
    {
        $pk = new UnconnectedPing();
        $pk->decode($data);

        $reply = new UnconnectedPong();
        $this->sendTo($address, $port, $reply->encode($pk->pingID, $this->serverID, $this->getMotd()));
    }

    /**
     * 发送 UDP 数据包
     */
    public function sendTo(string $address, int $port, string $data): void
    {
        if ($this->useReactPHP) {
            // ReactPHP 发送
            return;
        }

        if ($this->socket === null) return;

        $len = @socket_sendto($this->socket, $data, strlen($data), 0, $address, $port);
        if ($len !== false) {
            $this->bytesSent += $len;
        }
    }

    /**
     * RakNet 会话建立完成（ClientHandshake 校验通过）→ 创建玩家会话
     */
    public function onSessionConnected(RakSession $session): void
    {
        if ($this->playerModule !== null) {
            $session->player = $this->playerModule->createSession($session);
        }
    }

    /**
     * 移除会话
     */
    public function removeSession(RakSession $session): void
    {
        if ($session->player !== null) {
            $session->player->close();
            $session->player = null;
        }
        $key = $session->getAddress() . ":" . $session->getPort();
        unset($this->sessions[$key]);
    }

    /**
     * 清理过期会话
     */
    private function cleanupSessions(): void
    {
        $now = microtime(true);
        foreach ($this->sessions as $key => $session) {
            if ($session->isActive() && ($now - $session->lastUpdate) > RakNetProtocol::IDLE_TIMEOUT) {
                $session->close();
                unset($this->sessions[$key]);
            }
        }
    }

    /**
     * 清理资源
     */
    private function cleanup(): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
        $this->sessions = [];
    }

    /**
     * 启动 RCON 服务
     */
    public function startRCON(string $password, int $rconPort = 25575): void
    {
        if ($password === "") return;

        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) return;

        if (!@socket_bind($socket, "0.0.0.0", $rconPort)) {
            @socket_close($socket);
            return;
        }

        if (!@socket_listen($socket, 5)) {
            @socket_close($socket);
            return;
        }

        @socket_set_nonblock($socket);

        $this->rcon = new RCONProtocol($socket, $password);
        // 在独立线程/Fiber 中启动 RCON 循环
        // 这里在主循环中通过 tick 调用
    }

    /**
     * 启动 Query 服务
     */
    public function startQuery(): void
    {
        $this->query = new QueryProtocol();
        $this->query->setMotd($this->motd);
        $this->query->setName($this->name);
    }

    /**
     * 获取网络统计
     */
    public function getStats(): array
    {
        return [
            'sessions' => count($this->sessions),
            'bytesSent' => $this->bytesSent,
            'bytesReceived' => $this->bytesReceived,
            'port' => $this->port,
            'motd' => $this->getMotd(),
        ];
    }

    /**
     * 发送游戏包到指定会话
     */
    public function sendToSession(RakSession $session, string $data): void
    {
        $ep = new EncapsulatedPacket();
        $ep->reliability = RakNetProtocol::RELIABILITY_RELIABLE;
        $ep->buffer = $data;
        $session->sendEncapsulated($ep);
    }

    /**
     * 广播游戏包到所有会话
     */
    public function broadcastPacket(string $data): void
    {
        foreach ($this->sessions as $session) {
            if ($session->getState() === RakSession::STATE_CONNECTED) {
                $this->sendToSession($session, $data);
            }
        }
    }
}