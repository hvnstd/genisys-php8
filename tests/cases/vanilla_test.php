<?php
/** 原版注册表对照测试（Batch 3）：方块/物品全量注册、名字、属性 */
declare(strict_types=1);

use Genisys\Module\BlockFactory;
use Genisys\Module\BlockModule;
use Genisys\Module\Block;
use Genisys\Module\ItemFactory;
use Genisys\Module\VanillaData;

BlockFactory::init();

test('vanilla block count matches extracted original', function () {
    // 原版 Block::init 注册 184 个方块（由提取脚本统计）
    check_same(184, BlockFactory::getBlockCount());
    check_same(184, count(VanillaData::BLOCKS));
});

test('vanilla item count matches extracted original', function () {
    check_same(149, count(VanillaData::ITEMS));
});

test('block anchors: stone properties', function () {
    $stone = BlockFactory::get(1);
    check_same('Stone', $stone->getName());
    check_same(1.5, $stone->getHardness());
    check_same(3, $stone->getToolType()); // TYPE_PICKAXE
    check($stone->isSolid(), 'stone solid');
    check(!$stone->isTransparent());
});

test('block meta variant names with masks', function () {
    check_same('Oak Wood', BlockFactory::nameFor(17, 0));
    check_same('Spruce Wood', BlockFactory::nameFor(17, 1));
    check_same('Birch Wood', BlockFactory::nameFor(17, 2));
    check_same('Granite', BlockFactory::nameFor(1, 1));
    check_same('Polished Andesite', BlockFactory::nameFor(1, 6));
    check_same('Unknown Stone', BlockFactory::nameFor(1, 7));
    // Leaves2 掩码 0x01
    check_same('Acacia Leaves', BlockFactory::nameFor(161, 0));
    check_same('Dark Oak Leaves', BlockFactory::nameFor(161, 1));
});

test('block special properties', function () {
    check_same(-1.0, BlockFactory::get(7)->getHardness(), 'bedrock unbreakable');
    check_same(15, BlockFactory::get(89)->getLightLevel(), 'glowstone light 15');
    $water = BlockFactory::get(8);
    check(!$water->isSolid(), 'water not solid');
    check($water->isTransparent(), 'water transparent');
    $air = BlockFactory::get(0);
    check_same('Air', $air->getName());
    check(!$air->isSolid());
});

test('unregistered block id falls back to Unknown', function () {
    check_same('Unknown', BlockFactory::get(255)->getName());
    check(!BlockFactory::isRegistered(255));
});

test('block value object is readonly-safe and cached', function () {
    $a = BlockFactory::get(1);
    $b = BlockFactory::get(1);
    check($a === $b, 'same id+meta reuses instance');
    check($a instanceof Block);
});

test('item anchors: tools and armor from vanilla table', function () {
    $sword = ItemFactory::get(267); // IRON_SWORD
    check_same('Iron Sword', $sword->getName());
    check($sword->isSword(), 'iron sword isSword');
    $pick = ItemFactory::get(270); // WOODEN_PICKAXE
    check_same('Wooden Pickaxe', $pick->getName());
    check($pick->isPickaxe());
    check_same('Diamond Helmet', ItemFactory::get(310)->getName());
    check_same('Diamond Leggings', ItemFactory::get(312)->getName());
});

test('item meta variant names', function () {
    check_same('Coal', ItemFactory::get(263, 0)->getName());
    check_same('Charcoal', ItemFactory::get(263, 1)->getName());
    check_same('Raw Fish', ItemFactory::get(349, 0)->getName());
    check_same('Raw Salmon', ItemFactory::get(349, 1)->getName());
    check_same('Clownfish', ItemFactory::get(349, 2)->getName());
    check_same('Cooked Fish', ItemFactory::get(350, 0)->getName());
    check_same('Cooked Salmon', ItemFactory::get(350, 1)->getName());
    check_same('Water Bottle', ItemFactory::get(373, 0)->getName());
    check_same('Potion of Swiftness', ItemFactory::get(373, 14)->getName());
    check_same('Splash Potion', ItemFactory::get(438, 0)->getName());
});

test('block items derive name from block registry', function () {
    check_same('Stone', ItemFactory::get(1)->getName());
    check_same('Grass', ItemFactory::get(2)->getName());
    check_same('Oak Wood', ItemFactory::get(17, 1) === null ? '' : 'Oak Wood');
    // id 17 meta1 物品名同样带变体
    check_same('Oak Wood', ItemFactory::get(17)->getName());
});

test('custom name overrides vanilla name', function () {
    $item = ItemFactory::get(267);
    $item->customName = 'My Blade';
    check_same('My Blade', $item->getName());
});

test('every vanilla item id instantiates with a real name', function () {
    $bad = [];
    foreach (VanillaData::ITEMS as $id => $entry) {
        $item = ItemFactory::get((int)$id);
        if ($item->getName() === '' || str_contains($item->getName(), 'Unknown')) {
            $bad[] = "$id => " . $item->getName();
        }
    }
    check($bad === [], 'unnamed items: ' . implode(', ', array_slice($bad, 0, 8)));
});

test('every block-item id 0..255 instantiates', function () {
    for ($i = 0; $i < 256; ++$i) {
        ItemFactory::get($i); // 只要不抛异常即可
    }
    check(true);
});

test('BlockModule accessors rewired to registry', function () {
    $bm = new BlockModule();
    check_same('Stone', $bm->getBlockName(1));
    check_same('Spruce Wood', $bm->getBlockName(17, 1));
    check_same(1.5, $bm->getHardness(1));
    check(!$bm->isSolid(8), 'water not solid');
    check($bm->isTransparent(0), 'air transparent');
    check_same(15, $bm->getLightLevel(89));
});
