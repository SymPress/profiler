<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header id="header">
    <h1><a href="<?php echo $escape($home_url) ?>"><span aria-hidden="true"><?php echo $profiler_icon ?></span> SymPress Profiler</a></h1>

    <div class="search">
        <form method="get" action="<?php echo $escape($search_url) ?>">
            <div class="form-row">
                <input name="q" id="search-id" type="search" placeholder="search profiles" aria-label="Search profiles" value="<?php echo $escape($search_query) ?>">
                <button type="submit" class="visually-hidden">Search</button>
            </div>
       </form>
    </div>
</header>
