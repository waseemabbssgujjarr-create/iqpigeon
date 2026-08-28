<?php
/**
 * Shared client page header with inline notification bell (no overlap).
 *
 * @param string $title
 * @param array{subtitle?: string, actions?: string} $opts
 */
/**
 * @param 'open'|'close' $action
 */
function client_page_body_manage(string $action): void
{
    static $opened = false;
    if ($action === 'open' && !$opened) {
        $opened = true;
        echo '<div class="v2-page-body">';
        return;
    }
    if ($action === 'close' && $opened) {
        $opened = false;
        echo '</div>';
    }
}

function client_page_body_open(): void
{
    client_page_body_manage('open');
}

function client_page_body_close(): void
{
    client_page_body_manage('close');
}

function client_page_header(string $title, array $opts = []): void
{
    $subtitle = trim($opts['subtitle'] ?? '');
    $actions = $opts['actions'] ?? '';
    ?>
    <div class="v2-page-header">
        <header class="client-page-header">
            <div class="client-page-header__row">
                <div class="client-page-header__lead min-w-0 flex items-start gap-sm flex-1">
                    <?php client_mobile_menu_button(); ?>
                    <div class="client-page-header__main min-w-0">
                        <h1 class="client-page-header__title"><?= sanitize($title) ?></h1>
                        <?php if ($subtitle !== ''): ?>
                            <p class="client-page-header__subtitle"><?= sanitize($subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="client-page-header__actions">
                    <?= $actions ?>
                    <?php $variant = 'client'; include __DIR__ . '/theme-toggle.php'; ?>
                    <?php render_notification_bell_inline(); ?>
                </div>
            </div>
        </header>
    </div>
    <?php
    client_page_body_open();
}
