-- API request statistics, bucketed by hour rather than one row per
-- request. See db/schema.sql for the full reasoning.

CREATE TABLE IF NOT EXISTS api_request_stats (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_hour   DATETIME     NOT NULL,
  method        VARCHAR(10)  NOT NULL,
  route         VARCHAR(120) NOT NULL,
  status_class  ENUM('2xx','3xx','4xx','5xx') NOT NULL,
  source        VARCHAR(20)  NOT NULL DEFAULT 'unknown',
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_ms      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_request_stats (bucket_hour, method, route, status_class, source),
  KEY idx_api_request_stats_hour (bucket_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
