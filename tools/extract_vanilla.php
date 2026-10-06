<?php
/**
 * 从原版 Genisys（/workspace/genisys）静态提取方块/物品注册表，
 * 生成 genisys-php8 的 modules/VanillaData.php 数据文件。
 *
 * 提取内容：
 *  - 方块：ID→类名、名字（含 meta 变体名 + 掩码）、solid/transparent、
 *          hardness、toolType、lightLevel、resistance
 *  - 物品：ID→类名、名字（构造器第 4 参 + meta 条件名）
 *
 * 用法：php tools/extract_vanilla.php [/workspace/genisys]
 */
declare(strict_types=1);

const ORIG = '/workspace/genisys/src/pocketmine';

function stripComments(string $php): string
{
    // 去掉 /* */ 和 // 注释（保留字符串内容足够安全的保守实现）
    $php = preg_replace('#/\*.*?\*/#s', '', $php);
    return preg_replace('#^\s*//.*$#m', '', $php);
}

function classConsts(string $src): array
{
    $consts = [];
    // 支持 "const A = 1;" 与 "const A = 1, B = 2;" 两种写法
    if (preg_match_all('/const\s+([A-Za-z_,\s0-9=;\-]+?)\s*;/', $src, $gm, PREG_SET_ORDER)) {
        foreach ($gm as $g) {
            foreach (explode(',', $g[1]) as $part) {
                if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(-?\d+)\s*$/', trim($part), $pm)) {
                    $consts[$pm[1]] = (int)$pm[2];
                }
            }
        }
    }
    return $consts;
}

function methodBody(string $src, string $method): ?string
{
    // 定位方法名后第一个 { ... } 平衡块
    if (!preg_match('/function\s+' . $method . '\s*\([^)]*\)\s*[^{]*\{/', $src, $mm, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $mm[0][1] + strlen($mm[0][0]) - 1;
    $depth = 0;
    for ($i = $start, $n = strlen($src); $i < $n; ++$i) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($src, $start + 1, $i - $start - 1);
        }
    }
    return null;
}

function classOf(string $src): ?array
{
    if (!preg_match('/(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+([A-Za-z_][A-Za-z0-9_]*))?/', $src, $m)) {
        return null;
    }
    return [$m[1], $m[2] ?? 'Block'];
}

/** 解析一个方块类文件的属性 */
function parseBlockClass(string $file, array $toolConsts): ?array
{
    $src = stripComments(file_get_contents($file));
    if (!str_contains($src, 'namespace pocketmine\block')) return null;
    [$cls, $parent] = classOf($src);
    $consts = classConsts($src);

    $info = [
        'class' => $cls,
        'parent' => $parent,
        'name' => null,       // meta 0 名字
        'metaNames' => null,  // ordered [metaIdx => name]
        'metaMask' => null,
        'hardness' => null,
        'tool' => null,
        'light' => null,
        'resistance' => null,
        'solidOverride' => null,
        'transparentOverride' => null,
    ];

    // isSolid/isTransparent 直接覆盖
    if (preg_match('/function\s+isSolid\s*\(\)\s*(?::\s*bool)?\s*\{\s*return\s+(true|false)/', $src, $m)) {
        $info['solidOverride'] = $m[1] === 'true';
    }
    if (preg_match('/function\s+isTransparent\s*\(\)\s*(?::\s*bool)?\s*\{\s*return\s+(true|false)/', $src, $m)) {
        $info['transparentOverride'] = $m[1] === 'true';
    }

    // 名字
    $body = methodBody($src, 'getName');
    if ($body !== null) {
        if (preg_match('/static\s+\$names\s*=\s*\[(.*?)\]/s', $body, $nm)) {
            $names = [];
        if (preg_match_all('/(?:self::([A-Za-z_][A-Za-z0-9_]*)|(\d+))\s*=>\s*"((?:[^"\\\\]|\\\\.)*)"/', $nm[1], $em, PREG_SET_ORDER)) {
            $autoIdx = 0;
            foreach ($em as $e) {
                if ($e[1] !== '') {
                    $idx = $consts[$e[1]] ?? $autoIdx; // 常量在注释/上游缺失时按顺序兜底
                    if (!isset($consts[$e[1]])) fwrite(STDERR, "WARN $cls: names 键 {$e[1]} 用序号 $idx 兜底\n");
                    $autoIdx++;
                } else {
                    $idx = (int)$e[2];
                }
                $names[$idx] = stripslashes($e[3]);
            }
        }
            if ($names !== []) {
                ksort($names);
                $info['metaNames'] = $names;
                $info['name'] = $names[0] ?? null;
            }
            if (preg_match('/meta\s*&\s*(0x[0-9a-fA-F]+|\d+)/', $body, $mk)) {
                $info['metaMask'] = str_starts_with($mk[1], '0x') ? hexdec(substr($mk[1], 2)) : (int)$mk[1];
            }
        } elseif (preg_match('/return\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $rm)) {
            $info['name'] = stripslashes($rm[1]);
        }
    }
    // 名字在构造器里的老风格（$this->name = "X"）
    if ($info['name'] === null && preg_match('/\$this->name\s*=\s*"([^"]*)"/', $src, $m)) {
        $info['name'] = $m[1];
    }

    // hardness
    $body = methodBody($src, 'getHardness');
    if ($body !== null && preg_match('/return\s+(-?\d+(?:\.\d+)?)/', $body, $m)) {
        $info['hardness'] = (float)$m[1];
    } elseif ($body !== null && str_contains($body, '$this->hardness')) {
        // 返回成员变量 → 从构造器/属性里找赋值
        if (preg_match('/\$this->hardness\s*=\s*(-?\d+(?:\.\d+)?)/', $src, $m)) {
            $info['hardness'] = (float)$m[1];
        }
    }

    // toolType
    $body = methodBody($src, 'getToolType');
    if ($body !== null && preg_match('/return\s+Tool::TYPE_([A-Z_]+)\s*;/', $body, $m)) {
        $info['tool'] = $toolConsts[$m[1]] ?? null;
    }

    // light / resistance
    $body = methodBody($src, 'getLightLevel');
    if ($body !== null && preg_match('/return\s+(-?\d+(?:\.\d+)?)/', $body, $m)) {
        $info['light'] = (int)$m[1];
    }
    $body = methodBody($src, 'getResistance');
    if ($body !== null && preg_match('/return\s+(-?\d+(?:\.\d+)?)/', $body, $m)) {
        $info['resistance'] = (float)$m[1];
    }
    if ($info['resistance'] === null && preg_match('/\$this->resistance\s*=\s*(-?\d+(?:\.\d+)?)/', $src, $m)) {
        $info['resistance'] = (float)$m[1];
    }

    return $info;
}

/** 解析一个物品类文件的名字 */
function parseItemClass(string $file): ?array
{
    $src = stripComments(file_get_contents($file));
    if (!str_contains($src, 'namespace pocketmine\item')) return null;
    [$cls, $parent] = classOf($src);
    $consts = classConsts($src);

    $info = ['class' => $cls, 'name' => null, 'metaNames' => null];
    if (preg_match('/parent::__construct\([^;]*?,\s*"((?:[^"\\\\]|\\\\.)*)"\s*\)\s*;/', $src, $m)) {
        $info['name'] = stripslashes($m[1]);
    } elseif (preg_match('/\$this->name\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $m)) {
        $info['name'] = stripslashes($m[1]);
    }
    // meta 条件名（Coal→Charcoal、Fish→Raw Salmon 等）
    if (preg_match_all('/\$this->meta\s*(?:===|==)\s*(?:self::([A-Za-z_][A-Za-z0-9_]*)|(\d+))\s*\)\s*\{\s*(?:\$this->name|\$name)\s*=\s*"((?:[^"\\\\]|\\\\.)*)"\s*;/', $src, $mm, PREG_SET_ORDER)) {
        $metaNames = [];
        foreach ($mm as $c) {
            $metaNames[$c[1] !== '' ? ($consts[$c[1]] ?? -1) : (int)$c[2]] = stripslashes($c[3]);
        }
        if ($metaNames !== []) $info['metaNames'] = $metaNames;
    }
    // 三元名（CookedFish 风格：meta===X ? "A" : "B"）；常量可能继承自父类，用全局表回退
    global $allItemConsts;
    if (preg_match_all('/\$meta\s*(?:===|==)\s*self::([A-Za-z_][A-Za-z0-9_]*)\s*\?\s*"((?:[^"\\\\]|\\\\.)*)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) {
            $idx = $consts[$t[1]] ?? $allItemConsts[$t[1]] ?? null;
            if ($idx !== null) {
                $info['metaNames'][$idx] = stripslashes($t[2]);
                if ($info['name'] === null) $info['name'] = stripslashes($t[3]);
            }
        }
    }
    // 前缀拼接名（SplashPotion 风格：return "Splash " . Potion::getNameByMeta(...)）
    if ($info['name'] === null && preg_match('/function\s+getNameByMeta[^{]*\{\s*return\s+"((?:[^"\\\\]|\\\\.)*)"\s*\.\s*\w+::getNameByMeta/', $src, $pm)) {
        $prefix = rtrim($pm[1]);
        $info['name'] = $prefix !== '' ? $prefix . ' Potion' : 'Unknown';
    }
    // 构造器内 $name 默认值 + elseif 条件链（Fish 风格）
    $ctor = methodBody($src, '__construct');
    if ($ctor !== null && preg_match('/\$name\s*=\s*"((?:[^"\\\\]|\\\\.)*)"\s*;/', $ctor, $dm)) {
        if ($info['name'] === null) $info['name'] = stripslashes($dm[1]);
    }
    // getNameByMeta() 的 switch 表（Potion/SplashPotion 风格）
    {
        $sw = methodBody($src, 'getNameByMeta');
        if ($sw !== null) {
            $metaNames = [];
            if (preg_match_all('/case\s+(?:self::([A-Za-z_][A-Za-z0-9_]*)|(\d+))\s*:\s*(?:case\s+(?:self::([A-Za-z_][A-Za-z0-9_]*)|(\d+))\s*:\s*)*return\s+"((?:[^"\\\\]|\\\\.)*)"\s*;/', $sw, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $namesForCase = [];
                    foreach ([$c[1], $c[3]] as $key) {
                        if ($key !== '') $namesForCase[] = $consts[$key] ?? null;
                    }
                    if ($c[2] !== '') $namesForCase[] = (int)$c[2];
                    if ($c[4] !== '') $namesForCase[] = (int)$c[4];
                    foreach ($namesForCase as $idx) {
                        if ($idx !== null) $metaNames[$idx] = stripslashes($c[5]);
                    }
                }
            }
            if ($metaNames !== []) $info['metaNames'] = $metaNames;
            // default 分支作为基础名（SplashPotion meta-0 = "Splash Potion"）
            if ($info['name'] === null && preg_match('/default\s*:\s*return\s+"((?:[^"\\\\]|\\\\.)*)"\s*;/', $sw, $dm)) {
                $info['name'] = stripslashes($dm[1]);
            }
        }
    }
    // 构造器里的 static $names（个别变体物品）
    if ($info['metaNames'] === null && preg_match('/static\s+\$names\s*=\s*\[(.*?)\]/s', $src, $nm)) {
        $consts = classConsts($src);
        $names = [];
        if (preg_match_all('/(?:self::([A-Za-z_][A-Za-z0-9_]*)|(\d+))\s*=>\s*"((?:[^"\\\\]|\\\\.)*)"/', $nm[1], $em, PREG_SET_ORDER)) {
            foreach ($em as $e) {
                $idx = $e[1] !== '' ? ($consts[$e[1]] ?? null) : (int)$e[2];
                if ($idx !== null) $names[$idx] = stripslashes($e[3]);
            }
        }
        if ($names !== []) $info['metaNames'] = $names;
    }
    return $info;
}

/** 解析一个 Xxx.php 注册文件（Block.php / Item.php）里的 $list 项与常量 */
function parseRegistry(string $file, array $extraConsts = []): array
{
    $src = stripComments(file_get_contents($file));
    $consts = $extraConsts + classConsts($src);
    $entries = [];
    if (preg_match_all('/self::\$list\[(?:self::([A-Za-z_][A-Za-z0-9_]*)|(-?\d+))\]\s*=\s*([A-Za-z_][A-Za-z0-9_]*)::class\s*;/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $e) {
            $id = $e[1] !== '' ? ($consts[$e[1]] ?? null) : (int)$e[2];
            if ($id === null) {
                fwrite(STDERR, "WARN: 无法解析注册键 {$e[1]}{$e[2]} → {$e[3]}\n");
                continue;
            }
            $entries[$id] = $e[3];
        }
    }
    return [$consts, $entries];
}

// ---------- 主流程 ----------

$origRoot = $argv[1] ?? ORIG;
$toolConsts = [];
$tsrc = stripComments(file_get_contents("$origRoot/item/Tool.php"));
preg_match_all('/const\s+TYPE_([A-Z_]+)\s*=\s*(\d+)\s*;/', $tsrc, $tm, PREG_SET_ORDER);
foreach ($tm as $t) $toolConsts[$t[1]] = (int)$t[2];

// 基类继承属性（在 block/ 目录里定义的抽象基类链）
$baseAttrs = [
    'Block' => ['solid' => true, 'transparent' => false],
    'Solid' => ['solid' => true, 'transparent' => false],
    'Transparent' => ['solid' => true, 'transparent' => true],
    'Thin' => ['solid' => true, 'transparent' => true],
    'Flowable' => ['solid' => false, 'transparent' => true],
    'Liquid' => ['solid' => false, 'transparent' => true],
    'Fallable' => ['solid' => true, 'transparent' => false],
    'Stair' => ['solid' => true, 'transparent' => true],
    'Door' => ['solid' => true, 'transparent' => true],
];

$blockIdsConsts = classConsts(stripComments(file_get_contents("$origRoot/block/BlockIds.php")));
[$blockConsts, $blockList] = parseRegistry("$origRoot/block/Block.php", $blockIdsConsts);
$blockConsts += $blockIdsConsts;
$itemIdsConsts = classConsts(stripComments(file_get_contents("$origRoot/item/ItemIds.php")));
[$itemConsts, $itemList] = parseRegistry("$origRoot/item/Item.php", $itemIdsConsts);
$itemConsts += $itemIdsConsts;

$blocks = [];
foreach (glob("$origRoot/block/*.php") ?: [] as $f) {
    if (basename($f) === 'Block.php' || basename($f) === 'BlockIds.php') continue;
    $parsed = parseBlockClass($f, $toolConsts);
    if ($parsed !== null) $blocks[$parsed['class']] = $parsed;
}

$items = [];
$allItemConsts = [];
foreach (glob("$origRoot/item/*.php") ?: [] as $f) {
    $allItemConsts += classConsts(stripComments(file_get_contents($f)));
}
foreach (glob("$origRoot/item/*.php") ?: [] as $f) {
    $base = basename($f, '.php');
    if (in_array($base, ['Item', 'Tool', 'Armor'], true)) continue;
    $parsed = parseItemClass($f);
    if ($parsed !== null) $items[$parsed['class']] = $parsed;
}

// 汇总方块表（按注册 ID）
$blockTable = [];
$missingClass = [];
foreach ($blockList as $id => $cls) {
    if (!isset($blocks[$cls])) {
        $missingClass[] = "$id=>$cls";
        continue;
    }
    $b = $blocks[$cls];
    // 沿继承链取 solid/transparent
    $chain = [$cls];
    $p = $b['parent'];
    while ($p !== 'Block' && $p !== null) {
        $chain[] = $p;
        $p = $blocks[$p]['parent'] ?? $baseAttrs[$p] ?? null ? ($blocks[$p]['parent'] ?? (isset($baseAttrs[$p]) ? 'Block' : null)) : null;
        if (isset($baseAttrs[$chain[count($chain) - 1]])) break;
        if ($p === null && !isset($baseAttrs[end($chain)])) break;
    }
    $solid = false;
    $transparent = false;
    foreach ($chain as $c) {
        if (isset($baseAttrs[$c])) {
            $solid = $baseAttrs[$c]['solid'];
            $transparent = $baseAttrs[$c]['transparent'];
            break;
        }
        if ($c === $cls) continue;
        if (isset($blocks[$c])) {
            $pi = $blocks[$c];
            if ($pi['solidOverride'] !== null || $pi['transparentOverride'] !== null) {
                $solid = $pi['solidOverride'] ?? true;
                $transparent = $pi['transparentOverride'] ?? false;
                break;
            }
            $p2 = $pi['parent'];
            if ($p2 !== 'Block') {
                array_splice($chain, array_search($c, $chain, true) + 1, 0, [$p2]);
            }
        }
    }
    if ($b['solidOverride'] !== null) $solid = $b['solidOverride'];
    if ($b['transparentOverride'] !== null) $transparent = $b['transparentOverride'];

    $blockTable[$id] = [
        'name' => $b['name'] ?? "Unknown #$id",
        'solid' => $solid,
        'transparent' => $transparent,
        'hardness' => $b['hardness'] ?? 0.0,
        'tool' => $b['tool'] ?? 0,
        'light' => $b['light'] ?? 0,
        'resistance' => $b['resistance'] ?? 0.0,
        'class' => $cls,
        'metaMask' => $b['metaMask'],
        'metaNames' => $b['metaNames'],
    ];
}

// 汇总物品表
$itemTable = [];
$missingItemClass = [];
foreach ($itemList as $id => $cls) {
    if (!isset($items[$cls])) {
        $missingItemClass[] = "$id=>$cls";
        continue;
    }
    $i = $items[$cls];
    $itemTable[$id] = [
        'name' => $i['name'] ?? "Unknown #$id",
        'class' => $cls,
        'metaNames' => $i['metaNames'],
    ];
}

// ---------- 生成 VanillaData.php ----------
function varExportPretty(mixed $v, int $indent = 0): string
{
    $pad = str_repeat('    ', $indent);
    if (is_array($v)) {
        $isList = array_is_list($v);
        $parts = [];
        foreach ($v as $k => $val) {
            $keyPart = $isList ? '' : (is_int($k) ? "$k => " : "'$k' => ");
            $parts[] = $pad . '    ' . $keyPart . varExportPretty($val, $indent + 1);
        }
        return "[\n" . implode(",\n", $parts) . ",\n" . $pad . ']';
    }
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_float($v)) return var_export($v, true);
    if (is_int($v)) return (string)$v;
    return "'" . addslashes((string)$v) . "'";
}

$blockOut = [];
$blockMetaOut = [];
foreach ($blockTable as $id => $b) {
    $blockOut[$id] = [
        'name' => $b['name'], 'solid' => $b['solid'], 'transparent' => $b['transparent'],
        'hardness' => $b['hardness'], 'tool' => $b['tool'], 'light' => $b['light'],
        'resistance' => $b['resistance'], 'class' => $b['class'],
    ];
    if ($b['metaNames'] !== null && count($b['metaNames']) > 1) {
        $blockMetaOut[$id] = ['mask' => $b['metaMask'] ?? 0x0F, 'names' => $b['metaNames']];
    }
}

$itemOut = [];
$itemMetaOut = [];
foreach ($itemTable as $id => $i) {
    $itemOut[$id] = ['name' => $i['name'], 'class' => $i['class']];
    if ($i['metaNames'] !== null) {
        $itemMetaOut[$id] = $i['metaNames'];
    }
}

$idRange = $blockConsts['AIR'] . '..' . ($blockConsts['CAMERA'] ?? '*');
$php = "<?php\n/**\n * 原版（Genisys 0.14.x）方块/物品数据表 — 由 tools/extract_vanilla.php 自动生成，勿手改。\n * 来源：/workspace/genisys/src/pocketmine/{block,item}，对照 ID {$idRange}。\n */\ndeclare(strict_types=1);\n\nnamespace Genisys\Module;\n\nfinal class VanillaData\n{\n";
$php .= "    public const BLOCKS = " . varExportPretty($blockOut, 1) . ";\n\n";
$php .= "    public const BLOCK_META_NAMES = " . varExportPretty($blockMetaOut, 1) . ";\n\n";
$php .= "    public const ITEMS = " . varExportPretty($itemOut, 1) . ";\n\n";
$php .= "    public const ITEM_META_NAMES = " . varExportPretty($itemMetaOut, 1) . ";\n}\n";
file_put_contents(__DIR__ . '/../modules/VanillaData.php', $php);

fprintf(STDERR, "blocks registered: %d (unresolved class: %d)\nitems registered: %d (unresolved class: %d)\n",
    count($blockTable), count($missingClass), count($itemTable), count($missingItemClass));
foreach (array_slice($missingClass, 0, 10) as $x) fprintf(STDERR, "  missing block class: $x\n");
foreach (array_slice($missingItemClass, 0, 10) as $x) fprintf(STDERR, "  missing item class: $x\n");
