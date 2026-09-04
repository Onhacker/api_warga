-- UUID permohonan tetap valid; katalog dan snapshot memakai kunci lebih panjang.
-- Dapat dijalankan kembali pada database pusat yang sama.
SET @sync_aggregate_needs_upgrade := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'sync_messages'
    AND column_name = 'aggregate_id' AND character_maximum_length < 120
);
SET @sync_aggregate_upgrade_sql := IF(
  @sync_aggregate_needs_upgrade > 0,
  'ALTER TABLE sync_messages MODIFY aggregate_id VARCHAR(120) NOT NULL',
  'SELECT 1'
);
PREPARE sync_aggregate_upgrade_stmt FROM @sync_aggregate_upgrade_sql;
EXECUTE sync_aggregate_upgrade_stmt;
DEALLOCATE PREPARE sync_aggregate_upgrade_stmt;
