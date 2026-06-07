<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$count = count($profiles);
$queryArgs = $criteria->toQueryArgs();
?>
<?php if (is_string($missing_token) && $missing_token !== ''): ?>
    <div class="status status-warning">
        <h2>Profile not found</h2>
        <p>The requested profile token <code><?= $escape($missing_token) ?></code> could not be found. Showing the latest available profiles instead.</p>
    </div>
<?php endif; ?>

<section style="margin: 1.5rem 0;">
    <form method="get" action="<?= $escape($search_url) ?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <label>
            <span class="visually-hidden">Free text</span>
            <input type="search" name="q" placeholder="Search text" value="<?= $escape($criteria->text) ?>">
        </label>
        <label>
            <span class="visually-hidden">Token</span>
            <input type="text" name="token" placeholder="Token" value="<?= $escape($criteria->token) ?>">
        </label>
        <label>
            <span class="visually-hidden">Method</span>
            <input type="text" name="method" placeholder="Method" value="<?= $escape($criteria->method) ?>">
        </label>
        <label>
            <span class="visually-hidden">URL</span>
            <input type="text" name="url" placeholder="URL contains" value="<?= $escape($criteria->url) ?>">
        </label>
        <label>
            <span class="visually-hidden">IP</span>
            <input type="text" name="ip" placeholder="IP address" value="<?= $escape($criteria->ip) ?>">
        </label>
        <label>
            <span class="visually-hidden">Status</span>
            <input type="number" name="status" placeholder="Status code" value="<?= $escape($criteria->statusCode ?? '') ?>">
        </label>
        <label>
            <span class="visually-hidden">Context</span>
            <select name="context">
                <option value="">Any context</option>
                <?php foreach ($context_options as $contextOption): ?>
                    <option value="<?= $escape($contextOption) ?>"<?= $criteria->context === $contextOption ? ' selected' : '' ?>><?= $escape($contextOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span class="visually-hidden">From</span>
            <input type="text" name="start" placeholder="From date/time" value="<?= $escape($criteria->start) ?>">
        </label>
        <label>
            <span class="visually-hidden">Until</span>
            <input type="text" name="end" placeholder="Until date/time" value="<?= $escape($criteria->end) ?>">
        </label>
        <label>
            <span class="visually-hidden">Limit</span>
            <input type="number" min="1" max="200" name="limit" placeholder="Limit" value="<?= $escape($criteria->limit) ?>">
        </label>
        <div style="display:flex;gap:.75rem;align-items:center;">
            <button type="submit" class="btn"><?= $search_icon ?> Search</button>
            <a class="btn btn-link" href="<?= $escape($last_url) ?>">Last 10</a>
            <a class="btn btn-link" href="<?= $escape($latest_url) ?>">Latest</a>
        </div>
    </form>
</section>

<?php if ($queryArgs !== []): ?>
    <p class="help">Active filters:
        <?php foreach ($queryArgs as $key => $value): ?>
            <code><?= $escape((string) $key) ?>=<?= $escape((string) $value) ?></code>
        <?php endforeach; ?>
    </p>
<?php endif; ?>

<h2><?= $escape($count) ?> results found</h2>

<?php if ($profiles !== []): ?>
    <table id="search-results">
        <thead>
            <tr>
                <th scope="col" class="text-center">Status</th>
                <th scope="col">Context</th>
                <th scope="col">Method</th>
                <th scope="col">URL</th>
                <th scope="col">IP</th>
                <th scope="col">Time</th>
                <th scope="col">Token</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($profiles as $profile): ?>
                <?php
                $meta = $profile->meta;
                $statusCode = is_numeric($meta['status_code'] ?? null) ? (int) $meta['status_code'] : 200;
                $statusClass = $statusCode > 399 ? 'status-error' : ($statusCode > 299 ? 'status-warning' : 'status-success');
                $url = (string) ($meta['url'] ?? $meta['uri'] ?? '');
                ?>
                <tr>
                    <td class="text-center"><span class="label <?= $escape($statusClass) ?>"><?= $escape($statusCode) ?></span></td>
                    <td><span class="nowrap"><?= $escape((string) ($meta['context'] ?? 'core')) ?></span></td>
                    <td><span class="nowrap"><?= $escape((string) ($meta['method'] ?? 'GET')) ?></span></td>
                    <td class="break-long-words"><?= $escape($url) ?></td>
                    <td><span class="nowrap"><?= $escape((string) ($meta['ip'] ?? '')) ?></span></td>
                    <td class="text-small">
                        <time data-convert-to-user-timezone data-render-as-date datetime="<?= $escape($profile->createdAt) ?>"><?= $escape(substr($profile->createdAt, 0, 10)) ?></time>
                        <time class="newline" data-convert-to-user-timezone data-render-as-time datetime="<?= $escape($profile->createdAt) ?>"><?= $escape(substr($profile->createdAt, 11, 8)) ?></time>
                    </td>
                    <td class="nowrap"><a href="<?= $escape((string) ($meta['profiler_url'] ?? '#')) ?>"><?= $escape($profile->token) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="empty empty-panel">
        <p>No profiles have been recorded yet.</p>
    </div>
<?php endif; ?>
