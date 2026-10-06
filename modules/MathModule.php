<?php

declare(strict_types=1);

namespace Genisys\Module;

/**
 * 2D 向量
 */
readonly class Vector2 {
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0
    ) {}

    public function add(Vector2 $other): Vector2 {
        return new Vector2($this->x + $other->x, $this->y + $other->y);
    }

    public function subtract(Vector2 $other): Vector2 {
        return new Vector2($this->x - $other->x, $this->y - $other->y);
    }

    public function multiply(float $scalar): Vector2 {
        return new Vector2($this->x * $scalar, $this->y * $scalar);
    }

    public function divide(float $scalar): Vector2 {
        return new Vector2($this->x / $scalar, $this->y / $scalar);
    }

    public function dot(Vector2 $other): float {
        return $this->x * $other->x + $this->y * $other->y;
    }

    public function length(): float {
        return sqrt($this->x * $this->x + $this->y * $this->y);
    }

    public function lengthSquared(): float {
        return $this->x * $this->x + $this->y * $this->y;
    }

    public function normalize(): Vector2 {
        $len = $this->length();
        return $len > 0 ? $this->divide($len) : new Vector2();
    }

    public function distance(Vector2 $other): float {
        return $this->subtract($other)->length();
    }

    public function floor(): Vector2 {
        return new Vector2(floor($this->x), floor($this->y));
    }

    public function ceil(): Vector2 {
        return new Vector2(ceil($this->x), ceil($this->y));
    }

    public function equals(mixed $other): bool {
        return $other instanceof Vector2 && $this->x === $other->x && $this->y === $other->y;
    }

    public function __toString(): string {
        return "Vector2(x={$this->x}, y={$this->y})";
    }
}

/**
 * 3D 向量
 */
readonly class Vector3 {
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0
    ) {}

    public static function zero(): Vector3 {
        return new Vector3();
    }

    public static function one(): Vector3 {
        return new Vector3(1.0, 1.0, 1.0);
    }

    public function add(Vector3 $other): Vector3 {
        return new Vector3($this->x + $other->x, $this->y + $other->y, $this->z + $other->z);
    }

    public function subtract(Vector3 $other): Vector3 {
        return new Vector3($this->x - $other->x, $this->y - $other->y, $this->z - $other->z);
    }

    public function multiply(float $scalar): Vector3 {
        return new Vector3($this->x * $scalar, $this->y * $scalar, $this->z * $scalar);
    }

    public function divide(float $scalar): Vector3 {
        return new Vector3($this->x / $scalar, $this->y / $scalar, $this->z / $scalar);
    }

    public function dot(Vector3 $other): float {
        return $this->x * $other->x + $this->y * $other->y + $this->z * $other->z;
    }

    public function cross(Vector3 $other): Vector3 {
        return new Vector3(
            $this->y * $other->z - $this->z * $other->y,
            $this->z * $other->x - $this->x * $other->z,
            $this->x * $other->y - $this->y * $other->x
        );
    }

    public function length(): float {
        return sqrt($this->x * $this->x + $this->y * $this->y + $this->z * $this->z);
    }

    public function lengthSquared(): float {
        return $this->x * $this->x + $this->y * $this->y + $this->z * $this->z;
    }

    public function normalize(): Vector3 {
        $len = $this->length();
        return $len > 0 ? $this->divide($len) : new Vector3();
    }

    public function distance(Vector3 $other): float {
        return $this->subtract($other)->length();
    }

    public function distanceSquared(Vector3 $other): float {
        return $this->subtract($other)->lengthSquared();
    }

    public function lerp(Vector3 $other, float $t): Vector3 {
        return new Vector3(
            $this->x + ($other->x - $this->x) * $t,
            $this->y + ($other->y - $this->y) * $t,
            $this->z + ($other->z - $this->z) * $t
        );
    }

    public function floor(): Vector3 {
        return new Vector3(floor($this->x), floor($this->y), floor($this->z));
    }

    public function ceil(): Vector3 {
        return new Vector3(ceil($this->x), ceil($this->y), ceil($this->z));
    }

    public function round(): Vector3 {
        return new Vector3(round($this->x), round($this->y), round($this->z));
    }

    public function equals(mixed $other): bool {
        return $other instanceof Vector3 && $this->x === $other->x && $this->y === $other->y && $this->z === $other->z;
    }

    public function __toString(): string {
        return "Vector3(x={$this->x}, y={$this->y}, z={$this->z})";
    }
}

/**
 * 轴对齐包围盒
 */
readonly class AxisAlignedBB {
    public function __construct(
        public float $minX,
        public float $minY,
        public float $minZ,
        public float $maxX,
        public float $maxY,
        public float $maxZ
    ) {}

    public static function fromCoords(float $x1, float $y1, float $z1, float $x2, float $y2, float $z2): AxisAlignedBB {
        return new AxisAlignedBB(
            min($x1, $x2), min($y1, $y2), min($z1, $z2),
            max($x1, $x2), max($y1, $y2), max($z1, $z2)
        );
    }

    public static function fromCenter(Vector3 $center, float $sizeX, float $sizeY, float $sizeZ): AxisAlignedBB {
        return new AxisAlignedBB(
            $center->x - $sizeX / 2, $center->y - $sizeY / 2, $center->z - $sizeZ / 2,
            $center->x + $sizeX / 2, $center->y + $sizeY / 2, $center->z + $sizeZ / 2
        );
    }

    public function expand(float $x, float $y, float $z): AxisAlignedBB {
        return new AxisAlignedBB(
            $this->minX - $x, $this->minY - $y, $this->minZ - $z,
            $this->maxX + $x, $this->maxY + $y, $this->maxZ + $z
        );
    }

    public function contract(float $x, float $y, float $z): AxisAlignedBB {
        return new AxisAlignedBB(
            $this->minX + $x, $this->minY + $y, $this->minZ + $z,
            $this->maxX - $x, $this->maxY - $y, $this->maxZ - $z
        );
    }

    public function offset(float $x, float $y, float $z): AxisAlignedBB {
        return new AxisAlignedBB(
            $this->minX + $x, $this->minY + $y, $this->minZ + $z,
            $this->maxX + $x, $this->maxY + $y, $this->maxZ + $z
        );
    }

    public function intersects(AxisAlignedBB $other): bool {
        return $this->minX <= $other->maxX && $this->maxX >= $other->minX &&
               $this->minY <= $other->maxY && $this->maxY >= $other->minY &&
               $this->minZ <= $other->maxZ && $this->maxZ >= $other->minZ;
    }

    public function contains(Vector3 $point): bool {
        return $point->x >= $this->minX && $point->x <= $this->maxX &&
               $point->y >= $this->minY && $point->y <= $this->maxY &&
               $point->z >= $this->minZ && $point->z <= $this->maxZ;
    }

    public function containsBox(AxisAlignedBB $other): bool {
        return $this->minX <= $other->minX && $this->maxX >= $other->maxX &&
               $this->minY <= $other->minY && $this->maxY >= $other->maxY &&
               $this->minZ <= $other->minZ && $this->maxZ >= $other->maxZ;
    }

    public function getCenter(): Vector3 {
        return new Vector3(
            ($this->minX + $this->maxX) / 2,
            ($this->minY + $this->maxY) / 2,
            ($this->minZ + $this->maxZ) / 2
        );
    }

    public function getSize(): Vector3 {
        return new Vector3(
            $this->maxX - $this->minX,
            $this->maxY - $this->minY,
            $this->maxZ - $this->minZ
        );
    }

    public function volume(): float {
        return ($this->maxX - $this->minX) * ($this->maxY - $this->minY) * ($this->maxZ - $this->minZ);
    }

    public function surfaceArea(): float {
        $dx = $this->maxX - $this->minX;
        $dy = $this->maxY - $this->minY;
        $dz = $this->maxZ - $this->minZ;
        return 2 * ($dx * $dy + $dy * $dz + $dz * $dx);
    }

    public function getCorners(): array {
        return [
            new Vector3($this->minX, $this->minY, $this->minZ),
            new Vector3($this->maxX, $this->minY, $this->minZ),
            new Vector3($this->minX, $this->maxY, $this->minZ),
            new Vector3($this->maxX, $this->maxY, $this->minZ),
            new Vector3($this->minX, $this->minY, $this->maxZ),
            new Vector3($this->maxX, $this->minY, $this->maxZ),
            new Vector3($this->minX, $this->maxY, $this->maxZ),
            new Vector3($this->maxX, $this->maxY, $this->maxZ),
        ];
    }

    public function __toString(): string {
        return "AABB({$this->minX},{$this->minY},{$this->minZ} -> {$this->maxX},{$this->maxY},{$this->maxZ})";
    }
}

/**
 * 方块面向枚举
 */
enum BlockFace: int {
    case DOWN = 0;
    case UP = 1;
    case NORTH = 2;
    case SOUTH = 3;
    case WEST = 4;
    case EAST = 5;
    case SELF = 6;

    public function getOpposite(): BlockFace {
        return match($this) {
            self::DOWN => self::UP,
            self::UP => self::DOWN,
            self::NORTH => self::SOUTH,
            self::SOUTH => self::NORTH,
            self::WEST => self::EAST,
            self::EAST => self::WEST,
            self::SELF => self::SELF,
        };
    }

    public function getOffset(): Vector3 {
        return match($this) {
            self::DOWN => new Vector3(0, -1, 0),
            self::UP => new Vector3(0, 1, 0),
            self::NORTH => new Vector3(0, 0, -1),
            self::SOUTH => new Vector3(0, 0, 1),
            self::WEST => new Vector3(-1, 0, 0),
            self::EAST => new Vector3(1, 0, 0),
            self::SELF => new Vector3(0, 0, 0),
        };
    }

    public function getDirectionVector(): Vector3 {
        return $this->getOffset()->normalize();
    }

    public function isHorizontal(): bool {
        return in_array($this, [self::NORTH, self::SOUTH, self::WEST, self::EAST]);
    }

    public function isVertical(): bool {
        return in_array($this, [self::UP, self::DOWN]);
    }
}

/**
 * 面向工具
 */
final class Facing {
    public const HORIZONTAL = [BlockFace::NORTH, BlockFace::EAST, BlockFace::SOUTH, BlockFace::WEST];
    public const VERTICAL = [BlockFace::UP, BlockFace::DOWN];
    public const ALL = [BlockFace::DOWN, BlockFace::UP, BlockFace::NORTH, BlockFace::SOUTH, BlockFace::WEST, BlockFace::EAST];

    public static function fromYaw(float $yaw): BlockFace {
        $yaw = fmod($yaw + 360, 360);
        if ($yaw < 45 || $yaw >= 315) return BlockFace::SOUTH;
        if ($yaw < 135) return BlockFace::WEST;
        if ($yaw < 225) return BlockFace::NORTH;
        return BlockFace::EAST;
    }

    public static function fromYawPitch(float $yaw, float $pitch): BlockFace {
        $pitch = fmod($pitch + 360, 360);
        if ($pitch > 45 && $pitch < 135) return BlockFace::DOWN;
        if ($pitch > 225 && $pitch < 315) return BlockFace::UP;
        return self::fromYaw($yaw);
    }

    public static function getHorizontal(int $index): BlockFace {
        return self::HORIZONTAL[$index % 4];
    }

    public static function getOpposite(BlockFace $face): BlockFace {
        return $face->getOpposite();
    }

    public static function toVector(BlockFace $face): Vector3 {
        return $face->getDirectionVector();
    }
}

/**
 * 数学工具
 */
final class MathHelper {
    public const PI = M_PI;
    public const TWO_PI = 2 * M_PI;
    public const HALF_PI = M_PI / 2;
    public const DEG_TO_RAD = M_PI / 180;
    public const RAD_TO_DEG = 180 / M_PI;

    public static function clamp(float $value, float $min, float $max): float {
        return max($min, min($max, $value));
    }

    public static function lerp(float $a, float $b, float $t): float {
        return $a + ($b - $a) * $t;
    }

    public static function wrapDegrees(float $degrees): float {
        $degrees = fmod($degrees, 360);
        if ($degrees < -180) $degrees += 360;
        if ($degrees >= 180) $degrees -= 360;
        return $degrees;
    }

    public static function wrapRadians(float $radians): float {
        $radians = fmod($radians, self::TWO_PI);
        if ($radians < -self::PI) $radians += self::TWO_PI;
        if ($radians >= self::PI) $radians -= self::TWO_PI;
        return $radians;
    }

    public static function sin(float $radians): float {
        return sin($radians);
    }

    public static function cos(float $radians): float {
        return cos($radians);
    }

    public static function atan2(float $y, float $x): float {
        return atan2($y, $x);
    }

    public static function sqrt(float $value): float {
        return sqrt($value);
    }

    public static function floor(float $value): int {
        return (int)floor($value);
    }

    public static function ceil(float $value): int {
        return (int)ceil($value);
    }

    public static function random(): float {
        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
    }

    public static function randomRange(float $min, float $max): float {
        return $min + self::random() * ($max - $min);
    }

    public static function nextInt(int $bound): int {
        return random_int(0, $bound - 1);
    }

    public static function nextIntRange(int $min, int $max): int {
        return random_int($min, $max);
    }

    public static function abs(float $value): float {
        return abs($value);
    }

    public static function max(float $a, float $b): float {
        return max($a, $b);
    }

    public static function min(float $a, float $b): float {
        return min($a, $b);
    }
}

/**
 * 数学模块入口
 */
final class MathModule {
    public const VERSION = "1.0.0";
    public function getName(): string { return "MathModule"; }
    public function getVersion(): string { return self::VERSION; }
}