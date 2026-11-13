-- ============================================================================
-- v2.2 OFFLINE MODE upgrade
-- Adds the sync_log table: the permanent record of every offline action that
-- was synced back to the server. The UNIQUE key on client_id is the
-- idempotency guard: a retry of the same offline action can never be executed
-- twice, so a retry can never create a duplicate sale or payment.
-- Run once on the existing database (it does not touch any other table).
-- ============================================================================

CREATE TABLE IF NOT EXISTS sync_log (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id         VARCHAR(64)  NOT NULL,                -- idempotency key from the offline device
  user_id           INT UNSIGNED NULL,                    -- who did the action while offline
  action_type       VARCHAR(40)  NOT NULL,                -- pos_sale, table_draft_add, table_serve, table_settle, expense_create...
  payload           JSON         NULL,                    -- the queued data (for the audit trail)
  status            ENUM('processing','accepted','rejected') NOT NULL DEFAULT 'processing',
  message           VARCHAR(500) NULL,                    -- result summary / rejection reason
  result_ref_type   VARCHAR(30)  NULL,                    -- e.g. 'sale' when accepted
  result_ref_id     INT UNSIGNED NULL,
  client_created_at DATETIME     NULL,                    -- when the action was made offline
  synced_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sync_client (client_id),
  KEY idx_sync_user  (user_id, synced_at),
  KEY idx_sync_status (status, synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
