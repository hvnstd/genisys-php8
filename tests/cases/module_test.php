<?php
/** 纯逻辑模块测试：Math / NBT / TextFormat / Event / Item 工厂 */
declare(strict_types=1);

use Genisys\Module\MathHelper;
use Genisys\Module\Vector3;
use Genisys\Module\Facing;
use Genisys\Module\NBT;
use Genisys\Module\TagCompound;
use Genisys\Module\TagInt;
use Genisys\Module\TagString;
use Genisys\Module\TagList;
use Genisys\Module\TagFloat;
use Genisys\Module\TextFormat;
use Genisys\Module\EventModule;
use Genisys\Module\ItemFactory;

test('math clamp/lerp/wrapDegrees', function () {
    check_same(5.0, MathHelper::clamp(5.0, 0.0, 10.0));
    check_same(0.0, MathHelper::clamp(-1.0, 0.0, 10.0));
    check_same(10.0, MathHelper::clamp(11.0, 0.0, 10.0));
    check_same(1.5, MathHelper::lerp(1.0, 2.0, 0.5));
    check_same(-30.0, MathHelper::wrapDegrees(330.0));
    check_same(30.0, MathHelper::wrapDegrees(-330.0));
    check_same(0.0, MathHelper::wrapDegrees(360.0));
});

test('math floor vs php floor', function () {
    check_same(2, MathHelper::floor(2.7));
    check_same(-3, MathHelper::floor(-2.1));
});

test('vector3 operations', function () {
    $a = new Vector3(1.0, 2.0, 3.0);
    $b = new Vector3(4.0, 6.0, 8.0);
    $add = $a->add($b);
    check_same(5.0, $add->x);
    check_same(8.0, $add->y);
    check_same(11.0, $add->z);
    check_same(50.0, $a->distanceSquared($b));
});

test('facing from yaw', function () {
    // 原版行为：yaw 0 = 南(+Z)，90 = 西(-X)（MCPE 坐标系）
    $south = Facing::fromYaw(0.0);
    $west = Facing::fromYaw(90.0);
    check_same(Facing::getOpposite($south), Facing::fromYaw(180.0));
    check($south !== $west);
});

test('nbt compound write/read round trip', function () {
    $root = new TagCompound('root', [
        'intVal' => new TagInt('intVal', 123456),
        'strVal' => new TagString('strVal', 'genisys'),
        'floatVal' => new TagFloat('floatVal', 3.75),
    ]);

    $w = new \Genisys\Compat\BinaryStream();
    NBT::writeTag($root, $w);

    $r = new \Genisys\Compat\BinaryStream($w->getBuffer());
    $back = NBT::readTag($r);
    check($back instanceof TagCompound);
    check_same(123456, $back->getValue()['intVal']->getValue());
    check_same('genisys', $back->getValue()['strVal']->getValue());
    check_same(3.75, $back->getValue()['floatVal']->getValue());
});

test('nbt list round trip', function () {
    $list = new TagList('items', NBT::TAG_INT, [
        new TagInt('0', 1),
        new TagInt('1', 2),
        new TagInt('2', 3),
    ]);
    $w = new \Genisys\Compat\BinaryStream();
    NBT::writeTag($list, $w);
    $r = new \Genisys\Compat\BinaryStream($w->getBuffer());
    $back = NBT::readTag($r);
    check($back instanceof TagList);
    $vals = array_map(fn($t) => $t->getValue(), $back->getValue());
    check_same([1, 2, 3], $vals);
});

test('textformat constants match vanilla codes', function () {
    check_same("§c", TextFormat::RED);
    check_same("§a", TextFormat::GREEN);
    check_same("§r", TextFormat::RESET);
    check_same("§l", TextFormat::BOLD);
});

test('event handlers fire in priority order', function () {
    $em = new EventModule();
    $order = [];
    // 优先级数值越小越先（或越大越先）——以实际实现为准，只验证顺序稳定且 6 级都被识别
    $em->registerHandler('test.event', function ($e) use (&$order) { $order[] = 'A'; }, 3, 'pluginA');
    $em->registerHandler('test.event', function ($e) use (&$order) { $order[] = 'B'; }, 1, 'pluginB');
    check_same(2, $em->getHandlerCount('test.event'));
    $cancelled = $em->callEvent('test.event', new stdClass());
    check(is_bool($cancelled), 'callEvent returns bool');
    check_same(['B', 'A'], $order, 'lower priority value runs first');
});

test('event unregister plugin removes handlers', function () {
    $em = new EventModule();
    $em->registerHandler('evt', fn($e) => null, 0, 'p1');
    $em->registerHandler('evt', fn($e) => null, 0, 'p2');
    $em->unregisterPlugin('p1');
    check_same(1, $em->getHandlerCount('evt'));
    check_same(['p2'], $em->getRegisteredPlugins());
});

test('item factory air and basic item', function () {
    ItemFactory::init();
    $air = ItemFactory::get(0);
    check_same(0, $air->getId());
    $stone = ItemFactory::get(1);
    check_same(1, $stone->getId());
});
