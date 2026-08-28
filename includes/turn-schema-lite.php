<?php
/**
 * Minimal conversation turn tables — no conversation-turn-engine.php (avoids cPanel 503).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function turn_schema_lite_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        db_connect()->query(
            "CREATE TABLE IF NOT EXISTS conversation_turns (
                id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                lead_id                 INT NOT NULL,
                bot_id                  INT NOT NULL,
                sender_phone            VARCHAR(32) NOT NULL,
                status                  VARCHAR(24) NOT NULL DEFAULT 'buffering',
                conversation_state      VARCHAR(32) NOT NULL DEFAULT 'DISCOVERY',
                started_at              DATETIME NOT NULL,
                last_message_at         DATETIME NOT NULL,
                finalize_after          DATETIME NOT NULL,
                finalized_at            DATETIME NULL,
                message_count           INT NOT NULL DEFAULT 0,
                media_count             INT NOT NULL DEFAULT 0,
                processing_generation   INT NOT NULL DEFAULT 0,
                processing_started_at   DATETIME NULL,
                processing_completed_at DATETIME NULL,
                combined_text           MEDIUMTEXT NULL,
                ai_response_text        MEDIUMTEXT NULL,
                suppression_reason      VARCHAR(255) NULL,
                created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_lead_status (lead_id, status),
                KEY idx_finalize (status, finalize_after),
                KEY idx_bot_sender (bot_id, sender_phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        db_connect()->query(
            "CREATE TABLE IF NOT EXISTS conversation_turn_messages (
                id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                turn_id             INT UNSIGNED NOT NULL,
                wa_message_id       VARCHAR(128) NOT NULL,
                message_type        VARCHAR(24) NOT NULL DEFAULT 'text',
                raw_text            TEXT NULL,
                caption             TEXT NULL,
                media_id            VARCHAR(128) NULL,
                media_url           VARCHAR(512) NULL,
                mime_type           VARCHAR(128) NULL,
                transcription       MEDIUMTEXT NULL,
                image_description   MEDIUMTEXT NULL,
                processing_status   VARCHAR(24) NOT NULL DEFAULT 'pending',
                wa_timestamp        BIGINT NULL,
                sort_order          INT NOT NULL DEFAULT 0,
                metadata_json       JSON NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_wa_message (wa_message_id),
                KEY idx_turn_order (turn_id, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        db_connect()->query(
            "CREATE TABLE IF NOT EXISTS conversation_turn_events (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                turn_id     INT UNSIGNED NOT NULL,
                event_type  VARCHAR(64) NOT NULL,
                detail_json JSON NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_turn_event (turn_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        db_connect()->query(
            "CREATE TABLE IF NOT EXISTS conversation_state (
                lead_id     INT NOT NULL PRIMARY KEY,
                state       VARCHAR(32) NOT NULL DEFAULT 'DISCOVERY',
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('turn_schema_lite_ensure: ' . $e->getMessage());
    }

    $done = true;
}
