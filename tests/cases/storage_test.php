<?php
/** 世界存储对照测试（Batch 4）：真 Anvil/McRegion/level.dat 格式 */
declare(strict_types=1);

use Genisys\Module\LevelModule;
use Genisys\Module\LevelDat;
use Genisys\Module\RegionLoader;
use Genisys\Module\AnvilProvider;
use Genisys\Module\McRegionProvider;
use Genisys\Module\NBT;
use Genisys\Module\TagCompound;
use Genisys\Module\TagInt;
use Genisys\Module\TagString;
use Genisys\Module\TagByteArray;
use Genisys\Compat\BinaryStream;

const WORLD_DIR = '/tmp/genisys-php8-test-world';
const WORLD_DIR_MCR = '/tmp/genisys-php8-test-world-mcr';

function makeChunkFixture(int $x = 0, int $z = 0): array
{
    $blocks = array_fill(0, 32768, 0);
    // y=5 平面铺草，柱状测试块
    for ($cz = 0; $cz < 16; ++$cz) {
        for ($cx = 0; $cx < 16; ++$cx) {
            $blocks[5 * 256 + $cz * 16 + $cx] = 2;
        }
    }
    $blocks[7 * 256 + 3 * 16 + 4] = 41; // (4,7,3) 金块
    $biomes = array_fill(0, 256, 1);
    $heightMap = array_fill(0, 256, 6);
    $heightMap[0] = 8;
    return [
        'x' => $x, 'z' => $z, 'blocks' => $blocks, 'biomes' => $biomes,
        'heightMap' => $heightMap, 'biomeColors' => array_fill(0, 256, 0),
        'entities' => [], 'tiles' => [], 'generated' => true, 'populated' => true,
    ];
}

test('big-endian NBT byte layout matches vanilla spec', function () {
    // 根 compound "" 含 int "A"=1 的标准编码：
    // 0x0A + 名长(2B BE)=0 + 0x03 + 名长=1 + 'A' + 值(4B BE)=1 + TAG_END
    $root = new TagCompound('', ['A' => new TagInt('A', 1)]);
    $s = new BinaryStream();
    NBT::writeTag($root, $s, NBT::BIG_ENDIAN);
    check_same(
        "\x0a\x00\x00" . "\x03\x00\x01A" . "\x00\x00\x00\x01" . "\x00",
        $s->getBuffer(),
        'BE compound encoding'
    );
});

test('big-endian NBT round trip with all numeric types', function () {
    $src = new TagCompound('root', [
        'i' => new TagInt('i', -123456),
        's' => new Genisys\Module\TagShort('s', 40000),
        'l' => new Genisys\Module\TagLong('l', PHP_INT_MAX),
        'f' => new Genisys\Module\TagFloat('f', 3.5),
        'd' => new Genisys\Module\TagDouble('d', 6.25),
        'str' => new TagString('str', 'hello 大端'),
        'bytes' => new TagByteArray('bytes', "\x01\x02\x03"),
    ]);
    $w = new BinaryStream();
    NBT::writeTag($src, $w, NBT::BIG_ENDIAN);
    $r = new BinaryStream($w->getBuffer());
    $back = NBT::readTag($r, NBT::BIG_ENDIAN);
    check($back instanceof TagCompound);
    check_same(-123456, $back->getTag('i')->getValue());
    check_same(40000, $back->getTag('s')->getValue());
    check_same(PHP_INT_MAX, $back->getTag('l')->getValue());
    check_same(3.5, $back->getTag('f')->getValue());
    check_same(6.25, $back->getTag('d')->getValue());
    check_same('hello 大端', $back->getTag('str')->getValue());
    check_same("\x01\x02\x03", $back->getTag('bytes')->getValue());
});

test('level.dat is gzip NBT with Data compound (vanilla layout)', function () {
    $dir = '/tmp/genisys-php8-test-leveldat';
    @mkdir($dir);
    $dat = new LevelDat($dir);
    $dat->save([
        'RandomSeed' => 987654321,
        'LevelName' => 'Fixture',
        'SpawnX' => 10, 'SpawnY' => 64, 'SpawnZ' => -20,
        'Time' => 123456,
        'generatorName' => 'flat',
        'version' => 19133,
    ]);

    $raw = file_get_contents($dir . '/level.dat');
    check_same(0x1f, ord($raw[0]), 'gzip magic 1');
    check_same(0x8b, ord($raw[1]), 'gzip magic 2');
    $decoded = zlib_decode($raw);
    check_same(0x0a, ord($decoded[0]), 'root TAG_Compound');
    check_same(0, unpack('n', substr($decoded, 1, 2))[1], 'root name empty');
    // root 的第一个子标签也是 Compound（"Data"）
    check_same(0x0a, ord($decoded[3]), 'Data is compound');
    check_same('Data', substr($decoded, 6, 4), 'named Data');

    // 我们的读取端要能读回来
    $loaded = $dat->load();
    check_same(987654321, $loaded['RandomSeed']);
    check_same('Fixture', $loaded['LevelName']);
    check_same([10, 64, -20], [$loaded['SpawnX'], $loaded['SpawnY'], $loaded['SpawnZ']]);
    check_same(123456, $loaded['Time']);
});

test('region file header follows vanilla layout', function () {
    $path = '/tmp/genisys-php8-test-region/r.0.0.mca';
    @mkdir(dirname($path));
    @unlink($path);
    $region = new RegionLoader($path);
    $region->writeChunk(0, 0, '<nbt-payload-a>');
    $bigPayload = random_bytes(9000); // 不可压缩，必须占 3 个扇区
    $region->writeChunk(1, 0, $bigPayload);
    $region->close();

    $raw = file_get_contents($path);
    check(strlen($raw) >= 8192, 'header present');

    // 位置表：索引 = (x&31) + (z&31)*32，每项 4 字节
    $entry0 = unpack('N', substr($raw, 0, 4))[1];
    check_same(2, $entry0 >> 8, 'first chunk at sector 2');
    check(($entry0 & 0xff) >= 1, 'sector count >= 1');

    $entry1 = unpack('N', substr($raw, 4, 4))[1];
    check_same(3, $entry1 >> 8, 'second chunk allocated after first');
    check_same(3, $entry1 & 0xff, '9000 bytes need 3 sectors');

    // 时间表
    check(unpack('N', substr($raw, 4096, 4))[1] > 0, 'timestamp recorded');

    // 扇区 2：4 字节长度（BE）+ 压缩字节 2（zlib）+ zlib 负载
    $len = unpack('N', substr($raw, 2 * 4096, 4))[1];
    check_same(2, ord($raw[2 * 4096 + 4]), 'compression = zlib');
    $payload = zlib_decode(substr($raw, 2 * 4096 + 5, $len - 1));
    check_same('<nbt-payload-a>', $payload);

    // 第二个块解码
    $off = ($entry1 >> 8) * 4096;
    $len1 = unpack('N', substr($raw, $off, 4))[1];
    check($len1 > 9000, 'random payload stays large');
    $payload1 = zlib_decode(substr($raw, $off + 5, $len1 - 1));
    check_same($bigPayload, $payload1);

    // 重开读回
    $region2 = new RegionLoader($path, false);
    check_same('<nbt-payload-a>', $region2->readChunkDecompressed(0, 0));
    check_same($bigPayload, $region2->readChunkDecompressed(1, 0));
    check_same(null, $region2->readChunkDecompressed(5, 5));
    $region2->close();
});

test('anvil provider chunk round trip', function () {
    $chunk = makeChunkFixture(3, -2);
    $nbt = AnvilProvider::serializeChunk($chunk);
    // 编码结果是合法 BE NBT：根是 compound
    $root = NBT::readTag(new BinaryStream($nbt), NBT::BIG_ENDIAN);
    check($root instanceof TagCompound);
    check($root->getTag('Level') instanceof TagCompound, 'has Level compound');
    check_same(3, $root->getTag('Level')->getTag('xPos')->getValue());
    check_same(-2, $root->getTag('Level')->getTag('zPos')->getValue());

    $back = AnvilProvider::deserializeChunk($nbt);
    check_same($chunk['blocks'], $back['blocks'], 'blocks identical');
    check_same($chunk['biomes'], $back['biomes']);
    check_same($chunk['heightMap'], $back['heightMap']);
    check_same(3, $back['x']);
    check_same(-2, $back['z']);
    check($back['populated']);
});

test('mcregion provider chunk round trip', function () {
    $chunk = makeChunkFixture(1, 1);
    $nbt = McRegionProvider::serializeChunk($chunk);
    $back = McRegionProvider::deserializeChunk($nbt);
    check_same($chunk['blocks'], $back['blocks']);
    check_same($chunk['biomes'], $back['biomes']);
    check_same(1, $back['x']);
});

test('level module anvil persistence round trip', function () {
    exec('rm -rf ' . WORLD_DIR);
    $level = new LevelModule('TestWorld', WORLD_DIR, 12345, 'flat');
    $level->setSpawnPosition(8, 5, 8);
    $level->setBlockAt(3, 5, 4, 41);   // 金块
    $level->setBlockAt(0, 6, 0, 57);   // 钻石块
    $level->setBlockAt(-1, 4, -2, 22); // 跨区块（chunk -1,-1）
    $level->saveChunks();
    $level->close();

    check(file_exists(WORLD_DIR . '/level.dat'), 'level.dat written');
    check(file_exists(WORLD_DIR . '/region/r.0.0.mca'), 'anvil region written');
    check(file_exists(WORLD_DIR . '/region/r.-1.-1.mca'), 'cross-chunk region written');

    $level2 = new LevelModule('TestWorld', WORLD_DIR);
    check_same(12345, $level2->getSeed(), 'seed persisted');
    check_same([8, 5, 8], $level2->getSpawnPosition(), 'spawn persisted');
    check_same(41, $level2->getBlockAt(3, 5, 4), 'gold block persisted');
    check_same(57, $level2->getBlockAt(0, 6, 0), 'diamond block persisted');
    check_same(22, $level2->getBlockAt(-1, 4, -2), 'cross-chunk block persisted');
    check_same(2, $level2->getBlockAt(5, 4, 5), 'flat terrain persisted');
    check_same(0, $level2->getBlockAt(9, 9, 9), 'air above persisted');
    $level2->close();
});

test('level module mcregion persistence round trip', function () {
    exec('rm -rf ' . WORLD_DIR_MCR);
    mkdir(WORLD_DIR_MCR . '/region', 0777, true);
    touch(WORLD_DIR_MCR . '/region/r.0.0.mcr'); // 触发格式检测
    $level = new LevelModule('McrWorld', WORLD_DIR_MCR, 777, 'flat');
    check_same('mcregion', $level->getFormat(), 'format detected');
    $level->setBlockAt(2, 5, 2, 133);
    $level->saveChunks();
    $level->close();

    $level2 = new LevelModule('McrWorld', WORLD_DIR_MCR);
    check_same('mcregion', $level2->getFormat());
    check_same(777, $level2->getSeed());
    check_same(133, $level2->getBlockAt(2, 5, 2));
    $level2->close();
});
