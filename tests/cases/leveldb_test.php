<?php
/** LevelDB 存储对照测试（Batch 4b）：真 0.14.3 键格式 + 扩展依赖 */
declare(strict_types=1);

use Genisys\Module\LevelModule;
use Genisys\Module\LevelDat;
use Genisys\Module\LevelDBProvider;

const LDB_WORLD = '/tmp/genisys-php8-test-ldb-world';

function ldb_available(): bool
{
    return class_exists('\LevelDB');
}

test('leveldb chunk blob has vanilla terrain size', function () {
    $chunk = [
        'blocks' => array_fill(0, 32768, 0),
        'heightMap' => array_fill(0, 256, 7),
        'biomeColors' => array_fill(0, 256, 0),
    ];
    $blob = LevelDBProvider::serializeChunk($chunk);
    check_same(LevelDBProvider::TERRAIN_SIZE, strlen($blob));
    // blob 布局：blocks(32768) + meta(16384) + sky(16384=0xff) + light(16384) + heightmap(256) + biomeColors(1024)
    check_same(0, ord($blob[0]));
    check_same(0xff, ord($blob[32768 + 16384]), 'skyLight section filled with 0xff');
    check_same(7, ord($blob[32768 + 16384 * 3]), 'heightmap starts after lights');
    // chunkIndex 键格式：LE int32 X + LE int32 Z
    check_same(pack('V', 3) . pack('V', -2), LevelDBProvider::chunkIndex(3, -2));
    check_same(pack('V', 3) . pack('V', -2) . '0', LevelModule::levelDBKey(3, -2, LevelDBProvider::ENTRY_TERRAIN));
});

test('leveldb provider chunk round trip', function () {
    $blocks = array_fill(0, 32768, 0);
    $blocks[5 * 256 + 3 * 16 + 4] = 41; // (4,5,3)
    $blocks[7 * 256 + 0 * 16 + 0] = 57; // (0,7,0)
    $chunk = [
        'x' => 6, 'z' => -9, 'blocks' => $blocks,
        'biomes' => array_fill(0, 256, 1),
        'heightMap' => array_fill(0, 256, 6),
        'biomeColors' => array_fill(0, 256, 0),
        'entities' => [], 'tiles' => [],
        'generated' => true, 'populated' => true,
    ];
    $blob = LevelDBProvider::serializeChunk($chunk);
    $back = LevelDBProvider::deserializeChunk($blob, LevelDBProvider::FLAG_GENERATED | LevelDBProvider::FLAG_POPULATED, 6, -9);
    check_same($chunk['blocks'], $back['blocks'], 'blocks identical');
    check_same(6, $back['heightMap'][7]);
    check($back['generated'] && $back['populated'], 'flags decoded');
});

test('leveldb level.dat uses 8-byte header + little-endian NBT', function () {
    $dir = '/tmp/genisys-php8-test-ldb-leveldat';
    @mkdir($dir);
    $dat = new LevelDat($dir);
    $dat->saveLeveldb([
        'RandomSeed' => 11223344,
        'LevelName' => 'LdbFixture',
        'SpawnX' => 12, 'SpawnY' => 70, 'SpawnZ' => -34,
        'Time' => 999,
    ]);

    $raw = file_get_contents($dir . '/level.dat');
    // 头：version=3（LE int）+ NBT 长度（LE int）；无 gzip 魔数（对照原版 leveldb/LevelDB::generate）
    check_same(3, unpack('V', substr($raw, 0, 4))[1], 'version 3');
    $len = unpack('V', substr($raw, 4, 4))[1];
    check_same(strlen($raw) - 8, $len, 'length prefix matches');
    check_same(0x0a, ord($raw[8]), 'root TAG_Compound');
    // 小端 NBT：RandomSeed 是 Long(0x04)
    check_same(11223344, (new LevelDat($dir))->loadLeveldb()['RandomSeed']);
    $loaded = (new LevelDat($dir))->loadLeveldb();
    check_same('LdbFixture', $loaded['LevelName']);
    check_same([12, 70, -34], [$loaded['SpawnX'], $loaded['SpawnY'], $loaded['SpawnZ']]);
    check_same(999, $loaded['Time']);
});

if (ldb_available()) {
    test('leveldb world persistence round trip with raw key verification', function () {
        exec('rm -rf ' . LDB_WORLD);
        mkdir(LDB_WORLD . '/db', 0777, true); // 触发 leveldb 格式检测

        $level = new LevelModule('LdbWorld', LDB_WORLD, 555, 'flat');
        check_same('leveldb', $level->getFormat(), 'format detected');
        $level->setSpawnPosition(8, 5, 8);
        $level->setBlockAt(3, 5, 4, 41);   // 金块
        $level->setBlockAt(-1, 4, -2, 22); // 跨区块
        $level->saveChunks();
        $level->close();
        unset($level);
        gc_collect_cycles(); // 释放 LevelDB 文件锁

        // level.dat
        check(file_exists(LDB_WORLD . '/level.dat'), 'level.dat written');
        $raw = file_get_contents(LDB_WORLD . '/level.dat');
        check_same(3, unpack('V', substr($raw, 0, 4))[1], 'level.dat version=3');

        // 原始键校验（直接读 db，验证 0.14.3 键结构）
        $db = new \LevelDB(LDB_WORLD . '/db');
        $idx = LevelDBProvider::chunkIndex(0, 0);
        $terrain = $db->get($idx . '0');
        check($terrain !== false, 'terrain entry "0" exists');
        check_same(LevelDBProvider::TERRAIN_SIZE, strlen($terrain), 'terrain blob size');
        check_same(41, ord($terrain[5 * 256 + 4 * 16 + 3]), 'gold block at (4,5,3) in raw blob');
        $flags = $db->get($idx . 'f');
        check($flags !== false && (ord($flags) & 0x03) === 0x03, 'flags entry "f" = generated|populated');
        check_same("\x02", $db->get($idx . 'v'), 'version entry "v" = \\x02');
        $idx2 = LevelDBProvider::chunkIndex(-1, -1);
        check($db->get($idx2 . '0') !== false, 'cross-chunk entry exists');
        @$db->close();
        unset($db);
        gc_collect_cycles();

        // 重开读回
        $level2 = new LevelModule('LdbWorld', LDB_WORLD);
        check_same('leveldb', $level2->getFormat());
        check_same(555, $level2->getSeed(), 'seed persisted');
        check_same([8, 5, 8], $level2->getSpawnPosition(), 'spawn persisted');
        check_same(41, $level2->getBlockAt(3, 5, 4), 'gold block persisted');
        check_same(22, $level2->getBlockAt(-1, 4, -2), 'cross-chunk block persisted');
        check_same(2, $level2->getBlockAt(5, 4, 5), 'flat terrain persisted');
        $level2->close();
    });
} else {
    test('leveldb extension missing (skipped)', function () {
        fwrite(STDERR, "SKIP: ext-leveldb 未安装，世界持久化测试跳过\n");
        check(true);
    });
}
