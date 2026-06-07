<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="robots" content="noindex,nofollow" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <meta name="view-transition" content="same-origin" />
        <title><?= $escape($title) ?></title>
        <style>
<?= $profiler_css ?>
        </style>
    </head>
    <body>
        <script>
            if (null === localStorage.getItem('symfony/profiler/theme') || 'theme-auto' === localStorage.getItem('symfony/profiler/theme')) {
                document.body.classList.add((matchMedia('(prefers-color-scheme: dark)').matches ? 'theme-dark' : 'theme-light'));
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                    document.body.classList.remove('theme-light', 'theme-dark');
                    document.body.classList.add(e.matches ? 'theme-dark' : 'theme-light');
                });
            } else {
                document.body.classList.add(localStorage.getItem('symfony/profiler/theme'));
            }

            document.body.classList.add(localStorage.getItem('symfony/profiler/width') || 'width-normal');
            document.body.classList.add(
                (navigator.appVersion.indexOf('Win') !== -1) ? 'windows' : (navigator.appVersion.indexOf('Mac') !== -1) ? 'macos' : 'linux'
            );
        </script>
<?= $body_html ?>
    </body>
</html>
