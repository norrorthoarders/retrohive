<?php
declare(strict_types=1);

/**
 * Release identity.
 *
 * Still under development, so the number stays at 0.5 and does not climb with
 * every change. Version numbers earn their keep when somebody else is running
 * the software and needs to say which build they have; until then they are
 * bookkeeping, and bookkeeping that nobody reads goes stale and starts lying.
 *
 * The schema version is deliberately not written here: it is derived from the
 * files in db/migrations, so adding a migration cannot be forgotten in a second
 * place.
 */
const APP_VERSION      = '0.5';
const APP_RELEASED     = '2026-07-29';

/**
 * Optional: where to look for a newer release. Empty by default, and it stays
 * empty unless somebody sets it - a self-hosted collection catalogue has no
 * business phoning home without being asked.
 */
const APP_UPDATE_FEED  = '';
