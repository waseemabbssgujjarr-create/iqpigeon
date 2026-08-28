<?php
/**
 * Admin portal layout shell — shared v2 page header / body pattern.
 *
 *   require admin-shell.php;
 *   admin_layout_start(['width' => 'default|narrow|wide|full']);
 *   admin_page_header('Title', ['subtitle' => '…', 'actions' => '…']);
 *   ... content inside v2-page-body ...
 *   admin_layout_end();
 */

/**
 * @param 'open'|'close' $action
 */
function admin_page_body_manage(string $action): void
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

function admin_page_body_open(): void
{
    admin_page_body_manage('open');
}

function admin_page_body_close(): void
{
    admin_page_body_manage('close');
}

/**
 * @param array{subtitle?: string, actions?: string, back_href?: string} $opts
 */
function admin_page_header(string $title, array $opts = []): void
{
    $subtitle = trim($opts['subtitle'] ?? '');
    $actions = $opts['actions'] ?? '';
    $backHref = trim($opts['back_href'] ?? '');
    ?>
    <div class="v2-page-header">
        <header class="admin-page-header">
            <?php if ($backHref !== ''): ?>
            <a href="<?= sanitize($backHref) ?>" class="admin-page-header__back inline-flex items-center gap-xs text-on-surface-variant mb-sm active:scale-95">
                <span class="material-symbols-outlined text-lg">arrow_back</span>
                <span class="text-body-md">Back</span>
            </a>
            <?php endif; ?>
            <div class="admin-page-header__row">
                <div class="admin-page-header__main min-w-0">
                    <h1 class="admin-page-header__title"><?= sanitize($title) ?></h1>
                    <?php if ($subtitle !== ''): ?>
                        <p class="admin-page-header__subtitle"><?= sanitize($subtitle) ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($actions !== ''): ?>
                <div class="admin-page-header__actions shrink-0">
                    <?= $actions ?>
                </div>
                <?php endif; ?>
            </div>
        </header>
    </div>
    <?php
    admin_page_body_open();
}

/**
 * Flash banners inside v2-page-body.
 */
function admin_flash(string $message, string $type = 'success'): void
{
    if ($message === '') {
        return;
    }
    $class = $type === 'error' ? 'v2-banner v2-banner--error' : 'v2-banner v2-banner--success';
    echo '<div class="' . $class . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . sanitize($message) . '</div>';
}

/**
 * @param array{width?: string, main_class?: string} $opts
 */
function admin_layout_start(array $opts = []): void
{
    $width = $opts['width'] ?? 'default';
    $widthClass = match ($width) {
        'narrow' => ' max-w-lg',
        'medium' => ' max-w-2xl',
        'wide'   => ' max-w-3xl',
        'xl'     => ' max-w-5xl',
        'full'   => '',
        default  => '',
    };

    $mainClass = 'app-main md:ml-64 pb-24 md:pb-md v2-app-canvas min-w-0';
    if ($widthClass !== '') {
        $mainClass .= $widthClass;
    }
    if (!empty($opts['main_class'])) {
        $mainClass .= ' ' . preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $opts['main_class']);
    }

    echo '<div class="' . $mainClass . '">';
    echo '<div class="admin-inner px-edge-margin min-w-0">';
}

function admin_layout_end(): void
{
    admin_page_body_close();
    echo '</div></div>';
}
