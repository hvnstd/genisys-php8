<?php
/**
 * Genisys PHP 8.x 兼容层 — 二进制读写流
 *
 * 等价于原版 pocketmine/utils/BinaryStream，端序沿用原版
 * NBT 网络格式：小端（LE）。供 NBT / Metadata / Item 模块使用。
 */
declare(strict_types=1);

namespace Genisys\Compat;

class BinaryStream
{
    protected string $buffer;
    protected int $offset;

    public function __construct(string $buffer = "", int $offset = 0)
    {
        $this->buffer = $buffer;
        $this->offset = $offset;
    }

    public function reset(): void
    {
        $this->buffer = "";
        $this->offset = 0;
    }

    public function setBuffer(string $buffer = "", int $offset = 0): void
    {
        $this->buffer = $buffer;
        $this->offset = $offset;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function get(int $len): string
    {
        if ($len < 0) {
            throw new \InvalidArgumentException("Length must be positive, got $len");
        }
        if ($this->offset + $len > strlen($this->buffer)) {
            throw new \UnderflowException("Not enough bytes to read $len at offset {$this->offset}");
        }
        $result = substr($this->buffer, $this->offset, $len);
        $this->offset += $len;
        return $result;
    }

    public function put(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    // ---- 无符号基础类型 ----

    public function getByte(): int
    {
        return ord($this->get(1));
    }

    public function putByte(int $v): void
    {
        $this->buffer .= chr($v & 0xFF);
    }

    public function getShort(): int
    {
        $result = unpack("v", $this->get(2));
        return $result[1];
    }

    public function putShort(int $v): void
    {
        $this->buffer .= pack("v", $v & 0xFFFF);
    }

    public function getInt(): int
    {
        $result = unpack("V", $this->get(4));
        return $result[1];
    }

    public function putInt(int $v): void
    {
        $this->buffer .= pack("V", $v & 0xFFFFFFFF);
    }

    public function getLong(): int
    {
        $result = unpack("P", $this->get(8));
        return $result[1];
    }

    public function putLong(int $v): void
    {
        $this->buffer .= pack("P", $v);
    }

    public function getFloat(): float
    {
        $result = unpack("g", $this->get(4));
        return $result[1];
    }

    public function putFloat(float $v): void
    {
        $this->buffer .= pack("g", $v);
    }

    public function getDouble(): float
    {
        $result = unpack("e", $this->get(8));
        return $result[1];
    }

    public function putDouble(float $v): void
    {
        $this->buffer .= pack("e", $v);
    }

    // ---- 大端序原语（Anvil / McRegion / level.dat 磁盘格式用）----

    public function getShortBE(): int
    {
        return unpack("n", $this->get(2))[1];
    }

    public function putShortBE(int $v): void
    {
        $this->buffer .= pack("n", $v & 0xFFFF);
    }

    public function getIntBE(): int
    {
        $result = unpack("N", $this->get(4));
        return $result[1] >= 0x80000000 ? $result[1] - 0x100000000 : $result[1];
    }

    public function putIntBE(int $v): void
    {
        $this->buffer .= pack("N", $v & 0xFFFFFFFF);
    }

    public function getLongBE(): int
    {
        // unpack J 是无符号 64 位大端，转有符号
        $v = unpack("J", $this->get(8))[1];
        return $v >= (1 << 63) ? $v - (1 << 64) : $v;
    }

    public function putLongBE(int $v): void
    {
        $this->buffer .= pack("J", $v);
    }

    public function getFloatBE(): float
    {
        return unpack("G", $this->get(4))[1];
    }

    public function putFloatBE(float $v): void
    {
        $this->buffer .= pack("G", $v);
    }

    public function getDoubleBE(): float
    {
        return unpack("E", $this->get(8))[1];
    }

    public function putDoubleBE(float $v): void
    {
        $this->buffer .= pack("E", $v);
    }

    // ---- 复合类型 ----

    public function getSignedShort(): int
    {
        $result = unpack("s", $this->get(2));
        return $result[1];
    }

    public function getSignedInt(): int
    {
        $result = unpack("l", $this->get(4));
        return $result[1];
    }

    public function getSignedLong(): int
    {
        $result = unpack("q", $this->get(8));
        return $result[1];
    }

    public function getString(): string
    {
        $len = $this->getShort();
        return $this->get($len);
    }

    public function putString(string $v): void
    {
        $this->putShort(strlen($v));
        $this->put($v);
    }

    public function getByteArray(): string
    {
        $len = $this->getInt();
        return $this->get($len);
    }

    /** @return int[] */
    public function getIntArray(): array
    {
        $len = $this->getInt();
        $result = [];
        for ($i = 0; $i < $len; ++$i) {
            $result[] = $this->getSignedInt();
        }
        return $result;
    }

    /** @return int[] */
    public function getLongArray(): array
    {
        $len = $this->getInt();
        $result = [];
        for ($i = 0; $i < $len; ++$i) {
            $result[] = $this->getSignedLong();
        }
        return $result;
    }
}
