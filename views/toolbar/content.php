<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="sfToolbarClearer-<?= $escape($token) ?>" class="sf-toolbar-clearer"></div>
<div id="sfToolbarMainContent-<?= $escape($token) ?>" class="sf-toolbarreset notranslate clear-fix" data-no-turbolink data-turbo="false">
    <?php foreach ($items_html as $item_html): ?>
        <?= $item_html ?>
    <?php endforeach; ?>

    <button class="sf-toolbar-toggle-button" type="button" id="sfToolbarToggleButton-<?= $escape($token) ?>" accesskey="D" aria-expanded="true" aria-controls="sfToolbarMainContent-<?= $escape($token) ?>" aria-label="Toggle Profiler Toolbar">
        <i class="sf-toolbar-icon-opened" title="Close Toolbar"><?= $close_icon ?></i>
        <i class="sf-toolbar-icon-closed" title="Open Toolbar"><?= $symfony_icon ?></i>
    </button>
</div>
