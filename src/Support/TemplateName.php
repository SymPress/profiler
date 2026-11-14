<?php

declare(strict_types=1);

namespace SymPress\Profiler\Support;

final class TemplateName
{
    /**
     * The name a developer knows the rendered template by. Block themes render every page through
     * wp-includes/template-canvas.php; what was chosen is the block template WordPress resolved,
     * for example "sympress//page".
     */
    public static function label(string $path): string
    {
        $blockTemplate = $GLOBALS['_wp_current_template_id'] ?? null;

        if (is_string($blockTemplate) && $blockTemplate !== '' && basename($path) === 'template-canvas.php') {
            return $blockTemplate;
        }

        return $path !== '' ? basename($path) : '';
    }
}
