<?php

/**
 * Genisys PHP 8.x 兼容层 — WeakRef polyfill
 *
 * Provides a unified WeakRef interface that works with or without
 * PHP 8.0 built-in \WeakRef. When \WeakRef is available (standard
 * PHP 8.0+), it is used directly. When unavailable (custom builds),
 * a strong-reference fallback maintains API compatibility.
 */
declare(strict_types=1);

namespace Genisys\Compat;

/**
 * Unified WeakRef interface
 *
 * Uses PHP 8.0 built-in \WeakRef when available, falls back to
 * a strong-reference implementation when not available.
 */
final class WeakRefCompat
{
	/** @var \WeakRef|object|null */
	private $ref;

	/**
	 * @param object $object The object to hold a reference to.
	 */
	public function __construct(object $object)
	{
		if (class_exists(\WeakRef::class)) {
			$this->ref = new \WeakRef($object);
		} else {
			// Fallback: store a strong reference (no true weak ref available).
			// Objects tracked this way will not be garbage-collected while
			// the WeakRefCompat instance exists — leak detection is degraded
			// but the API remains functional.
			$this->ref = $object;
		}
	}

	/**
	 * Check whether the referenced object is still alive.
	 */
	public function isValid(): bool
	{
		if ($this->ref instanceof \WeakRef) {
			return $this->ref->isValid();
		}

		// Fallback: strong reference is always valid.
		return true;
	}

	/**
	 * Get the referenced object, or null if it has been garbage-collected.
	 */
	public function get(): ?object
	{
		if ($this->ref instanceof \WeakRef) {
			return $this->ref->get();
		}

		// Fallback: return the strong reference.
		return $this->ref;
	}
}