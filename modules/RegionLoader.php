<?php
/**
 * Module: Region 文件容器
 *
 * 对照原版 pocketmine/level/format/io/region/RegionLoader.php：
 * - 4096 字节位置表 + 4096 字节时间表
 * - 定位索引 = (($x & 31) + ($z & 31) * 32) * 4，条目 = 3 字节扇区偏移 + 1 字节扇区数
 * - 区块数据块：4 字节长度（BE）+ 1 字节压缩类型 + zlib 压缩负载
 * - 扇区 0/1 为表头，数据从扇区 2 开始；写入采用首次适配分配
 */
declare(strict_types=1);

namespace Genisys\Module;

final class RegionLoader
{
    public const COMPRESSION_ZLIB = 2; // 1 = GZIP（旧版），2 = zlib，3 = 不压缩

    public const MAX_SECTOR_COUNT = 255;
    private const MAX_CHUNKS_PER_REGION = 32 * 32;
    private const HEADER_SIZE = 8192;
    private const SECTOR_SIZE = 4096;

    private string $filePath;
    /** @var resource|null */
    private $fileHandle = null;
    private bool $isWritable;

    /** @var array<int, array{offset:int, sectors:int}> 区块位置（索引 = (x&31)+(z&31)*32） */
    private array $locations = [];
    /** @var array<int, int> 区块时间戳 */
    private array $timestamps = [];

    /** @var array<int, bool> 已占用扇区位图（索引 = 扇区号） */
    private array $sectorBitmap = [];

    public function __construct(string $filePath, bool $isWritable = true)
    {
        $this->filePath = $filePath;
        $this->isWritable = $isWritable;
        $this->open();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function open(): void
    {
        $exists = file_exists($this->filePath);
        if (!$exists && !$this->isWritable) {
            return; // 只读模式且文件不存在：空区域
        }
        if (!$exists) {
            $dir = dirname($this->filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
        $mode = $exists ? 'r+b' : 'w+b';
        $handle = fopen($this->filePath, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open region file: {$this->filePath}");
        }
        $this->fileHandle = $handle;
        $this->loadHeader();
    }

    /**
     * 加载表头并构建扇区位图（对照原版 loadLocationTable）
     */
    private function loadHeader(): void
    {
        clearstatcache(true, $this->filePath);
        $size = filesize($this->filePath);
        if ($size === false || $size < self::HEADER_SIZE) {
            // 新文件/损坏文件：初始化空表头
            $this->locations = [];
            $this->timestamps = [];
            $this->sectorBitmap = [];
            if ($this->fileHandle !== null && $this->isWritable) {
                fwrite($this->fileHandle, str_repeat("\x00", self::HEADER_SIZE));
                $this->writeHeader();
            }
            return;
        }

        fseek($this->fileHandle, 0);
        $header = fread($this->fileHandle, self::HEADER_SIZE);
        if ($header === false || strlen($header) < self::HEADER_SIZE) {
            throw new \RuntimeException("Region header truncated: {$this->filePath}");
        }

        $this->locations = [];
        $this->timestamps = [];
        for ($i = 0; $i < self::MAX_CHUNKS_PER_REGION; ++$i) {
            $entry = unpack('N', substr($header, $i * 4, 4))[1];
            $offset = $entry >> 8;
            $sectors = $entry & 0xff;
            if ($offset !== 0 || $sectors !== 0) {
                $this->locations[$i] = ['offset' => $offset, 'sectors' => $sectors];
            }
            $this->timestamps[$i] = unpack('N', substr($header, 4096 + $i * 4, 4))[1];
        }

        // 扇区位图
        $this->sectorBitmap = [];
        foreach ($this->locations as $loc) {
            for ($s = $loc['offset']; $s < $loc['offset'] + $loc['sectors']; ++$s) {
                $this->sectorBitmap[$s] = true;
            }
        }
    }

    private function writeHeader(): void
    {
        if ($this->fileHandle === null) {
            return;
        }
        $locTable = str_repeat("\x00", 4096);
        foreach ($this->locations as $i => $loc) {
            $locTable = substr_replace(
                $locTable,
                pack('N', ($loc['offset'] << 8) | $loc['sectors']),
                $i * 4,
                4
            );
        }
        $timeTable = str_repeat("\x00", 4096);
        foreach ($this->timestamps as $i => $ts) {
            $timeTable = substr_replace($timeTable, pack('N', $ts), $i * 4, 4);
        }
        fseek($this->fileHandle, 0);
        fwrite($this->fileHandle, $locTable . $timeTable);
    }

    public static function chunkIndexInRegion(int $x, int $z): int
    {
        return ($x & 31) + ($z & 31) * 32;
    }

    public function chunkExists(int $x, int $z): bool
    {
        return isset($this->locations[self::chunkIndexInRegion($x, $z)]);
    }

    /**
     * 读取区块负载（未解压），不存在返回 null
     */
    public function readChunk(int $x, int $z): ?string
    {
        $loc = $this->locations[self::chunkIndexInRegion($x, $z)] ?? null;
        if ($loc === null || $this->fileHandle === null) {
            return null;
        }
        fseek($this->fileHandle, $loc['offset'] * self::SECTOR_SIZE);
        $data = fread($this->fileHandle, $loc['sectors'] * self::SECTOR_SIZE);
        if ($data === false || strlen($data) < 5) {
            return null;
        }
        $length = unpack('N', substr($data, 0, 4))[1];
        if ($length <= 1 || $length > strlen($data)) {
            return null;
        }
        return substr($data, 4, $length); // 压缩类型字节 + 压缩负载
    }

    /**
     * 解压区块负载为 NBT 原始字节
     */
    public function readChunkDecompressed(int $x, int $z): ?string
    {
        $raw = $this->readChunk($x, $z);
        if ($raw === null || $raw === '') {
            return null;
        }
        $compression = ord($raw[0]);
        $payload = substr($raw, 1);
        return match ($compression) {
            self::COMPRESSION_ZLIB => zlib_decode($payload),
            default => null, // GZIP（1）/不压缩（3）暂不支持
        };
    }

    /**
     * 写入区块：压缩 → 首次适配分配扇区 → 更新表头
     */
    public function writeChunk(int $x, int $z, string $decompressedPayload, int $timestamp = 0): void
    {
        if (!$this->isWritable || $this->fileHandle === null) {
            throw new \RuntimeException("Region file is read-only: {$this->filePath}");
        }
        $compressed = zlib_encode($decompressedPayload, ZLIB_ENCODING_DEFLATE, 7);
        if ($compressed === false) {
            throw new \RuntimeException("zlib_encode failed");
        }
        $block = pack('N', strlen($compressed) + 1) . chr(self::COMPRESSION_ZLIB) . $compressed;
        $neededSectors = (int)ceil(strlen($block) / self::SECTOR_SIZE);
        if ($neededSectors > self::MAX_SECTOR_COUNT) {
            throw new \RuntimeException("Chunk too large: $neededSectors sectors");
        }

        $index = self::chunkIndexInRegion($x, $z);
        $old = $this->locations[$index] ?? null;

        if ($old !== null && $old['sectors'] >= $neededSectors) {
            // 原地可容纳 → 复用原位置，释放收缩后的尾部扇区
            $offset = $old['offset'];
            for ($s = $offset; $s < $offset + $neededSectors; ++$s) {
                $this->sectorBitmap[$s] = true;
            }
            for ($s = $offset + $neededSectors; $s < $offset + $old['sectors']; ++$s) {
                unset($this->sectorBitmap[$s]);
            }
        } else {
            // 释放旧扇区后重新分配
            if ($old !== null) {
                for ($s = $old['offset']; $s < $old['offset'] + $old['sectors']; ++$s) {
                    unset($this->sectorBitmap[$s]);
                }
            }
            $offset = $this->allocateSectors($neededSectors);
            $this->locations[$index] = ['offset' => $offset, 'sectors' => $neededSectors];
        }

        $this->timestamps[$index] = $timestamp !== 0 ? $timestamp : time();

        $this->writeHeader();
        $padded = str_pad($block, $neededSectors * self::SECTOR_SIZE, "\x00");
        fseek($this->fileHandle, $offset * self::SECTOR_SIZE);
        fwrite($this->fileHandle, $padded);
    }

    /**
     * 首次适配分配连续扇区（对照原版 RegionLoader::allocate）
     */
    private function allocateSectors(int $count): int
    {
        $maxUsed = 1; // 扇区 0/1 为表头
        foreach (array_keys($this->sectorBitmap) as $s) {
            if ($s > $maxUsed) $maxUsed = $s;
        }
        $searchEnd = $maxUsed + $count + 1;
        for ($start = 2; $start <= $searchEnd; ++$start) {
            $free = true;
            for ($s = $start; $s < $start + $count; ++$s) {
                if (isset($this->sectorBitmap[$s])) {
                    $start = $s; // 跳过占用段
                    $free = false;
                    break;
                }
            }
            if ($free) {
                for ($s = $start; $s < $start + $count; ++$s) {
                    $this->sectorBitmap[$s] = true;
                }
                return $start;
            }
        }
        throw new \RuntimeException("Region file is full: {$this->filePath}");
    }

    /**
     * 落盘并释放句柄（对照原版 close）
     */
    public function close(): void
    {
        if ($this->fileHandle !== null) {
            fflush($this->fileHandle);
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
        $this->locations = [];
        $this->timestamps = [];
        $this->sectorBitmap = [];
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function countChunks(): int
    {
        return count($this->locations);
    }
}
