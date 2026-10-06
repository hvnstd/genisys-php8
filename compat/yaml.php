<?php
/**
 * Genisys PHP 8.x 兼容层 — YAML 解析适配器
 * 
 * 统一 yaml 扩展和 symfony/yaml 的接口
 */
declare(strict_types=1);

namespace Genisys\Compat;

final class YamlAdapter
{
    private static ?YamlAdapter $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function parse(string $yamlString): mixed
    {
        if (class_exists('Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parse($yamlString);
        }
        if (function_exists('yaml_parse')) {
            return \yaml_parse($yamlString);
        }
        return MiniYaml::parse($yamlString);
    }

    public function emit(array $data, int $flags = 0): string
    {
        if (class_exists('Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::dump($data, 4, 0, \Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP);
        }
        if (function_exists('yaml_emit')) {
            return \yaml_emit($data, $flags);
        }
        return MiniYaml::emit($data);
    }

    public function fixYamlIndexes(string $content): string
    {
        return $content;
    }
}

/**
 * 最小 YAML 子集解析/生成器（无任何扩展时的降级实现）
 *
 * 覆盖 Genisys 配置文件实际用到的特性：
 * 缩进式映射、"- " 序列、标量（int/float/bool/null/字符串）、
 * 单双引号字符串、行内 # 注释。
 */
final class MiniYaml
{
    public static function parse(string $yamlString): mixed
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $yamlString) as $line) {
            $trimmed = preg_replace('/(^|\s)#.*$/', '', $line);
            if (trim($trimmed) === '') {
                continue;
            }
            $lines[] = $trimmed;
        }
        $idx = 0;
        return self::parseBlock($lines, $idx, 0);
    }

    private static function parseBlock(array $lines, int &$idx, int $indent): mixed
    {
        if ($idx >= count($lines)) {
            return null;
        }
        $isList = self::isListLine($lines[$idx]);
        $result = $isList ? [] : [];
        while ($idx < count($lines)) {
            $line = $lines[$idx];
            $curIndent = strlen($line) - strlen(ltrim($line, ' '));
            if ($curIndent < $indent) {
                break;
            }
            $content = trim($line);
            if ($isList && str_starts_with($content, '- ')) {
                $item = substr($content, 2);
                $idx++;
                $result[] = self::parseInlineOrNested($item, $lines, $idx, $curIndent + 2);
            } elseif (!$isList && preg_match('/^("([^"]*)"|\'([^\']*)\'|[^:]+):\s*(.*)$/', $content, $m)) {
                $key = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : trim($m[1], ': '));
                $rest = trim($m[4]);
                $idx++;
                if ($rest === '') {
                    $result[$key] = self::parseBlock($lines, $idx, self::nextIndent($lines, $idx, $curIndent));
                } else {
                    $result[$key] = self::parseScalar($rest);
                }
            } else {
                // 无法识别的行：跳过，避免死循环
                $idx++;
            }
        }
        return $result;
    }

    private static function isListLine(string $line): bool
    {
        return str_starts_with(trim($line), '- ');
    }

    private static function nextIndent(array $lines, int $idx, int $parentIndent): int
    {
        if ($idx >= count($lines)) {
            return $parentIndent + 1;
        }
        return strlen($lines[$idx]) - strlen(ltrim($lines[$idx], ' '));
    }

    private static function parseInlineOrNested(string $item, array $lines, int &$idx, int $childIndent): mixed
    {
        if (preg_match('/^("([^"]*)"|\'([^\']*)\'|[^:]+):\s*(.*)$/', $item, $m)) {
            // "- key: value" 形式，继续吸收更深的缩进行
            $key = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : trim($m[1], ': '));
            $rest = trim($m[4]);
            $map = [];
            $map[$key] = $rest !== '' ? self::parseScalar($rest) : self::parseBlock($lines, $idx, self::nextIndent($lines, $idx, $childIndent - 2));
            return $map;
        }
        return self::parseScalar($item);
    }

    public static function parseScalar(string $raw): mixed
    {
        $s = trim($raw);
        if ($s === '' || $s === '~' || strtolower($s) === 'null') {
            return null;
        }
        // 行内流式序列：[a, b, c]（plugin.yml 常用写法）
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
            $inner = substr($s, 1, -1);
            if (trim($inner) === '') {
                return [];
            }
            return array_map(
                fn(string $part) => self::parseScalar(trim($part)),
                preg_split('/,(?=(?:[^"\']*["\'][^"\']*["\'])*[^"\']*$)/', $inner) ?: []
            );
        }
        // 行内流式映射：{a: 1, b: two}
        if (str_starts_with($s, '{') && str_ends_with($s, '}')) {
            $inner = substr($s, 1, -1);
            if (trim($inner) === '') {
                return [];
            }
            $map = [];
            foreach (preg_split('/,(?=(?:[^"\']*["\'][^"\']*["\'])*[^"\']*$)/', $inner) ?: [] as $part) {
                if (preg_match('/^("([^"]*)"|\'([^\']*)\'|[^:]+):\s*(.*)$/', trim($part), $m)) {
                    $key = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : trim($m[1], ': '));
                    $map[$key] = self::parseScalar(trim($m[4]));
                }
            }
            return $map;
        }
        if ($s === '""' || $s === "''") {
            return '';
        }
        $first = $s[0];
        if ($first === '"' && str_ends_with($s, '"')) {
            return str_replace(['\\"', '\\n'], ['"', "\n"], substr($s, 1, -1));
        }
        if ($first === "'" && str_ends_with($s, "'")) {
            return str_replace("''", "'", substr($s, 1, -1));
        }
        $lower = strtolower($s);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $s)) {
            return (int)$s;
        }
        if (is_numeric($s)) {
            return (float)$s;
        }
        return $s;
    }

    public static function emit(array $data): string
    {
        return self::emitBlock($data, 0);
    }

    private static function emitBlock(array $data, int $indent): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);
        $isList = array_is_list($data) && !(self::isAssocLike($data));
        foreach ($data as $key => $value) {
            if ($isList && is_scalar($value) && !is_bool($value)) {
                $out .= $pad . '- ' . self::emitScalar($value) . "\n";
            } elseif (is_array($value)) {
                $out .= $pad . self::emitKey($key) . ":\n";
                $out .= $value === [] ? $pad . "  {}\n" : self::emitBlock($value, $indent + 1);
            } else {
                $out .= $pad . self::emitKey($key) . ': ' . self::emitScalar($value) . "\n";
            }
        }
        return $out;
    }

    private static function isAssocLike(array $a): bool
    {
        return count($a) > 0 && array_keys($a) !== range(0, count($a) - 1) && !array_is_list($a);
    }

    private static function emitKey(int|string $key): string
    {
        return (string)$key;
    }

    private static function emitScalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        if ($v === null) {
            return 'null';
        }
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$v) . '"';
    }
}
