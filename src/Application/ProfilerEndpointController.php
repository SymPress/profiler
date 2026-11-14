<?php

declare(strict_types=1);

namespace SymPress\Profiler\Application;

use SymPress\Profiler\Contract\ProfileStorageInterface;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\View\ProfilerPageRenderer;
use SymPress\Profiler\View\WebProfilerAssets;

final class ProfilerEndpointController
{
    public function __construct(
        private readonly ProfileGate $gate,
        private readonly ProfilerRequestMatcher $matcher,
        private readonly ProfilerUrlGenerator $urls,
        private readonly ProfileStorageInterface $storage,
        private readonly ProfileViewBuilder $viewBuilder,
        private readonly ProfilerPageRenderer $pageRenderer,
        private readonly WebProfilerAssets $assets,
    ) {
    }

    public function handle(): void
    {
        $response = $this->response();

        if ($response === null) {
            return;
        }

        if (isset($response['location'])) {
            $this->redirect($response['location']);
        }

        $this->respond($response['body'], $response['status'], $response['type'], $response['static'] ?? false);
    }

    /**
     * The response for the current request, or null when it is not a profiler route. Kept free of
     * output and exit so every route can be tested.
     *
     * @return array{status: int, type: string, body: string, location?: string, static?: bool}|null
     */
    public function response(): ?array
    {
        if (!$this->matcher->isInternalProfilerRequest()) {
            return null;
        }

        if (!$this->gate->canAccessProfiler()) {
            return $this->text('Access denied.', 403);
        }

        if ($this->matcher->isToolbarStylesheetRequest()) {
            return ['status' => 200, 'type' => 'text/css; charset=UTF-8', 'body' => $this->assets->toolbarCss(), 'static' => true];
        }

        if ($this->matcher->isFontRequest()) {
            return $this->fontResponse();
        }

        if ($this->matcher->isToolbarRequest()) {
            return $this->toolbarResponse();
        }

        return $this->matcher->isProfilerRequest() ? $this->profilerResponse() : null;
    }

    /** @return array{status: int, type: string, body: string, static?: bool} */
    private function fontResponse(): array
    {
        $fontPath = $this->assets->fontPath($this->matcher->fontName() ?? '');
        $contents = $fontPath !== null && is_file($fontPath) ? file_get_contents($fontPath) : false;

        if (!is_string($contents)) {
            return $this->text('Font not found.', 404);
        }

        return ['status' => 200, 'type' => 'font/woff2', 'body' => $contents, 'static' => true];
    }

    /** @return array{status: int, type: string, body: string} */
    private function toolbarResponse(): array
    {
        $token = $this->matcher->toolbarToken();
        $profile = is_string($token) ? $this->storage->load($token) : null;

        if (!$profile instanceof ProfileRecord) {
            return $this->html('', 404);
        }

        return $this->html($this->viewBuilder->renderToolbarContent($profile), 200);
    }

    /** @return array{status: int, type: string, body: string, location?: string} */
    private function profilerResponse(): array
    {
        $token = $this->matcher->token();
        $criteria = $this->matcher->searchCriteria();

        if ($token === 'phpinfo') {
            ob_start();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_phpinfo -- Symfony's "View full PHP configuration" page; profiler access only.
            phpinfo();

            return $this->html((string) ob_get_clean(), 200);
        }

        if ($token === 'latest') {
            $latest = $this->storage->latest(1)[0] ?? null;

            if ($latest instanceof ProfileRecord) {
                return ['status' => 302, 'type' => '', 'body' => '', 'location' => $this->urls->profile($latest->token, $this->matcher->panel())];
            }

            $token = null;
        }

        if ($token === null) {
            return $this->html($this->pageRenderer->renderResults($this->storage->search($criteria), $criteria), 200);
        }

        $profile = $this->storage->load($token);

        if (!$profile instanceof ProfileRecord) {
            return $this->html($this->pageRenderer->renderResults($this->storage->search($criteria), $criteria, $token), 404);
        }

        return $this->html($this->pageRenderer->renderProfile(
            $profile,
            $this->viewBuilder->toolbarBlocks($profile),
            $this->viewBuilder->panels($profile),
            $this->matcher->panel(),
        ), 200);
    }

    /** @return array{status: int, type: string, body: string} */
    private function text(string $body, int $status): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }

    /** @return array{status: int, type: string, body: string} */
    private function html(string $body, int $status): array
    {
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    private function redirect(string $url): never
    {
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url, 302);
            exit;
        }

        header('Location: ' . $url, true, 302);
        exit;
    }

    private function respond(string $body, int $status, string $contentType, bool $static = false): never
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (function_exists('status_header')) {
            status_header($status);
        } else {
            http_response_code($status);
        }

        // The toolbar stylesheet and the font are the same for every request: let the admin's browser keep
        // them instead of booting WordPress for them on each page view. Never in shared caches.
        header($static ? 'Cache-Control: private, max-age=3600' : 'Cache-Control: private, no-cache, max-age=0, must-revalidate', true);
        header('X-Accel-Expires: 0', true);
        header('Content-Type: ' . $contentType, true);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw profiler response body.
        echo $body;
        exit;
    }
}
