<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="container">
    <?= $header_html ?>

    <div id="summary">
        <?= $summary_html ?>
    </div>

    <div id="content">
        <main id="main">
            <div id="sidebar">
                <div id="sidebar-contents">
                    <div id="sidebar-shortcuts">
                        <div class="shortcuts">
                            <?php foreach ($shortcuts as $shortcut): ?>
                                <a class="btn btn-link" href="<?= $escape($shortcut['url']) ?>">
                                    <?php if (($shortcut['icon'] ?? '') !== ''): ?>
                                        <?= $shortcut['icon'] ?>
                                    <?php endif; ?>
                                    <?= $escape($shortcut['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($menu_items !== []): ?>
                        <nav aria-label="Profiler menu">
                            <ul id="menu-profiler">
                                <?php foreach ($menu_items as $item): ?>
                                    <li class="<?= $escape($item['id']) ?><?= $item['selected'] ? ' selected' : '' ?><?= $item['enabled'] ? '' : ' disabled' ?>">
                                        <?php if ($item['enabled']): ?>
                                            <a href="<?= $escape($item['link']) ?>"<?= $item['selected'] ? ' aria-current="page"' : '' ?>>
                                                <span class="label">
                                                    <span class="icon"><?= $item['icon'] ?></span>
                                                    <strong><?= $escape($item['label']) ?></strong>
                                                    <?php if ($item['metric'] !== ''): ?>
                                                        <span class="count">
                                                            <span><?= $escape($item['metric']) ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <span class="label disabled" aria-disabled="true">
                                                <span class="icon"><?= $item['icon'] ?></span>
                                                <strong><?= $escape($item['label']) ?></strong>
                                                <?php if ($item['metric'] !== ''): ?>
                                                    <span class="count">
                                                        <span><?= $escape($item['metric']) ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

                <?= $settings_html ?>
            </div>

            <div id="collector-wrapper">
                <div id="collector-content">
                    <script>
<?= $base_js ?>
                    </script>
                    <?= $content_html ?>
                </div>
            </div>
        </main>
    </div>
</div>
