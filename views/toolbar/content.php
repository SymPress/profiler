<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="sfToolbarClearer-<?php echo $escape($token) ?>" class="sf-toolbar-clearer"></div>
<div id="sfToolbarMainContent-<?php echo $escape($token) ?>" class="sf-toolbarreset notranslate clear-fix" data-no-turbolink data-turbo="false">
    <?php foreach ($items_html as $item_html): ?>
        <?php echo $item_html ?>
    <?php endforeach; ?>

    <button class="sf-toolbar-toggle-button" type="button" id="sfToolbarToggleButton-<?php echo $escape($token) ?>" accesskey="D" aria-expanded="true" aria-controls="sfToolbarMainContent-<?php echo $escape($token) ?>" aria-label="Toggle Profiler Toolbar">
        <i class="sf-toolbar-icon-opened" title="Close Toolbar"><?php echo $close_icon ?></i>
        <i class="sf-toolbar-icon-closed" title="Open Toolbar"><?php echo $symfony_icon ?></i>
    </button>
</div>
