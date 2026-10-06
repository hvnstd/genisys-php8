<?php

declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\BinaryStream;

/**
 * 元数据值类型常量
 */
final class Metadata {
    public const TYPE_BYTE = 0;
    public const TYPE_SHORT = 1;
    public const TYPE_INT = 2;
    public const TYPE_FLOAT = 3;
    public const TYPE_STRING = 4;
    public const TYPE_SLOT = 5;
    public const TYPE_POSITION = 6;
    public const TYPE_VECTOR3 = 7;
    public const TYPE_ROTATION = 8;
    public const TYPE_BLOCK_ID = 9;
    public const TYPE_LONG = 10;
}

/**
 * 位置类
 */
readonly class Position {
    public function __construct(
        public int $x = 0,
        public int $y = 0,
        public int $z = 0
    ) {}

    public static function fromLong(int $value): Position {
        return new Position(
            $value >> 38,
            ($value >> 26) & 0xFFF,
            $value & 0x3FFFFFF
        );
    }

    public function toLong(): int {
        return (($this->x & 0x3FFFFFF) << 38) | (($this->y & 0xFFF) << 26) | ($this->z & 0x3FFFFFF);
    }

    public function add(Position $other): Position {
        return new Position($this->x + $other->x, $this->y + $other->y, $this->z + $other->z);
    }

    public function subtract(Position $other): Position {
        return new Position($this->x - $other->x, $this->y - $other->y, $this->z - $other->z);
    }

    public function distance(Position $other): float {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        $dz = $this->z - $other->z;
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    public function equals(mixed $other): bool {
        return $other instanceof Position && $this->x === $other->x && $this->y === $other->y && $this->z === $other->z;
    }

    public function __toString(): string {
        return "Position(x={$this->x}, y={$this->y}, z={$this->z})";
    }
}

/**
 * 物品槽位
 */
readonly class Slot {
    public function __construct(
        public int $id = 0,
        public int $count = 0,
        public int $damage = 0,
        public ?TagCompound $nbt = null
    ) {}

    public static function empty(): Slot {
        return new Slot();
    }

    public function isEmpty(): bool {
        return $this->id === 0 || $this->count <= 0;
    }

    public function equals(mixed $other): bool {
        if (!$other instanceof Slot) return false;
        return $this->id === $other->id &&
               $this->count === $other->count &&
               $this->damage === $other->damage &&
               (($this->nbt === null && $other->nbt === null) ||
                ($this->nbt !== null && $other->nbt !== null && $this->nbt->equals($other->nbt)));
    }
}

/**
 * 元数据值
 */
readonly class MetadataValue {
    public function __construct(
        public int $type,
        public mixed $value
    ) {}

    public static function createByte(string $name, int $value): self {
        return new self(Metadata::TYPE_BYTE, $value & 0xFF);
    }

    public static function createShort(string $name, int $value): self {
        return new self(Metadata::TYPE_SHORT, $value & 0xFFFF);
    }

    public static function createInt(string $name, int $value): self {
        return new self(Metadata::TYPE_INT, $value);
    }

    public static function createFloat(string $name, float $value): self {
        return new self(Metadata::TYPE_FLOAT, $value);
    }

    public static function createString(string $name, string $value): self {
        return new self(Metadata::TYPE_STRING, $value);
    }

    public static function createSlot(string $name, Slot $value): self {
        return new self(Metadata::TYPE_SLOT, $value);
    }

    public static function createPosition(string $name, Position $value): self {
        return new self(Metadata::TYPE_POSITION, $value);
    }

    public static function createVector3(string $name, Vector3 $value): self {
        return new self(Metadata::TYPE_VECTOR3, $value);
    }

    public static function createRotation(string $name, float $yaw, float $pitch, float $roll): self {
        return new self(Metadata::TYPE_ROTATION, [$yaw, $pitch, $roll]);
    }

    public static function createBlockId(string $name, int $value): self {
        return new self(Metadata::TYPE_BLOCK_ID, $value);
    }

    public static function createLong(string $name, int $value): self {
        return new self(Metadata::TYPE_LONG, $value);
    }

    public function write(BinaryStream $stream): void {
        $stream->putByte($this->type);
        match($this->type) {
            Metadata::TYPE_BYTE => $stream->putByte($this->value),
            Metadata::TYPE_SHORT => $stream->putShort($this->value),
            Metadata::TYPE_INT => $stream->putInt($this->value),
            Metadata::TYPE_FLOAT => $stream->putFloat($this->value),
            Metadata::TYPE_STRING => $stream->putString($this->value),
            Metadata::TYPE_SLOT => $this->writeSlot($stream, $this->value),
            Metadata::TYPE_POSITION => $stream->putLong($this->value->toLong()),
            Metadata::TYPE_VECTOR3 => $this->writeVector3($stream, $this->value),
            Metadata::TYPE_ROTATION => $this->writeRotation($stream, $this->value),
            Metadata::TYPE_BLOCK_ID => $stream->putInt($this->value),
            Metadata::TYPE_LONG => $stream->putLong($this->value),
        };
    }

    private function writeSlot(BinaryStream $stream, Slot $slot): void {
        $stream->putShort($slot->id);
        $stream->putByte($slot->count);
        $stream->putShort($slot->damage);
        if ($slot->nbt !== null) {
            $stream->putByte(1); // has NBT
            NBT::writeTag($slot->nbt, $stream);
        } else {
            $stream->putByte(0); // no NBT
        }
    }

    private function writeVector3(BinaryStream $stream, Vector3 $vec): void {
        $stream->putFloat($vec->x);
        $stream->putFloat($vec->y);
        $stream->putFloat($vec->z);
    }

    private function writeRotation(BinaryStream $stream, array $rot): void {
        $stream->putFloat($rot[0]); // yaw
        $stream->putFloat($rot[1]); // pitch
        $stream->putFloat($rot[2]); // roll
    }

    public static function read(BinaryStream $stream): ?self {
        $type = $stream->getByte();
        if ($type === 0xFF) return null; // terminator

        $value = match($type) {
            Metadata::TYPE_BYTE => $stream->getByte(),
            Metadata::TYPE_SHORT => $stream->getShort(),
            Metadata::TYPE_INT => $stream->getInt(),
            Metadata::TYPE_FLOAT => $stream->getFloat(),
            Metadata::TYPE_STRING => $stream->getString(),
            Metadata::TYPE_SLOT => self::readSlot($stream),
            Metadata::TYPE_POSITION => Position::fromLong($stream->getLong()),
            Metadata::TYPE_VECTOR3 => self::readVector3($stream),
            Metadata::TYPE_ROTATION => self::readRotation($stream),
            Metadata::TYPE_BLOCK_ID => $stream->getInt(),
            Metadata::TYPE_LONG => $stream->getLong(),
            default => null,
        };
        return $value !== null ? new self($type, $value) : null;
    }

    private static function readSlot(BinaryStream $stream): ?Slot {
        $id = $stream->getShort();
        if ($id === 0) return new Slot();
        $count = $stream->getByte();
        $damage = $stream->getShort();
        $hasNbt = $stream->getByte();
        $nbt = $hasNbt ? NBT::readTag($stream) : null;
        return new Slot($id, $count, $damage, $nbt instanceof TagCompound ? $nbt : null);
    }

    private static function readVector3(BinaryStream $stream): Vector3 {
        return new Vector3(
            $stream->getFloat(),
            $stream->getFloat(),
            $stream->getFloat()
        );
    }

    private static function readRotation(BinaryStream $stream): array {
        return [
            $stream->getFloat(), // yaw
            $stream->getFloat(), // pitch
            $stream->getFloat(), // roll
        ];
    }
}

/**
 * 元数据集合
 */
class MetadataCollection {
    /** @var array<int, MetadataValue> */
    private array $data = [];

    public function __construct(array $data = []) {
        foreach ($data as $index => $value) {
            $this->set($index, $value);
        }
    }

    public function set(int $index, MetadataValue $value): void {
        if ($index < 0 || $index > 255) {
            throw new \InvalidArgumentException("Metadata index must be 0-255");
        }
        $this->data[$index] = $value;
    }

    public function get(int $index): ?MetadataValue {
        return $this->data[$index] ?? null;
    }

    public function has(int $index): bool {
        return isset($this->data[$index]);
    }

    public function remove(int $index): void {
        unset($this->data[$index]);
    }

    public function getAll(): array {
        return $this->data;
    }

    public function write(BinaryStream $stream): void {
        foreach ($this->data as $index => $value) {
            $stream->putByte($index);
            $value->write($stream);
        }
        $stream->putByte(0xFF); // terminator
    }

    public static function read(BinaryStream $stream): self {
        $collection = new self();
        while (true) {
            $index = $stream->getByte();
            if ($index === 0xFF) break; // terminator
            $value = MetadataValue::read($stream);
            if ($value !== null) {
                $collection->set($index, $value);
            }
        }
        return $collection;
    }

    public function count(): int {
        return count($this->data);
    }
}

/**
 * 元数据模块入口
 */
final class MetadataModule {
    public const VERSION = "1.0.0";
    public function getName(): string { return "MetadataModule"; }
    public function getVersion(): string { return self::VERSION; }
}