<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header id="header">
    <h1><a href="<?= $escape($home_url) ?>"><span aria-hidden="true"><?= $profiler_icon ?></span> Symfony Profiler</a></h1>

    <div class="search">
        <form method="get" action="https://symfony.com/search" target="_blank">
            <div class="form-row">
                <input name="q" id="search-id" type="search" placeholder="search on symfony.com" aria-label="Search on symfony.com" value="">
                <button type="submit" class="visually-hidden">Search</button>
            </div>
       </form>
    </div>
</header>
