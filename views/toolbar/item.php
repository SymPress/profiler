<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="sf-toolbar-block sf-toolbar-block-<?= $escape($name) ?> sf-toolbar-status-<?= $escape($status) ?>" data-accessible-label="<?= $escape($accessible_label) ?>">
    <a href="<?= $escape($link) ?>" aria-controls="sf-toolbar-info-<?= $escape($name) ?>-<?= $escape($token) ?>" aria-haspopup="dialog" aria-keyshortcuts="ArrowDown">
        <div class="sf-toolbar-icon"><?= $icon_html ?></div>
    </a>
    <div class="sf-toolbar-info" id="sf-toolbar-info-<?= $escape($name) ?>-<?= $escape($token) ?>" role="dialog" aria-roledescription="details" aria-label="<?= $escape($accessible_label) ?>" tabindex="-1">
        <?= $info_html ?>
    </div>
</div>
