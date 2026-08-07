-- Small per-user choices that are nobody else's business and nothing else's
-- concern.
--
-- Not a column on `users`. The first of these is which view each browser section
-- opens in, and the second will be something else - a column apiece means a
-- migration apiece for settings that are, individually, worth almost nothing.
-- The shape is notification_prefs': a row per user per key, primary-keyed on the
-- pair, cascading away with the account.
--
-- Deliberately not the `settings` table: that one holds instance configuration an
-- administrator sets, and mixing per-user preference into it would mean every
-- read had to say whose it was.
--
-- VARCHAR rather than TEXT for the value, because a preference that needs more
-- than 500 characters is not a preference.

CREATE TABLE IF NOT EXISTS user_prefs (
  user_id INT UNSIGNED NOT NULL,
  name    VARCHAR(60)  NOT NULL,
  value   VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (user_id, name),
  CONSTRAINT fk_userpref_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
