<?php

declare(strict_types=1);

namespace Genisys\Module;

use Genisys\Compat\RuntimeCompat;
use Genisys\Compat\YamlAdapter;
use Genisys\Compat\WeakRefCompat;

/**
 * MemoryModule — PHP 8.x Memory Management
 *
 * Comprehensive memory management for PHP 8.x applications:
 *  - Three-level memory limits (soft / hard / global) with cascade responses
 *  - Object leak tracking via WeakRefCompat (PHP 8.0 \WeakRef with fallback)
 *  - Low-memory cascade: clear caches → GC → event notification → async reclamation
 *  - Deep memory inspection via ReflectionObject
 *  - Memory usage reporting and leak detection
 *
 * Only imports from the Genisys\Compat namespace.
 */
class MemoryModule
{
	// ------------------------------------------------------------------
	// Three-Level Memory Limits (bytes)
	// ------------------------------------------------------------------

	/** Soft limit  — triggers warning + light cleanup (128 MiB) */
	private const SOFT_LIMIT = 134_217_728;

	/** Hard limit  — triggers aggressive cleanup (256 MiB) */
	private const HARD_LIMIT = 268_435_456;

	/** Global limit — triggers emergency response (512 MiB) */
	private const GLOBAL_LIMIT = 536_870_912;

	// ------------------------------------------------------------------
	// Cascade Thresholds
	// ------------------------------------------------------------------

	/** Ratio of soft limit  at which warning cascade begins (80 %) */
	private const SOFT_WARNING_RATIO  = 0.80;

	/** Ratio of hard limit  at which emergency cascade begins (90 %) */
	private const HARD_WARNING_RATIO  = 0.90;

	// ------------------------------------------------------------------
	// Leak Tracking
	// ------------------------------------------------------------------

	/** @var array<string, WeakRefCompat>  Weak references for leak tracking */
	private array $leakWatch = [];

	/**
	 * @var array<string, array{class:string, label:?string, created:float, refs:int}>
	 */
	private array $leakInfo = [];

	/** @var array<int, array{timestamp:float, usage:int, peak:int}> */
	private array $memoryHistory = [];

	/** Maximum number of history entries to retain */
	private const MAX_HISTORY_SIZE = 100;

	// ------------------------------------------------------------------
	// Cascade State
	// ------------------------------------------------------------------

	private bool $cascadeActive = false;

	/** @var array<int, array{time:float, level:string, action:string}> */
	private array $cascadeLog = [];

	// ------------------------------------------------------------------
	// Constructor
	// ------------------------------------------------------------------

	public function __construct() {
		// Dependencies are accessed via static compat facades (RuntimeCompat,
		// YamlAdapter, WeakRefCompat) — no injection needed.
	}

	// ==================================================================
	// Memory Usage Tracking
	// ==================================================================

	/**
	 * Get current memory usage in bytes (real usage, not peak).
	 */
	public function getCurrentUsage(): int
	{
		return memory_get_usage(true);
	}

	/**
	 * Get peak memory usage in bytes.
	 */
	public function getPeakUsage(): int
	{
		return memory_get_peak_usage(true);
	}

	/**
	 * Get current usage as a human-readable string.
	 */
	public function getFormattedUsage(): string
	{
		return $this->formatBytes($this->getCurrentUsage());
	}

	/**
	 * Format bytes into a human-readable string (B / KB / MB / GB / TB).
	 */
	private function formatBytes(int $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$bytes  = max($bytes, 0);
		$pow    = (int) floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow    = min($pow, count($units) - 1);
		$bytes /= 1024 ** $pow;

		return round($bytes, 2) . ' ' . $units[$pow];
	}

	// ==================================================================
	// Three-Level Memory Limit Checking
	// ==================================================================

	/**
	 * Check memory against all three limit levels and trigger responses.
	 *
	 * @return array{level:string, usage:int, limit:int, ratio:float, actions:string[]}
	 */
	public function checkMemoryLimits(): array
	{
		$usage  = $this->getCurrentUsage();
		$ratio  = $usage / self::GLOBAL_LIMIT;
		$level  = 'normal';
		$actions = [];

		if ($usage >= self::GLOBAL_LIMIT) {
			$level   = 'global';
			$actions = $this->triggerGlobalLimit();
		} elseif ($usage >= self::HARD_LIMIT) {
			$level   = 'hard';
			$actions = $this->triggerHardLimit();
		} elseif ($usage >= self::SOFT_LIMIT) {
			$level   = 'soft';
			$actions = $this->triggerSoftLimit();
		}

		$this->recordMemoryHistory($usage);

		return [
			'level'   => $level,
			'usage'   => $usage,
			'limit'   => $this->getLimitForLevel($level),
			'ratio'   => $ratio,
			'actions' => $actions,
		];
	}

	/**
	 * Return the byte limit for a given level name.
	 */
	private function getLimitForLevel(string $level): int
	{
		return match ($level) {
			'soft'   => self::SOFT_LIMIT,
			'hard'   => self::HARD_LIMIT,
			'global' => self::GLOBAL_LIMIT,
			default  => self::SOFT_LIMIT,
		};
	}

	// ==================================================================
	// Soft-Limit Response
	// ==================================================================

	/**
	 * Trigger soft-limit cascade: clear caches → GC → event notify.
	 *
	 * @return string[]
	 */
	private function triggerSoftLimit(): array
	{
		$actions = [];

		// 1. Clear caches
		$cleared = $this->clearCaches();
		$actions[] = "cleared_caches:{$cleared}";

		// 2. Run garbage collection
		$collected = $this->runGarbageCollection();
		$actions[] = "gc_collected:{$collected}";

		// 3. Notify via event system
		$this->notifyLowMemory('soft', $this->getCurrentUsage());
		$actions[] = 'notified:soft';

		// 4. Log cascade
		$this->logCascade('soft', 'cache_clear+gc+notify');

		return $actions;
	}

	// ==================================================================
	// Hard-Limit Response
	// ==================================================================

	/**
	 * Trigger hard-limit cascade: full cleanup → GC → notify → async reclaim.
	 *
	 * @return string[]
	 */
	private function triggerHardLimit(): array
	{
		$actions = [];

		// 1. Clear all caches
		$cleared = $this->clearAllCaches();
		$actions[] = "cleared_all_caches:{$cleared}";

		// 2. Force garbage collection (multiple passes)
		$collected = $this->forceGarbageCollection();
		$actions[] = "gc_forced:{$collected}";

		// 3. Notify via event system
		$this->notifyLowMemory('hard', $this->getCurrentUsage());
		$actions[] = 'notified:hard';

		// 4. Trigger async reclamation
		$this->triggerAsyncReclamation();
		$actions[] = 'async_reclamation_triggered';

		// 5. Log cascade
		$this->logCascade('hard', 'full_cleanup+gc+notify+async');

		return $actions;
	}

	// ==================================================================
	// Global-Limit Response
	// ==================================================================

	/**
	 * Trigger global-limit cascade: emergency purge → aggressive GC →
	 * notify → async reclaim.
	 *
	 * @return string[]
	 */
	private function triggerGlobalLimit(): array
	{
		$actions = [];

		// 1. Emergency cache purge
		$this->emergencyPurge();
		$actions[] = 'emergency_purge';

		// 2. Force aggressive GC
		$this->forceAggressiveGC();
		$actions[] = 'aggressive_gc';

		// 3. Emergency notification
		$this->notifyLowMemory('global', $this->getCurrentUsage());
		$actions[] = 'notified:global';

		// 4. Async reclamation
		$this->triggerAsyncReclamation();
		$actions[] = 'async_reclamation';

		// 5. Log cascade
		$this->logCascade('global', 'emergency+aggressive_gc+notify+async');

		return $actions;
	}

	// ==================================================================
	// Cache Clearing
	// ==================================================================

	/**
	 * Clear application-level caches.
	 */
	private function clearCaches(): int
	{
		$cleared = 0;

		if (function_exists('opcache_reset')) {
			opcache_reset();
			$cleared++;
		}

		// RuntimeCompat provides cache-clearing helpers when available.
		if (method_exists(RuntimeCompat::class, 'clearCache')) {
			RuntimeCompat::clearCache();
			$cleared++;
		}

		return $cleared;
	}

	/**
	 * Clear all caches including persistent storage.
	 */
	private function clearAllCaches(): int
	{
		$cleared = $this->clearCaches();

		if (method_exists(RuntimeCompat::class, 'clearAllCaches')) {
			RuntimeCompat::clearAllCaches();
			$cleared++;
		}

		return $cleared;
	}

	/**
	 * Emergency purge of all caches and temporary data.
	 */
	private function emergencyPurge(): void
	{
		$this->clearAllCaches();
		$this->leakWatch = [];
		$this->leakInfo  = [];
	}

	// ==================================================================
	// Garbage Collection
	// ==================================================================

	/**
	 * Run a single GC cycle and return bytes collected.
	 */
	private function runGarbageCollection(): int
	{
		$before = memory_get_usage(true);
		gc_collect_cycles();
		$after = memory_get_usage(true);

		return max(0, $before - $after);
	}

	/**
	 * Force garbage collection with multiple passes.
	 */
	private function forceGarbageCollection(): int
	{
		$total = 0;
		for ($i = 0; $i < 3; $i++) {
			$total += $this->runGarbageCollection();
		}
		return $total;
	}

	/**
	 * Force aggressive garbage collection (5 passes).
	 */
	private function forceAggressiveGC(): void
	{
		for ($i = 0; $i < 5; $i++) {
			gc_collect_cycles();
		}
	}

	// ==================================================================
	// Event Notification
	// ==================================================================

	/**
	 * Dispatch a low-memory event through the concurrency layer if available.
	 */
	private function notifyLowMemory(string $level, int $usage): void
	{
		// Log to error log as the primary notification mechanism.
		// In a full deployment, this would dispatch through the
		// concurrency/event system (ThreadInterface-based).
		error_log(
			"[MemoryModule] Low memory ({$level}): "
			. $this->formatBytes($usage)
		);
	}

	// ==================================================================
	// Async Reclamation
	// ==================================================================

	/**
	 * Trigger asynchronous memory reclamation.
	 *
	 * Uses PHP 8.1+ Fiber for true async execution when available,
	 * falling back to synchronous reclamation otherwise.
	 */
	private function triggerAsyncReclamation(): void
	{
		if (class_exists(\Fiber::class)) {
			$fiber = new \Fiber(function (): void {
				$this->doAsyncReclamation();
			});
			$fiber->start();
		} else {
			// Fallback: synchronous reclamation.
			$this->doAsyncReclamation();
		}
	}

	/**
	 * Async reclamation task: clean up leaked objects, run GC, record state.
	 */
	private function doAsyncReclamation(): void
	{
		$this->doObjectCleanup();
		$this->forceGarbageCollection();
		$this->recordMemoryHistory($this->getCurrentUsage());
	}

	// ==================================================================
	// Object Leak Tracking  (WeakRefCompat — PHP 8.0 \WeakRef or fallback)
	// ==================================================================

	/**
	 * Track an object for leak detection using WeakRefCompat.
	 *
	 * WeakRefCompat uses PHP 8.0 built-in \WeakRef when available,
	 * falling back to a strong-reference implementation when not.
	 *
	 * @param object $object The object to track.
	 * @param string|null $label Optional human-readable label.
	 * @return string The spl_object_hash of the tracked object.
	 */
	public function trackObject(object $object, ?string $label = null): string
	{
		$hash     = spl_object_hash($object);
		$className = $object::class;

		// Use WeakRefCompat — works with or without PHP 8.0 \WeakRef.
		$weakRef = new WeakRefCompat($object);

		$this->leakWatch[$hash] = $weakRef;
		$this->leakInfo[$hash]  = [
			'class'   => $className,
			'label'   => $label ?? $className,
			'created' => microtime(true),
			'refs'    => 1,
		];

		return $hash;
	}

	/**
	 * Remove an object from leak tracking.
	 */
	public function removeWatch(string $hash): void
	{
		unset($this->leakWatch[$hash]);
		unset($this->leakInfo[$hash]);
	}

	/**
	 * Check whether a tracked object has been garbage-collected.
	 */
	public function isObjectLeaked(string $hash): bool
	{
		if (!isset($this->leakWatch[$hash])) {
			return false;
		}

		// WeakRefCompat::isValid() — returns false once the referent is gone
		// (when using real \WeakRef). With the strong-reference fallback,
		// isValid() always returns true, so leak detection is degraded
		// but the API remains functional.
		return !$this->leakWatch[$hash]->isValid();
	}

	/**
	 * Detect all leaked objects.
	 *
	 * @return array<int, array{hash:string, class:string, label:string, created:float, age:float}>
	 */
	public function detectLeaks(): array
	{
		$leaks = [];
		$now   = microtime(true);

		foreach ($this->leakInfo as $hash => $info) {
			if (!isset($this->leakWatch[$hash])) {
				continue;
			}

			if (!$this->leakWatch[$hash]->isValid()) {
				$leaks[] = [
					'hash'     => $hash,
					'class'    => $info['class'],
					'label'    => $info['label'],
					'created'  => $info['created'],
					'age'      => $now - $info['created'],
				];
			}
		}

		return $leaks;
	}

	/**
	 * Clean up tracking entries whose objects have been garbage-collected.
	 */
	public function doObjectCleanup(): void
	{
		foreach ($this->leakWatch as $hash => $ref) {
			if (!$ref->isValid()) {
				$this->removeWatch($hash);
			}
		}
	}

	// ==================================================================
	// Memory History
	// ==================================================================

	/**
	 * Record a memory-usage snapshot.
	 */
	private function recordMemoryHistory(int $usage): void
	{
		$this->memoryHistory[] = [
			'timestamp' => microtime(true),
			'usage'     => $usage,
			'peak'      => $this->getPeakUsage(),
		];

		if (count($this->memoryHistory) > self::MAX_HISTORY_SIZE) {
			array_shift($this->memoryHistory);
		}
	}

	/**
	 * Get the memory-usage history.
	 *
	 * @return array<int, array{timestamp:float, usage:int, peak:int}>
	 */
	public function getMemoryHistory(): array
	{
		return $this->memoryHistory;
	}

	// ==================================================================
	// Cascade Logging
	// ==================================================================

	/**
	 * Record a cascade event.
	 */
	private function logCascade(string $level, string $action): void
	{
		$this->cascadeLog[] = [
			'time'   => microtime(true),
			'level'  => $level,
			'action' => $action,
		];

		$this->cascadeActive = true;
	}

	/**
	 * Get the cascade event log.
	 *
	 * @return array<int, array{time:float, level:string, action:string}>
	 */
	public function getCascadeLog(): array
	{
		return $this->cascadeLog;
	}

	/**
	 * Whether any cascade has been triggered.
	 */
	public function isCascadeActive(): bool
	{
		return $this->cascadeActive;
	}

	// ==================================================================
	// Deep Memory Inspection  (ReflectionObject)
	// ==================================================================

	/**
	 * Dump server memory usage with deep object-graph traversal.
	 *
	 * Uses ReflectionObject to inspect object properties recursively
	 * up to a configurable depth.
	 *
	 * @param object|null $rootObject Optional root object to traverse from.
	 * @param int $maxDepth Maximum traversal depth (default 3).
	 * @return array{
	 *   total_usage:int, peak_usage:int,
	 *   limits:array{soft:int,hard:int,global:int},
	 *   formatted:array{total:string,peak:string,soft:string,hard:string,global:string},
	 *   objects:array<string,array{class:string,properties:array,depth:int}>
	 * }
	 */
	public function dumpServerMemory(
		?object $rootObject = null,
		int    $maxDepth = 3
	): array {
		$report = [
			'total_usage' => $this->getCurrentUsage(),
			'peak_usage'  => $this->getPeakUsage(),
			'limits'      => [
				'soft'   => self::SOFT_LIMIT,
				'hard'   => self::HARD_LIMIT,
				'global' => self::GLOBAL_LIMIT,
			],
			'formatted'   => [
				'total' => $this->formatBytes($this->getCurrentUsage()),
				'peak'  => $this->formatBytes($this->getPeakUsage()),
				'soft'  => $this->formatBytes(self::SOFT_LIMIT),
				'hard'  => $this->formatBytes(self::HARD_LIMIT),
				'global'=> $this->formatBytes(self::GLOBAL_LIMIT),
			],
			'objects'     => [],
		];

		if ($rootObject !== null) {
			$report['objects'] = $this->traverseObjectGraph(
				$rootObject, $maxDepth
			);
		}

		return $report;
	}

	/**
	 * Recursively traverse an object graph via ReflectionObject.
	 *
	 * @param object $object The object to inspect.
	 * @param int $maxDepth Maximum depth to traverse.
	 * @param int $currentDepth Current depth (internal, starts at 0).
	 * @param array<string,bool> $visited Set of visited object hashes (internal).
	 * @return array<string, array{class:string, properties:array, depth:int}>
	 */
	private function traverseObjectGraph(
		object $object,
		int    $maxDepth,
		int    $currentDepth = 0,
		array  &$visited = []
	): array {
		$hash = spl_object_hash($object);

		if (isset($visited[$hash]) || $currentDepth > $maxDepth) {
			return [];
		}

		$visited[$hash] = true;
		$result        = [];
		$reflection    = new \ReflectionObject($object);
		$properties    = [];

		foreach ($reflection->getProperties() as $prop) {
			$prop->setAccessible(true);
			$value    = $prop->getValue($object);
			$propName = $prop->getName();

			$propInfo = [
				'name' => $propName,
				'type' => $this->getPropertyType($value),
			];

			if (is_object($value)) {
				$propInfo['object_class'] = $value::class;
				$propInfo['object_hash']  = spl_object_hash($value);

				$nested = $this->traverseObjectGraph(
					$value, $maxDepth, $currentDepth + 1, $visited
				);
				if (!empty($nested)) {
					$propInfo['nested'] = $nested;
				}
			} elseif (is_array($value)) {
				$propInfo['array_count'] = count($value);

				$arrayObjects = [];
				foreach ($value as $key => $item) {
					if (is_object($item)) {
						$arrayObjects[(string) $key] = [
							'class' => $item::class,
							'hash'  => spl_object_hash($item),
						];
					}
				}
				if (!empty($arrayObjects)) {
					$propInfo['array_objects'] = $arrayObjects;
				}
			}

			$properties[$propName] = $propInfo;
		}

		$result[$hash] = [
			'class'      => $object::class,
			'properties' => $properties,
			'depth'      => $currentDepth,
		];

		return $result;
	}

	/**
	 * Return a human-readable type description for a value.
	 */
	private function getPropertyType(mixed $value): string
	{
		if (is_null($value)) {
			return 'null';
		}
		if (is_bool($value)) {
			return 'bool';
		}
		if (is_int($value)) {
			return 'int';
		}
		if (is_float($value)) {
			return 'float';
		}
		if (is_string($value)) {
			return 'string(' . strlen($value) . ')';
		}
		if (is_array($value)) {
			return 'array(' . count($value) . ')';
		}
		if (is_object($value)) {
			return 'object(' . $value::class . ')';
		}
		if (is_resource($value)) {
			return 'resource(' . get_resource_type($value) . ')';
		}
		return gettype($value);
	}

	// ==================================================================
	// Memory Reporting
	// ==================================================================

	/**
	 * Generate a comprehensive memory-usage report.
	 *
	 * @return array{
	 *   timestamp:float, current:int, peak:int,
	 *   formatted:array{current:string,peak:string},
	 *   limits:array{soft:int,hard:int,global:int},
	 *   ratios:array{soft:float,hard:float,global:float},
	 *   cascade:array{active:bool,log:array},
	 *   leaks:array,
	 *   history:array
	 * }
	 */
	public function generateReport(): array
	{
		$usage = $this->getCurrentUsage();

		return [
			'timestamp' => microtime(true),
			'current'   => $usage,
			'peak'      => $this->getPeakUsage(),
			'formatted' => [
				'current' => $this->formatBytes($usage),
				'peak'    => $this->formatBytes($this->getPeakUsage()),
			],
			'limits'    => [
				'soft'   => self::SOFT_LIMIT,
				'hard'   => self::HARD_LIMIT,
				'global' => self::GLOBAL_LIMIT,
			],
			'ratios'    => [
				'soft'   => $usage / self::SOFT_LIMIT,
				'hard'   => $usage / self::HARD_LIMIT,
				'global' => $usage / self::GLOBAL_LIMIT,
			],
			'cascade'   => [
				'active' => $this->cascadeActive,
				'log'    => $this->cascadeLog,
			],
			'leaks'     => $this->detectLeaks(),
			'history'   => $this->memoryHistory,
		];
	}

	/**
	 * Get a formatted, human-readable memory report string.
	 */
	public function getFormattedReport(): string
	{
		$report = $this->generateReport();
		$lines  = [];

		$lines[] = '=== Memory Usage Report ===';
		$lines[] = 'Current:  ' . $report['formatted']['current'];
		$lines[] = 'Peak:     ' . $report['formatted']['peak'];
		$lines[] = 'Soft:     ' . $this->formatBytes(self::SOFT_LIMIT)
			. ' (' . round($report['ratios']['soft'] * 100, 1) . '%)';
		$lines[] = 'Hard:     ' . $this->formatBytes(self::HARD_LIMIT)
			. ' (' . round($report['ratios']['hard'] * 100, 1) . '%)';
		$lines[] = 'Global:   ' . $this->formatBytes(self::GLOBAL_LIMIT)
			. ' (' . round($report['ratios']['global'] * 100, 1) . '%)';
		$lines[] = 'Cascade:  ' . ($report['cascade']['active'] ? 'ACTIVE' : 'idle');
		$lines[] = 'Leaks:    ' . count($report['leaks']);

		if (!empty($report['leaks'])) {
			$lines[] = '--- Leaks ---';
			foreach ($report['leaks'] as $leak) {
				$lines[] = '  ' . $leak['class']
					. ' (' . $leak['label'] . ')'
					. ' — age: ' . round($leak['age'], 2) . 's';
			}
		}

		return implode("\n", $lines);
	}

	// ==================================================================
	// Lifecycle / State Management
	// ==================================================================

	/**
	 * Reset cascade state (clear log and active flag).
	 */
	public function resetCascade(): void
	{
		$this->cascadeActive = false;
		$this->cascadeLog   = [];
	}

	/**
	 * Clear all tracking data (leaks, history, cascade log).
	 */
	public function reset(): void
	{
		$this->leakWatch    = [];
		$this->leakInfo     = [];
		$this->memoryHistory = [];
		$this->cascadeLog   = [];
		$this->cascadeActive = false;
	}
}