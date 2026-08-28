<?php
/**
 * One-time database installer — run once, then delete this folder.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = db_connect();

        $tables = [
            "CREATE TABLE IF NOT EXISTS users (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                name                  VARCHAR(100) NOT NULL,
                email                 VARCHAR(150) UNIQUE NOT NULL,
                password              VARCHAR(255) NOT NULL,
                role                  ENUM('admin','staff','client') DEFAULT 'client',
                company_name          VARCHAR(150),
                avatar_initials       VARCHAR(3),
                stripe_customer_id    VARCHAR(100),
                stripe_subscription_id VARCHAR(100),
                subscription_status   ENUM('active','trialing','past_due','canceled') DEFAULT 'trialing',
                subscription_plan     ENUM('starter','growth','agency') DEFAULT 'starter',
                trial_ends_at         DATETIME,
                reset_token           VARCHAR(100),
                reset_expires_at      DATETIME,
                email_verified_at     DATETIME,
                verify_token          VARCHAR(100),
                verify_code           VARCHAR(6),
                verify_expires_at     DATETIME,
                login_attempts        INT DEFAULT 0,
                locked_until          DATETIME,
                created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS bots (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                user_id               INT NOT NULL,
                name                  VARCHAR(100) NOT NULL,
                persona_description   TEXT,
                whatsapp_phone_id     VARCHAR(150),
                whatsapp_token        TEXT,
                whatsapp_verified     TINYINT(1) DEFAULT 0,
                instagram_page_id     VARCHAR(150),
                instagram_token       TEXT,
                instagram_verified    TINYINT(1) DEFAULT 0,
                openai_system_prompt  TEXT,
                qualifying_questions  JSON,
                qualify_trigger       TEXT,
                qualify_message       TEXT,
                disqualify_message    TEXT,
                calendly_link         VARCHAR(255),
                widget_enabled        TINYINT(1) DEFAULT 0,
                widget_color          VARCHAR(20) DEFAULT '#4aad36',
                is_active             TINYINT(1) DEFAULT 1,
                created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS leads (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                bot_id                INT NOT NULL,
                external_id           VARCHAR(200),
                name                  VARCHAR(100),
                platform              ENUM('whatsapp','instagram','widget') DEFAULT 'whatsapp',
                status                ENUM('new','in_progress','qualified','disqualified','booked') DEFAULT 'new',
                qualification_data    JSON,
                calendly_link_sent    TINYINT(1) DEFAULT 0,
                score                 INT DEFAULT 0,
                notes                 TEXT,
                bot_paused_until      DATETIME NULL,
                created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS conversations (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                lead_id     INT NOT NULL,
                role        ENUM('user','assistant','system') NOT NULL,
                message     TEXT NOT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS settings (
                key_name    VARCHAR(100) PRIMARY KEY,
                value       TEXT,
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS client_whatsapp_accounts (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                client_id             INT NOT NULL,
                waba_id               VARCHAR(50) NOT NULL,
                phone_number_id       VARCHAR(50) NOT NULL,
                business_token        TEXT NOT NULL,
                phone_display_number  VARCHAR(30) DEFAULT NULL,
                connection_status     ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
                connected_at          DATETIME DEFAULT NULL,
                created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_waba_phone (waba_id, phone_number_id),
                FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS whatsapp_messages_log (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                client_id       INT NOT NULL,
                phone_number_id VARCHAR(50) DEFAULT NULL,
                direction       ENUM('inbound','outbound') NOT NULL,
                wa_message_id   VARCHAR(100) DEFAULT NULL,
                from_number     VARCHAR(30) DEFAULT NULL,
                to_number       VARCHAR(30) DEFAULT NULL,
                message_body    TEXT,
                payload         JSON DEFAULT NULL,
                status          VARCHAR(30) DEFAULT NULL,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($tables as $sql) {
            $conn->query($sql);
        }

        $indexes = [
            'CREATE INDEX idx_bots_user ON bots(user_id)',
            'CREATE INDEX idx_leads_bot ON leads(bot_id)',
            'CREATE INDEX idx_leads_status ON leads(status)',
            'CREATE INDEX idx_leads_platform ON leads(platform)',
            'CREATE INDEX idx_leads_external ON leads(external_id)',
            'CREATE INDEX idx_conversations_lead ON conversations(lead_id)',
            'CREATE INDEX idx_bots_whatsapp ON bots(whatsapp_phone_id)',
            'CREATE INDEX idx_bots_instagram ON bots(instagram_page_id)',
        ];

        foreach ($indexes as $sql) {
            try {
                $conn->query($sql);
            } catch (mysqli_sql_exception $e) {
                // Index may already exist
                if (strpos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        }

        try {
            $conn->query('ALTER TABLE leads ADD COLUMN bot_paused_until DATETIME NULL AFTER notes');
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                // Column already exists on fresh installs
            }
        }

        $adminEmail = ADMIN_EMAIL;
        $existing = db_fetch('SELECT id FROM users WHERE email = ? AND role = \'admin\'', 's', [$adminEmail]);

        if (!$existing) {
            $adminPass = password_hash('Admin@12345', PASSWORD_BCRYPT);
            db_insert(
                'INSERT INTO users (name, email, password, role, company_name, avatar_initials, subscription_status)
                 VALUES (?, ?, ?, \'admin\', ?, ?, \'active\')',
                'sssss',
                ['Super Admin', $adminEmail, $adminPass, APP_NAME, 'SA']
            );
            $messages[] = 'Admin account created. Email: ' . $adminEmail . ' | Password: Admin@12345 — change immediately!';
        } else {
            $messages[] = 'Admin account already exists.';
        }

        $defaults = [
            'default_system_prompt' => 'You are an AI sales assistant. Qualify leads through natural conversation.',
            'trial_days' => (string) TRIAL_DAYS,
            'ai_model' => OPENAI_MODEL,
            'openai_model' => OPENAI_MODEL,
        ];

        foreach ($defaults as $key => $value) {
            db_execute(
                'INSERT INTO settings (key_name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = value',
                'ss',
                [$key, $value]
            );
        }

        $messages[] = 'All database tables created successfully.';
        $messages[] = 'IMPORTANT: Delete the /install/ folder immediately after setup.';

    } catch (Exception $e) {
        error_log('Setup error: ' . $e->getMessage());
        $errors[] = 'Setup failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= page_head('Database Setup') ?>
</head>
<body class="bg-background font-body text-on-surface min-h-[100dvh] flex items-center justify-center p-edge-margin safe-top safe-bottom">
    <div class="w-full max-w-md bg-surface-container-lowest rounded-2xl p-lg border border-outline-variant shadow-lg">
        <div class="flex items-center gap-sm mb-lg">
            <span class="material-symbols-outlined text-primary text-3xl">database</span>
            <h1 class="font-headline text-headline-mob text-on-surface">Database Setup</h1>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <div class="bg-error-container text-on-error-container rounded-xl p-md mb-md text-body-md"><?= sanitize($err) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="bg-primary-container/20 border border-primary text-on-primary-container rounded-xl p-md mb-md text-body-md"><?= sanitize($msg) ?></div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-body-md text-on-surface-variant mb-lg">
                This will create all required database tables and seed the super admin account.
                Run this only once, then delete <code>setup.php</code> from your server root (the <code>/install/</code> folder is blocked by .htaccess).
            </p>
            <form method="POST">
                <button type="submit" class="w-full h-14 rounded-xl bg-primary text-on-primary font-title text-title-md active:scale-95 transition-transform duration-150 touch-manipulation">
                    Run Setup
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
