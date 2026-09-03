<?php

namespace App\Enums;

/**
 * Stable notification type keys.
 *
 * Each case represents a distinct notification that can be sent through
 * the platform. New types are added as product requirements are approved.
 *
 * Commission-reminder types (e.g. due, deadline-approaching, overdue) are
 * intentionally absent — they belong to MH-BE-022D after the remaining
 * product decisions from MH-BE-022B are resolved.
 */
enum NotificationType: string
{
    // ── Placeholder foundation type for testing / smoke ──────────────
    case Test = 'test';
}
