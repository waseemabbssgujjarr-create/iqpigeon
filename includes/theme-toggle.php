<?php
/**
 * Theme toggle button — include in headers / nav.
 * @var string $variant icon|full
 */
$variant = $variant ?? 'icon';
?>
<?php if ($variant === 'full'): ?>
<button type="button" data-theme-toggle
        class="theme-toggle theme-toggle--full client-sidebar-link w-full border-none bg-transparent cursor-pointer text-left"
        aria-label="Toggle dark mode">
    <span class="material-symbols-outlined">dark_mode</span>
    <span>Toggle dark mode</span>
</button>
<?php elseif ($variant === 'client'): ?>
<button type="button" data-theme-toggle
        class="theme-toggle theme-toggle--client"
        aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="material-symbols-outlined">dark_mode</span>
</button>
<?php else: ?>
<button type="button" data-theme-toggle
        class="theme-toggle theme-toggle--icon marketing-header-btn marketing-header-btn--icon marketing-header-btn--outline"
        aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="material-symbols-outlined">dark_mode</span>
</button>
<?php endif; ?>
