<?php

declare(strict_types=1);

namespace Genisys\Module;

/**
 * UUID 生成与解析
 */
readonly class UUID {
    private string $bytes;

    public function __construct(string $bytes = "") {
        if ($bytes === "") {
            $bytes = random_bytes(16);
            // Version 4 (random)
            $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
            // Variant RFC4122
            $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        }
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException("UUID must be 16 bytes");
        }
        $this->bytes = $bytes;
    }

    public static function fromString(string $uuid): self {
        $uuid = str_replace(['-', '{', '}'], '', $uuid);
        if (strlen($uuid) !== 32) {
            throw new \InvalidArgumentException("Invalid UUID string");
        }
        return new self(hex2bin($uuid));
    }

    public static function fromBytes(string $bytes): self {
        return new self($bytes);
    }

    public static function random(): self {
        return new self();
    }

    public function toString(): string {
        $hex = bin2hex($this->bytes);
        return vsprintf('%s-%s-%s-%s-%s', str_split($hex, [8, 4, 4, 4, 12]));
    }

    public function getBytes(): string {
        return $this->bytes;
    }

    public function getMostSignificantBits(): int {
        $bytes = substr($this->bytes, 0, 8);
        $val = 0;
        for ($i = 0; $i < 8; $i++) {
            $val = ($val << 8) | ord($bytes[$i]);
        }
        return $val;
    }

    public function getLeastSignificantBits(): int {
        $bytes = substr($this->bytes, 8, 8);
        $val = 0;
        for ($i = 0; $i < 8; $i++) {
            $val = ($val << 8) | ord($bytes[$i]);
        }
        return $val;
    }

    public function equals(mixed $other): bool {
        return $other instanceof self && $this->bytes === $other->bytes;
    }

    public function __toString(): string {
        return $this->toString();
    }
}

/**
 * 文本格式代码 (§)
 */
final class TextFormat {
    // Colors
    public const BLACK = "§0";
    public const DARK_BLUE = "§1";
    public const DARK_GREEN = "§2";
    public const DARK_AQUA = "§3";
    public const DARK_RED = "§4";
    public const DARK_PURPLE = "§5";
    public const GOLD = "§6";
    public const GRAY = "§7";
    public const DARK_GRAY = "§8";
    public const BLUE = "§9";
    public const GREEN = "§a";
    public const AQUA = "§b";
    public const RED = "§c";
    public const LIGHT_PURPLE = "§d";
    public const YELLOW = "§e";
    public const WHITE = "§f";

    // Formatting
    public const RESET = "§r";
    public const BOLD = "§l";
    public const ITALIC = "§o";
    public const UNDERLINE = "§n";
    public const STRIKETHROUGH = "§m";
    public const OBFUSCATED = "§k";

    // Aliases
    public const MAGIC = self::OBFUSCATED;

    public static function clean(string $text): string {
        return preg_replace('/§[0-9a-fklmnor]/i', '', $text);
    }

    public static function format(string $text, string ...$formats): string {
        return implode('', $formats) . $text . self::RESET;
    }
}

/**
 * 配置基类
 */
abstract class Config {
    protected array $data = [];
    protected string $file = "";

    public function __construct(string $file = "") {
        if ($file !== "") {
            $this->file = $file;
            $this->load();
        }
    }

    abstract protected function parse(string $data): array;
    abstract protected function dump(array $data): string;

    public function load(): void {
        if ($this->file !== "" && file_exists($this->file)) {
            $this->data = $this->parse(file_get_contents($this->file));
        }
    }

    public function save(): void {
        if ($this->file !== "") {
            file_put_contents($this->file, $this->dump($this->data));
        }
    }

    public function loadFromString(string $data): void {
        $this->data = $this->parse($data);
    }

    public function saveToString(): string {
        return $this->dump($this->data);
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->getNested($key, $default);
    }

    public function getNested(string $key, mixed $default = null): mixed {
        $keys = explode('.', $key);
        $value = $this->data;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void {
        $this->setNested($key, $value);
    }

    public function setNested(string $key, mixed $value): void {
        $keys = explode('.', $key);
        $last = array_pop($keys);
        $ref = &$this->data;
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) {
                $ref[$k] = [];
            }
            $ref = &$ref[$k];
        }
        $ref[$last] = $value;
    }

    public function remove(string $key): void {
        $keys = explode('.', $key);
        $last = array_pop($keys);
        $ref = &$this->data;
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) {
                return;
            }
            $ref = &$ref[$k];
        }
        unset($ref[$last]);
    }

    public function getAll(): array {
        return $this->data;
    }

    public function setAll(array $data): void {
        $this->data = $data;
    }

    public function exists(string $key): bool {
        return $this->getNested($key, new class {}) !== new class {};
    }

    public function __get(string $key): mixed {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool {
        return $this->exists($key);
    }

    public function __unset(string $key): void {
        $this->remove($key);
    }
}

/**
 * YAML 配置
 */
class YamlConfig extends Config {
    protected function parse(string $data): array {
        if (!function_exists('yaml_parse')) {
            throw new \RuntimeException("yaml extension not available");
        }
        $parsed = yaml_parse($data);
        return is_array($parsed) ? $parsed : [];
    }

    protected function dump(array $data): string {
        if (!function_exists('yaml_emit')) {
            throw new \RuntimeException("yaml extension not available");
        }
        return yaml_emit($data, YAML_UTF8_ENCODING);
    }
}

/**
 * JSON 配置
 */
class JsonConfig extends Config {
    protected function parse(string $data): array {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function dump(array $data): string {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

/**
 * Properties 配置 (键=值)
 */
class PropertiesConfig extends Config {
    protected function parse(string $data): array {
        $result = [];
        foreach (explode("\n", $data) as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === '#' || $line[0] === '!') continue;
            $pos = strpos($line, '=');
            if ($pos === false) $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $result[$key] = $value;
            }
        }
        return $result;
    }

    protected function dump(array $data): string {
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = "$key=$value";
        }
        return implode("\n", $lines) . "\n";
    }
}

/**
 * 网络工具
 */
final class Internet {
    public static function getURL(string $url, int $timeout = 10, array $headers = []): mixed {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300 ? $response : false;
    }

    public static function postURL(string $url, array $data, int $timeout = 10, array $headers = []): mixed {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => is_array($data) ? http_build_query($data) : $data,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300 ? $response : false;
    }

    public static function downloadURL(string $url, string $target, int $timeout = 30): bool {
        $ch = curl_init($url);
        $fp = fopen($target, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $result !== false && $code >= 200 && $code < 300;
    }
}

/**
 * 终端工具
 */
final class Terminal {
    public const RESET = "\033[0m";
    public const BOLD = "\033[1m";
    public const DIM = "\033[2m";
    public const UNDERLINE = "\033[4m";
    public const BLINK = "\033[5m";
    public const REVERSE = "\033[7m";
    public const HIDDEN = "\033[8m";

    // Foreground colors
    public const FG_BLACK = "\033[30m";
    public const FG_RED = "\033[31m";
    public const FG_GREEN = "\033[32m";
    public const FG_YELLOW = "\033[33m";
    public const FG_BLUE = "\033[34m";
    public const FG_MAGENTA = "\033[35m";
    public const FG_CYAN = "\033[36m";
    public const FG_WHITE = "\033[37m";
    public const FG_DEFAULT = "\033[39m";

    // Bright foreground
    public const FG_BRIGHT_BLACK = "\033[90m";
    public const FG_BRIGHT_RED = "\033[91m";
    public const FG_BRIGHT_GREEN = "\033[92m";
    public const FG_BRIGHT_YELLOW = "\033[93m";
    public const FG_BRIGHT_BLUE = "\033[94m";
    public const FG_BRIGHT_MAGENTA = "\033[95m";
    public const FG_BRIGHT_CYAN = "\033[96m";
    public const FG_BRIGHT_WHITE = "\033[97m";

    // Background colors
    public const BG_BLACK = "\033[40m";
    public const BG_RED = "\033[41m";
    public const BG_GREEN = "\033[42m";
    public const BG_YELLOW = "\033[43m";
    public const BG_BLUE = "\033[44m";
    public const BG_MAGENTA = "\033[45m";
    public const BG_CYAN = "\033[46m";
    public const BG_WHITE = "\033[47m";

    public static function colorize(string $text, string ...$codes): string {
        return implode('', $codes) . $text . self::RESET;
    }

    public static function progressBar(int $current, int $total, int $width = 40, string $prefix = "", string $suffix = ""): string {
        $percent = $total > 0 ? $current / $total : 1;
        $filled = (int)($width * $percent);
        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);
        return "$prefix [$bar] " . number_format($percent * 100, 1) . "% $suffix";
    }

    public static function table(array $headers, array $rows, array $align = []): string {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen((string)$cell));
            }
        }

        $format = implode(' | ', array_map(fn($w) => "%-{$w}s", $widths)) . "\n";
        $sep = implode('-+-', array_map(fn($w) => str_repeat('-', $w), $widths)) . "\n";

        $out = vsprintf($format, $headers);
        $out .= $sep;
        foreach ($rows as $row) {
            $out .= vsprintf($format, $row);
        }
        return $out;
    }

    public static function clearScreen(): void {
        echo "\033[2J\033[H";
    }

    public static function moveCursor(int $x, int $y): void {
        echo "\033[{$y};{$x}H";
    }

    public static function hideCursor(): void {
        echo "\033[?25l";
    }

    public static function showCursor(): void {
        echo "\033[?25h";
    }
}

/**
 * 插件日志器 (PSR-3 兼容)
 */
interface LoggerInterface {
    public function emergency(string $message, array $context = []): void;
    public function alert(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function notice(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function log(mixed $level, string $message, array $context = []): void;
}

final class PluginLogger implements LoggerInterface {
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;
    public const ALERT = 550;
    public const EMERGENCY = 600;

    private int $level = self::INFO;
    private string $prefix = "";
    private $handler = null;

    public function __construct(string $prefix = "", int $level = self::INFO) {
        $this->prefix = $prefix;
        $this->level = $level;
    }

    public function setLevel(int $level): void {
        $this->level = $level;
    }

    public function setHandler(callable $handler): void {
        $this->handler = $handler;
    }

    private function write(int $level, string $levelName, string $message, array $context = []): void {
        if ($level < $this->level) return;
        $time = date('Y-m-d H:i:s');
        $ctx = $context ? " " . json_encode($context, JSON_UNESCAPED_UNICODE) : "";
        $line = "[$time] [$levelName] {$this->prefix}$message$ctx";
        if ($this->handler) {
            ($this->handler)($level, $line, $context);
        } else {
            echo $line . PHP_EOL;
        }
    }

    public function emergency(string $message, array $context = []): void { $this->write(self::EMERGENCY, 'EMERGENCY', $message, $context); }
    public function alert(string $message, array $context = []): void { $this->write(self::ALERT, 'ALERT', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->write(self::CRITICAL, 'CRITICAL', $message, $context); }
    public function error(string $message, array $context = []): void { $this->write(self::ERROR, 'ERROR', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->write(self::WARNING, 'WARNING', $message, $context); }
    public function notice(string $message, array $context = []): void { $this->write(self::NOTICE, 'NOTICE', $message, $context); }
    public function info(string $message, array $context = []): void { $this->write(self::INFO, 'INFO', $message, $context); }
    public function debug(string $message, array $context = []): void { $this->write(self::DEBUG, 'DEBUG', $message, $context); }
    public function log(mixed $level, string $message, array $context = []): void { $this->write((int)$level, 'LOG', $message, $context); }
}

/**
 * 工具模块入口
 */
final class UtilsModule {
    public const VERSION = "1.0.0";
    public function getName(): string { return "UtilsModule"; }
    public function getVersion(): string { return self::VERSION; }
}