<?php
/**
 * Reusable WhatsApp live demo CTA block.
 * @var string $variant hero|hippo-hero|section|hippo|compact
 */
$variant = $variant ?? 'section';
$waHref = whatsapp_demo_href();
if (!$waHref) {
    return;
}
$isHero = $variant === 'hero';
$isHippoHero = $variant === 'hippo-hero';
$isHippo = $variant === 'hippo';
$isCompact = $variant === 'compact';

if ($isHippoHero) {
    $class = 'v2-btn-pill hip-btn hip-btn--wa hover-lift';
} elseif ($isHippo) {
    $class = 'v2-btn-pill hip-btn hip-btn--wa';
} elseif ($isHero) {
    $class = 'v2-btn-pill hip-btn hip-btn--wa hover-lift active:scale-95 shadow-md';
} elseif ($isCompact) {
    $class = 'marketing-header-btn marketing-header-btn--whatsapp';
} else {
    $class = 'inline-flex items-center gap-md h-14 px-xl rounded-2xl font-title text-title-md hover-lift active:scale-95 shadow-md transition-all duration-300 marketing-header-btn marketing-header-btn--whatsapp';
}
?>
<a href="<?= sanitize($waHref) ?>"
   target="_blank"
   rel="noopener noreferrer"
   class="<?= $class ?>">
    <span class="material-symbols-outlined">chat</span>
    <?= ($isCompact || $isHippo || $isHippoHero) ? 'WhatsApp Demo' : 'Try Live on WhatsApp' ?>
</a>
