<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="container">
    <?php echo $header_html ?>

    <div id="summary">
        <?php echo $summary_html ?>
    </div>

    <div id="content">
        <main id="main">
            <div id="sidebar">
                <div id="sidebar-contents">
                    <div id="sidebar-shortcuts">
                        <div class="shortcuts">
                            <?php foreach ($shortcuts as $shortcut): ?>
                                <a class="btn btn-link" href="<?php echo $escape($shortcut['url']) ?>">
                                    <?php if (($shortcut['icon'] ?? '') !== ''): ?>
                                        <?php echo $shortcut['icon'] ?>
                                    <?php endif; ?>
                                    <?php echo $escape($shortcut['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($menu_items !== []): ?>
                        <nav aria-label="Profiler menu">
                            <ul id="menu-profiler">
                                <?php foreach ($menu_items as $item): ?>
                                    <li class="<?php echo $escape($item['id']) ?><?php echo $item['selected'] ? ' selected' : '' ?><?php echo $item['enabled'] ? '' : ' disabled' ?>">
                                        <?php if ($item['enabled']): ?>
                                            <a href="<?php echo $escape($item['link']) ?>"<?php echo $item['selected'] ? ' aria-current="page"' : '' ?>>
                                                <span class="label">
                                                    <span class="icon"><?php echo $item['icon'] ?></span>
                                                    <strong><?php echo $escape($item['label']) ?></strong>
                                                    <?php if ($item['metric'] !== ''): ?>
                                                        <span class="count">
                                                            <span><?php echo $escape($item['metric']) ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <span class="label disabled" aria-disabled="true">
                                                <span class="icon"><?php echo $item['icon'] ?></span>
                                                <strong><?php echo $escape($item['label']) ?></strong>
                                                <?php if ($item['metric'] !== ''): ?>
                                                    <span class="count">
                                                        <span><?php echo $escape($item['metric']) ?></span>
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

                <?php echo $settings_html ?>
            </div>

            <div id="collector-wrapper">
                <div id="collector-content">
                    <script>
<?php echo $base_js ?>
                    </script>
                    <?php echo $content_html ?>
                </div>
            </div>
        </main>
    </div>
</div>
