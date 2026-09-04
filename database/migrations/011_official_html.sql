-- Keep one active document per request; preserve existing PDF metadata as legacy.
SET @html_format_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'service_requests'
    AND column_name = 'document_format'
);
SET @html_format_sql := IF(
  @html_format_exists = 0,
  'ALTER TABLE service_requests ADD document_format VARCHAR(10) NOT NULL DEFAULT ''pdf'' AFTER document_size',
  'SELECT 1'
);
PREPARE html_format_stmt FROM @html_format_sql;
EXECUTE html_format_stmt;
DEALLOCATE PREPARE html_format_stmt;
