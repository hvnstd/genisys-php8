<?php
/** 兼容层测试：RuntimeCompat / YamlAdapter(MiniYaml) / BinaryStream / WeakRefCompat */
declare(strict_types=1);

use Genisys\Compat\RuntimeCompat;
use Genisys\Compat\YamlAdapter;
use Genisys\Compat\BinaryStream;
use Genisys\Compat\WeakRefCompat;

test('runtime each()', function () {
    $arr = ['a' => 1, 'b' => 2];
    check_same(['a', 1], RuntimeCompat::each($arr), 'first');
    check_same(['b', 2], RuntimeCompat::each($arr), 'second');
    check_same(null, RuntimeCompat::each($arr), 'exhausted');
});

test('runtime assert()', function () {
    RuntimeCompat::assert(true);
    check_throws(fn() => RuntimeCompat::assert(false, 'boom'), \AssertionError::class);
});

test('yaml fallback parse scalars', function () {
    $y = YamlAdapter::getInstance();
    $out = $y->parse(<<<'YML'
# comment line
motd: "Hello Server"   # inline comment
port: 19132
ratio: 0.5
debug: true
announce: false
empty: null
name: plainstring
YML);
    check_same('Hello Server', $out['motd']);
    check_same(19132, $out['port']);
    check_same(0.5, $out['ratio']);
    check_same(true, $out['debug']);
    check_same(false, $out['announce']);
    check_same(null, $out['empty']);
    check_same('plainstring', $out['name']);
});

test('yaml fallback parse nested + list', function () {
    $y = YamlAdapter::getInstance();
    $out = $y->parse(<<<'YML'
server:
  name: Main
  port: 19133
worlds:
  - world
  - nether
YML);
    check_same('Main', $out['server']['name']);
    check_same(19133, $out['server']['port']);
    check_same(['world', 'nether'], $out['worlds']);
});

test('yaml fallback emit/parse round trip', function () {
    $y = YamlAdapter::getInstance();
    $data = ['a' => 1, 'b' => true, 'c' => 'txt', 'd' => ['x' => 0.5, 'y' => ['p', 'q']]];
    $round = $y->parse($y->emit($data));
    check_same($data, $round);
});

test('yaml fallback never throws when no parser installed', function () {
    // 本环境无 symfony/yaml、无 yaml 扩展，parse/emit 必须走 MiniYaml 降级
    $y = YamlAdapter::getInstance();
    check_same(42, $y->parse('answer: 42')['answer']);
});

test('binarystream scalar round trip (little-endian)', function () {
    $s = new BinaryStream();
    $s->putByte(0xAB);
    $s->putShort(0x1234);
    $s->putInt(0xDEADBEEF);
    $s->putLong(0x0123456789ABCDEF);
    $s->putFloat(3.5);
    $s->putDouble(6.25);

    $r = new BinaryStream($s->getBuffer());
    check_same(0xAB, $r->getByte());
    check_same(0x1234, $r->getShort());
    check_same(0xDEADBEEF, $r->getInt());
    check_same(0x0123456789ABCDEF, $r->getLong());
    check_same(3.5, $r->getFloat());
    check_same(6.25, $r->getDouble());
    check_same(strlen($s->getBuffer()), $r->getOffset(), 'all bytes consumed');
});

test('binarystream string/array round trip', function () {
    $s = new BinaryStream();
    $s->putString('hello world');
    $s->putInt(3); // TagByteArray 长度前缀
    $s->put("\x01\x02\x03");
    $r = new BinaryStream($s->getBuffer());
    check_same('hello world', $r->getString());
    check_same("\x01\x02\x03", $r->getByteArray());
});

test('binarystream underflow throws', function () {
    $r = new BinaryStream("\x01\x02");
    check_throws(fn() => $r->get(5), \UnderflowException::class);
});

test('weakref compat holds object', function () {
    $obj = new stdClass();
    $ref = new WeakRefCompat($obj);
    check_same($obj, $ref->get());
    check($ref->isValid(), 'ref should be valid');
});
