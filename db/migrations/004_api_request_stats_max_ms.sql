-- The slowest single call folded into each hourly bucket, alongside the
-- total this table already kept. An average alone hides exactly the
-- calls worth knowing about: a bucket that looks fast on average
-- because 99 calls were instant and one took four seconds is
-- indistinguishable from one where all 100 took forty milliseconds,
-- unless the max is kept too. See db/schema.sql for the full reasoning.

ALTER TABLE api_request_stats
  ADD COLUMN IF NOT EXISTS max_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_ms;
