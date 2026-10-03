<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$meta = $profile->meta;
$request = $profile->collector('request');
$method = strtoupper((string) ($meta['method'] ?? 'GET'));
$url = (string) ($meta['url'] ?? $meta['uri'] ?? '');
$profileTitle = function_exists('mb_strlen') && mb_strlen($url) >= 160
    ? mb_substr($url, 0, 160) . '…'
    : $url;
$statusCodeValue = (int) $status_code;
$referer = (string) ($request['referer'] ?? '');
$ip = (string) ($request['ip'] ?? '');
$redirect = is_array($request['redirect'] ?? null) ? $request['redirect'] : null;
?>
<?php if ($redirect !== null): ?>
    <?php
    $redirectStatus = is_numeric($redirect['status_code'] ?? null) ? (int) $redirect['status_code'] : 302;
    $redirectMethod = strtoupper((string) ($redirect['method'] ?? $method));
    $redirectRoute = (string) ($redirect['route'] ?? ($redirect['url'] ?? 'redirect'));
    $redirectToken = (string) ($redirect['token'] ?? '');
    ?>
    <div class="status status-compact status-warning">
        <span class="icon icon-redirect"><?php echo $redirect_icon ?></span>
        <span class="status-response-status-code"><?php echo $escape($redirectStatus) ?></span> redirect from
        <span class="status-request-method"><?php echo $escape($redirectMethod) ?></span>
        <?php echo $escape($redirectRoute) ?>
        <?php if ($redirectToken !== ''): ?>
            (<?php echo $escape($redirectToken) ?>)
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="status <?php echo $escape($status_class) ?>">
    <?php if ($statusCodeValue > 399): ?>
        <p class="status-error-details">
            <span class="icon"><?php echo $alert_icon ?></span>
            <span class="status-response-status-code">Error <?php echo $escape($statusCodeValue) ?></span>
            <span class="status-response-status-text"><?php echo $escape($status_text) ?></span>
        </p>
    <?php endif; ?>

    <h2>
        <span class="status-request-method"><?php echo $escape($method) ?></span>
        <?php if (in_array($method, ['GET', 'HEAD'], true) && $url !== ''): ?>
            <a href="<?php echo $escape($url) ?>"><?php echo $escape($profileTitle) ?></a>
        <?php else: ?>
            <?php echo $escape($profileTitle) ?>
        <?php endif; ?>
    </h2>

    <dl class="metadata">
        <?php if ($statusCodeValue < 400): ?>
            <dt>Response</dt>
            <dd>
                <span class="status-response-status-code"><?php echo $escape($statusCodeValue) ?></span>
                <span class="status-response-status-text"><?php echo $escape($status_text) ?></span>
            </dd>
        <?php endif; ?>

        <?php if ($referer !== ''): ?>
            <dt>Referer</dt>
            <dd>
                <span class="icon icon-referer"><?php echo $referrer_icon ?></span>
                <a href="<?php echo $escape($referer) ?>" class="referer">Browse referrer URL</a>
            </dd>
        <?php endif; ?>

        <?php if ($ip !== ''): ?>
            <dt>IP</dt>
            <dd><?php echo $escape($ip) ?></dd>
        <?php endif; ?>

        <dt>Profiled on</dt>
        <dd><time data-convert-to-user-timezone data-render-as-datetime datetime="<?php echo $escape($profile->createdAt) ?>"><?php echo $escape($profile->createdAt) ?></time></dd>

        <dt>Token</dt>
        <dd><?php echo $escape($profile->token) ?></dd>
    </dl>
</div>
