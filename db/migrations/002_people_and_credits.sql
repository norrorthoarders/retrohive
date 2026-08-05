-- People, and the credits that name them - director, artist, author, and
-- whatever role comes up next without needing a migration of its own to
-- add it, since credit_roles is data, not a fixed enum.
--
-- Safe to re-run: every CREATE is IF NOT EXISTS, and every ALTER TABLE
-- ADD CONSTRAINT is preceded by a check that the constraint does not
-- already exist, since MariaDB has no ADD CONSTRAINT IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS people (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id    INT UNSIGNED DEFAULT NULL,
  name          VARCHAR(160) NOT NULL,
  slug          VARCHAR(180) NOT NULL,
  born_year     SMALLINT UNSIGNED DEFAULT NULL,
  died_year     SMALLINT UNSIGNED DEFAULT NULL,
  website       VARCHAR(500) DEFAULT NULL,
  wikipedia_url VARCHAR(500) DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  library_key   INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_people_slug (library_key, slug),
  KEY idx_people_library (library_id, name),
  KEY idx_people_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_roles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id    INT UNSIGNED DEFAULT NULL,
  name          VARCHAR(80)  NOT NULL,
  slug          VARCHAR(100) NOT NULL,
  domains       SET('hardware','software','video','music') NOT NULL DEFAULT 'software',
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  library_key   INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_credit_roles_slug (library_key, slug),
  KEY idx_credit_roles_library (library_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credits (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id    INT UNSIGNED NOT NULL,
  title_id      INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NOT NULL,
  person_id     INT UNSIGNED DEFAULT NULL,
  company_id    INT UNSIGNED DEFAULT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_credits_title (title_id, sort_order),
  KEY idx_credits_role (role_id),
  KEY idx_credits_person (person_id),
  KEY idx_credits_company (company_id),
  KEY idx_credits_library (library_id),
  CONSTRAINT chk_credits_one_holder CHECK (
    (person_id IS NOT NULL AND company_id IS NULL) OR
    (person_id IS NULL AND company_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADD CONSTRAINT has no IF NOT EXISTS in MariaDB, so each is guarded by a
-- lookup against information_schema instead - the same shape a manual
-- "does this already exist" check would take, just run for you.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_people_library');
SET @sql := IF(@fk = 0,
  'ALTER TABLE people ADD CONSTRAINT fk_people_library FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_credit_roles_library');
SET @sql := IF(@fk = 0,
  'ALTER TABLE credit_roles ADD CONSTRAINT fk_credit_roles_library FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_credits_library');
SET @sql := IF(@fk = 0,
  'ALTER TABLE credits ADD CONSTRAINT fk_credits_library FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_credits_title');
SET @sql := IF(@fk = 0,
  'ALTER TABLE credits ADD CONSTRAINT fk_credits_title FOREIGN KEY (title_id) REFERENCES titles (id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_credits_role');
SET @sql := IF(@fk = 0,
  'ALTER TABLE credits ADD CONSTRAINT fk_credits_role FOREIGN KEY (role_id) REFERENCES credit_roles (id) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_credits_person');
SET @sql := IF(@fk = 0,
  'ALTER TABLE credits ADD CONSTRAINT fk_credits_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_credits_company');
SET @sql := IF(@fk = 0,
  'ALTER TABLE credits ADD CONSTRAINT fk_credits_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
