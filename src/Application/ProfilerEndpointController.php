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
        if (!$this->matcher->isInternalProfilerRequest()) {
            return;
        }

        if (!$this->gate->canAccessProfiler()) {
            $this->respond('Access denied.', 403, 'text/plain; charset=UTF-8');
        }

        if ($this->matcher->isToolbarStylesheetRequest()) {
            $this->respond($this->assets->toolbarCss(), 200, 'text/css; charset=UTF-8');
        }

        if ($this->matcher->isFontRequest()) {
            $this->renderFont();
        }

        if ($this->matcher->isToolbarRequest()) {
            $this->renderToolbar();
        }

        if ($this->matcher->isProfilerRequest()) {
            $this->renderProfiler();
        }
    }

    private function renderFont(): void
    {
        $fontPath = $this->assets->fontPath($this->matcher->fontName() ?? '');

        if ($fontPath === null || !is_file($fontPath)) {
            $this->respond('Font not found.', 404, 'text/plain; charset=UTF-8');
        }

        $contents = file_get_contents($fontPath);

        if (!is_string($contents)) {
            $this->respond('Font not found.', 404, 'text/plain; charset=UTF-8');
        }

        $this->respond($contents, 200, 'font/woff2');
    }

    private function renderToolbar(): void
    {
        $token = $this->matcher->toolbarToken();
        $profile = is_string($token) ? $this->storage->load($token) : null;

        if (!$profile instanceof ProfileRecord) {
            $this->respond('', 404, 'text/html; charset=UTF-8');
        }

        $this->respond($this->viewBuilder->renderToolbarContent($profile), 200, 'text/html; charset=UTF-8');
    }

    private function renderProfiler(): void
    {
        $token = $this->matcher->token();
        $criteria = $this->matcher->searchCriteria();

        if ($token === 'latest') {
            $latest = $this->storage->latest(1)[0] ?? null;

            if ($latest instanceof ProfileRecord) {
                $this->redirect($this->urls->profile($latest->token, $this->matcher->panel()));
            }

            $token = null;
        }

        if ($token !== null) {
            $profile = $this->storage->load($token);

            if ($profile instanceof ProfileRecord) {
                $html = $this->pageRenderer->renderProfile(
                    $profile,
                    $this->viewBuilder->toolbarBlocks($profile),
                    $this->viewBuilder->panels($profile),
                    $this->matcher->panel(),
                );

                $this->respond($html, 200, 'text/html; charset=UTF-8');
            }

            $profiles = $this->storage->search($criteria);
            $html = $this->pageRenderer->renderResults($profiles, $criteria, $token);

            $this->respond($html, 404, 'text/html; charset=UTF-8');
        }

        $profiles = $this->storage->search($criteria);
        $html = $this->pageRenderer->renderResults($profiles, $criteria);

        $this->respond($html, 200, 'text/html; charset=UTF-8');
    }

    private function redirect(string $url): void
    {
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url, 302);
            exit;
        }

        header('Location: ' . $url, true, 302);
        exit;
    }

    private function respond(string $body, int $status, string $contentType): never
    {
        if (function_exists('status_header')) {
            status_header($status);
        } else {
            http_response_code($status);
        }

        header('Cache-Control: private, no-cache, max-age=0, must-revalidate', true);
        header('X-Accel-Expires: 0', true);
        header('Content-Type: ' . $contentType, true);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw profiler response body.
        echo $body;
        exit;
    }
}
