<?php

declare(strict_types=1);

namespace SymPress\Profiler\View;

final class PhpViewRenderer
{
    public function __construct(
        private readonly string $basePath = '',
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $view, array $context = []): string
    {
        $path = $this->resolve($view);

        if (!is_file($path)) {
            throw new \RuntimeException('Requested view was not found.');
        }

        $render = static function (string $viewPath, array $viewContext): string {
            // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- isolated PHP view scope.
            extract($viewContext, EXTR_SKIP);

            ob_start();
            require $viewPath;

            return (string) ob_get_clean();
        };

        return $render($path, $context);
    }

    private function resolve(string $view): string
    {
        $basePath = $this->basePath !== ''
            ? rtrim($this->basePath, '/')
            : dirname(__DIR__, 2) . '/views';

        return $basePath . '/' . ltrim($view, '/') . '.php';
    }
}
