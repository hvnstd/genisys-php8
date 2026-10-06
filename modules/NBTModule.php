<?php

declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\BinaryStream;

/**
 * NBT 标签类型常量
 *
 * 端序约定（对照原版 pocketmine/nbt/NBT）：
 * - 小端（LITTLE_ENDIAN）：网络/NBT 交换格式（Metadata、Item NBT）
 * - 大端（BIG_ENDIAN）：磁盘格式（Anvil/McRegion 区块、level.dat）
 */
final class NBT {
    public const LITTLE_ENDIAN = 0;
    public const BIG_ENDIAN = 1;

    public const TAG_END = 0;
    public const TAG_BYTE = 1;
    public const TAG_SHORT = 2;
    public const TAG_INT = 3;
    public const TAG_LONG = 4;
    public const TAG_FLOAT = 5;
    public const TAG_DOUBLE = 6;
    public const TAG_BYTE_ARRAY = 7;
    public const TAG_STRING = 8;
    public const TAG_LIST = 9;
    public const TAG_COMPOUND = 10;
    public const TAG_INT_ARRAY = 11;
    public const TAG_LONG_ARRAY = 12;

    /**
     * 将标签写入二进制流（默认小端；Anvil/level.dat 用大端）
     */
    public static function writeTag(Tag $tag, BinaryStream $stream, int $endianness = self::LITTLE_ENDIAN): void {
        $stream->putByte($tag->getType());
        if ($tag->getType() !== self::TAG_END) {
            if ($endianness === self::BIG_ENDIAN) {
                $stream->putShortBE(strlen($tag->getName()));
                $stream->put($tag->getName());
            } else {
                $stream->putString($tag->getName());
            }
            $tag->write($stream, $endianness);
        }
    }

    /**
     * 从二进制流读取标签
     */
    public static function readTag(BinaryStream $stream, int $endianness = self::LITTLE_ENDIAN): ?Tag {
        $type = $stream->getByte();
        if ($type === self::TAG_END) {
            return new TagEnd("");
        }
        $name = $endianness === self::BIG_ENDIAN
            ? $stream->get($stream->getShortBE())
            : $stream->getString();
        return self::readTagPayload($type, $name, $stream, $endianness);
    }

    private static function readTagPayload(int $type, string $name, BinaryStream $stream, int $endianness): ?Tag {
        $be = $endianness === self::BIG_ENDIAN;
        return match($type) {
            self::TAG_BYTE => new TagByte($name, $stream->getByte()),
            self::TAG_SHORT => new TagShort($name, $be ? $stream->getShortBE() : $stream->getShort()),
            self::TAG_INT => new TagInt($name, $be ? $stream->getIntBE() : $stream->getSignedInt()),
            self::TAG_LONG => new TagLong($name, $be ? $stream->getLongBE() : $stream->getLong()),
            self::TAG_FLOAT => new TagFloat($name, $be ? $stream->getFloatBE() : $stream->getFloat()),
            self::TAG_DOUBLE => new TagDouble($name, $be ? $stream->getDoubleBE() : $stream->getDouble()),
            self::TAG_BYTE_ARRAY => $be
                ? new TagByteArray($name, $stream->get($stream->getIntBE()))
                : new TagByteArray($name, $stream->getByteArray()),
            self::TAG_STRING => new TagString($name, $be ? $stream->get($stream->getShortBE()) : $stream->getString()),
            self::TAG_LIST => self::readList($name, $stream, $endianness),
            self::TAG_COMPOUND => self::readCompound($name, $stream, $endianness),
            self::TAG_INT_ARRAY => $be
                ? new TagIntArray($name, self::readIntsBE($stream))
                : new TagIntArray($name, $stream->getIntArray()),
            self::TAG_LONG_ARRAY => $be
                ? new TagLongArray($name, self::readLongsBE($stream))
                : new TagLongArray($name, $stream->getLongArray()),
            default => null,
        };
    }

    private static function readIntsBE(BinaryStream $stream): array {
        $len = $stream->getIntBE();
        $result = [];
        for ($i = 0; $i < $len; ++$i) {
            $result[] = $stream->getIntBE();
        }
        return $result;
    }

    private static function readLongsBE(BinaryStream $stream): array {
        $len = $stream->getIntBE();
        $result = [];
        for ($i = 0; $i < $len; ++$i) {
            $result[] = $stream->getLongBE();
        }
        return $result;
    }

    private static function readList(string $name, BinaryStream $stream, int $endianness): TagList {
        $listType = $stream->getByte();
        $length = $endianness === self::BIG_ENDIAN ? $stream->getIntBE() : $stream->getInt();
        $values = [];
        for ($i = 0; $i < $length; $i++) {
            $tag = self::readTagPayload($listType, "", $stream, $endianness);
            if ($tag !== null) {
                $values[] = $tag;
            }
        }
        return new TagList($name, $listType, $values);
    }

    private static function readCompound(string $name, BinaryStream $stream, int $endianness): TagCompound {
        $tags = [];
        while (true) {
            $tag = self::readTag($stream, $endianness);
            if ($tag === null || $tag->getType() === self::TAG_END) {
                break;
            }
            $tags[$tag->getName()] = $tag;
        }
        return new TagCompound($name, $tags);
    }

    /**
     * GZIP 压缩写入（网络小端格式，长度前缀）
     */
    public static function writeCompressed(TagCompound $compound, BinaryStream $stream): void {
        $temp = new BinaryStream();
        self::writeTag($compound, $temp);
        $compressed = gzcompress($temp->getBuffer());
        $stream->putInt(strlen($compressed));
        $stream->put($compressed);
    }

    /**
     * GZIP 解压读取（网络小端格式，长度前缀）
     */
    public static function readCompressed(BinaryStream $stream): ?TagCompound {
        $length = $stream->getInt();
        $compressed = $stream->get($length);
        $decompressed = gzuncompress($compressed);
        if ($decompressed === false) {
            return null;
        }
        $temp = new BinaryStream($decompressed);
        $tag = self::readTag($temp);
        return $tag instanceof TagCompound ? $tag : null;
    }

    /**
     * 工厂方法：根据值自动推断类型创建标签
     */
    public static function create(string $name, mixed $value): Tag {
        return match(true) {
            is_null($value) => new TagEnd($name),
            is_bool($value) => new TagByte($name, $value ? 1 : 0),
            is_int($value) => $value > 2147483647 || $value < -2147483648
                ? new TagLong($name, $value)
                : new TagInt($name, $value),
            is_float($value) => new TagDouble($name, $value),
            is_string($value) => new TagString($name, $value),
            is_array($value) => self::createArray($name, $value),
            $value instanceof Tag => $value,
            default => new TagString($name, (string)$value),
        };
    }

    private static function createArray(string $name, array $value): Tag {
        if (empty($value)) {
            return new TagList($name, self::TAG_END, []);
        }
        $first = reset($value);
        if (is_int($first)) {
            $allInt = true;
            $allLong = true;
            foreach ($value as $v) {
                if (!is_int($v)) { $allInt = false; $allLong = false; break; }
                if ($v > 2147483647 || $v < -2147483648) { $allInt = false; }
            }
            if ($allInt) return new TagIntArray($name, $value);
            if ($allLong) return new TagLongArray($name, $value);
        }
        if (is_string($first)) {
            return new TagByteArray($name, array_map('ord', $value));
        }
        // 复杂数组作为 List
        $tags = array_map(fn($v) => self::create("", $v), $value);
        $type = $tags[0]->getType() ?? self::TAG_END;
        return new TagList($name, $type, $tags);
    }
}

/**
 * 抽象标签基类
 */
abstract class Tag {
    protected string $name;
    public function __construct(string $name = "") { $this->name = $name; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    abstract public function getType(): int;
    abstract public function getValue(): mixed;
    abstract public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void;
    public function __toString(): string { return $this->getName() . ": " . $this->getValue(); }
}

/** TAG_END */
final class TagEnd extends Tag {
    public function getType(): int { return NBT::TAG_END; }
    public function getValue(): mixed { return null; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {}
}

/** TAG_BYTE */
final class TagByte extends Tag {
    private int $value;
    public function __construct(string $name, int $value = 0) { parent::__construct($name); $this->value = $value & 0xFF; }
    public function getType(): int { return NBT::TAG_BYTE; }
    public function getValue(): int { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void { $stream->putByte($this->value); }
}

/** TAG_SHORT */
final class TagShort extends Tag {
    private int $value;
    public function __construct(string $name, int $value = 0) { parent::__construct($name); $this->value = $value & 0xFFFF; }
    public function getType(): int { return NBT::TAG_SHORT; }
    public function getValue(): int { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) { $stream->putShortBE($this->value); }
        else { $stream->putShort($this->value); }
    }
}

/** TAG_INT */
final class TagInt extends Tag {
    private int $value;
    public function __construct(string $name, int $value = 0) { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_INT; }
    public function getValue(): int { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) { $stream->putIntBE($this->value); }
        else { $stream->putInt($this->value); }
    }
}

/** TAG_LONG */
final class TagLong extends Tag {
    private int $value;
    public function __construct(string $name, int $value = 0) { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_LONG; }
    public function getValue(): int { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) { $stream->putLongBE($this->value); }
        else { $stream->putLong($this->value); }
    }
}

/** TAG_FLOAT */
final class TagFloat extends Tag {
    private float $value;
    public function __construct(string $name, float $value = 0.0) { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_FLOAT; }
    public function getValue(): float { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) { $stream->putFloatBE($this->value); }
        else { $stream->putFloat($this->value); }
    }
}

/** TAG_DOUBLE */
final class TagDouble extends Tag {
    private float $value;
    public function __construct(string $name, float $value = 0.0) { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_DOUBLE; }
    public function getValue(): float { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) { $stream->putDoubleBE($this->value); }
        else { $stream->putDouble($this->value); }
    }
}

/** TAG_BYTE_ARRAY */
final class TagByteArray extends Tag {
    private string $value;
    public function __construct(string $name, string $value = "") { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_BYTE_ARRAY; }
    public function getValue(): string { return $this->value; }
    public function getArray(): array { return array_map('ord', str_split($this->value)); }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) {
            $stream->putIntBE(strlen($this->value));
        } else {
            $stream->putInt(strlen($this->value));
        }
        $stream->put($this->value);
    }
}

/** TAG_STRING */
final class TagString extends Tag {
    private string $value;
    public function __construct(string $name, string $value = "") { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_STRING; }
    public function getValue(): string { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) {
            $stream->putShortBE(strlen($this->value));
            $stream->put($this->value);
        } else {
            $stream->putString($this->value);
        }
    }
}

/** TAG_LIST */
final class TagList extends Tag {
    private int $listType;
    /** @var Tag[] */
    private array $values = [];
    public function __construct(string $name, int $listType = NBT::TAG_END, array $values = []) {
        parent::__construct($name);
        $this->listType = $listType;
        foreach ($values as $tag) {
            $this->add($tag);
        }
    }
    public function getType(): int { return NBT::TAG_LIST; }
    public function getValue(): array { return $this->values; }
    public function getListType(): int { return $this->listType; }
    public function getTag(int $index): ?Tag { return $this->values[$index] ?? null; }
    public function add(Tag $tag): void {
        if ($this->listType === NBT::TAG_END) {
            $this->listType = $tag->getType();
        } elseif ($tag->getType() !== $this->listType) {
            throw new \InvalidArgumentException("Tag type mismatch in TagList");
        }
        $this->values[] = $tag;
    }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        $stream->putByte($this->listType);
        if ($endianness === NBT::BIG_ENDIAN) { $stream->putIntBE(count($this->values)); }
        else { $stream->putInt(count($this->values)); }
        foreach ($this->values as $tag) {
            $tag->write($stream, $endianness);
        }
    }
}

/** TAG_COMPOUND */
final class TagCompound extends Tag {
    /** @var Tag[] */
    private array $tags = [];
    public function __construct(string $name, array $tags = []) {
        parent::__construct($name);
        foreach ($tags as $tag) {
            $this->set($tag);
        }
    }
    public function getType(): int { return NBT::TAG_COMPOUND; }
    public function getValue(): array { return $this->tags; }
    public function hasTag(string $name): bool { return isset($this->tags[$name]); }
    public function getTag(string $name): ?Tag { return $this->tags[$name] ?? null; }
    public function set(Tag $tag): void { $this->tags[$tag->getName()] = $tag; }
    public function remove(string $name): void { unset($this->tags[$name]); }
    public function getTags(): array { return $this->tags; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        foreach ($this->tags as $tag) {
            NBT::writeTag($tag, $stream, $endianness);
        }
        $stream->putByte(NBT::TAG_END);
    }
    public static function create(array $data): TagCompound {
        $compound = new TagCompound("");
        foreach ($data as $key => $value) {
            $compound->set(NBT::create($key, $value));
        }
        return $compound;
    }
}

/** TAG_INT_ARRAY */
final class TagIntArray extends Tag {
    private array $value;
    public function __construct(string $name, array $value = []) { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_INT_ARRAY; }
    public function getValue(): array { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) {
            $stream->putIntBE(count($this->value));
            foreach ($this->value as $v) { $stream->putIntBE($v); }
        } else {
            $stream->putInt(count($this->value));
            foreach ($this->value as $v) { $stream->putInt($v); }
        }
    }
}

/** TAG_LONG_ARRAY */
final class TagLongArray extends Tag {
    private array $value;
    public function __construct(string $name, array $value = []) { parent::__construct($name); $this->value = $value; }
    public function getType(): int { return NBT::TAG_LONG_ARRAY; }
    public function getValue(): array { return $this->value; }
    public function write(BinaryStream $stream, int $endianness = NBT::LITTLE_ENDIAN): void {
        if ($endianness === NBT::BIG_ENDIAN) {
            $stream->putIntBE(count($this->value));
            foreach ($this->value as $v) { $stream->putLongBE($v); }
        } else {
            $stream->putInt(count($this->value));
            foreach ($this->value as $v) { $stream->putLong($v); }
        }
    }
}

/**
 * NBT 模块入口
 */
final class NBTModule {
    public const VERSION = "1.1.0";
    public function getName(): string { return "NBTModule"; }
    public function getVersion(): string { return self::VERSION; }
}
