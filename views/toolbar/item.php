<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="sf-toolbar-block sf-toolbar-block-<?php echo $escape($name) ?> sf-toolbar-status-<?php echo $escape($status) ?>" data-accessible-label="<?php echo $escape($accessible_label) ?>">
    <a href="<?php echo $escape($link) ?>" aria-controls="sf-toolbar-info-<?php echo $escape($name) ?>-<?php echo $escape($token) ?>" aria-haspopup="dialog" aria-keyshortcuts="ArrowDown">
        <div class="sf-toolbar-icon"><?php echo $icon_html ?></div>
    </a>
    <div class="sf-toolbar-info" id="sf-toolbar-info-<?php echo $escape($name) ?>-<?php echo $escape($token) ?>" role="dialog" aria-roledescription="details" aria-label="<?php echo $escape($accessible_label) ?>" tabindex="-1">
        <?php echo $info_html ?>
    </div>
</div>
