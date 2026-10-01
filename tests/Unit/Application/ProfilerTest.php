<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Profiler\Application\ProfileGate;
use SymPress\Profiler\Application\Profiler;
use SymPress\Profiler\Application\ProfilerRequestMatcher;
use SymPress\Profiler\Application\ProfilerUrlGenerator;
use SymPress\Profiler\Application\ProfileViewBuilder;
use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Collector\HttpClientCollector;
use SymPress\Profiler\Collector\ExceptionCollector;
use SymPress\Profiler\Collector\RequestCollector;
use SymPress\Profiler\Collector\LogCollector;
use SymPress\Profiler\Infrastructure\FilesystemProfileStorage;
use SymPress\Profiler\Recorder\ProfilerHttpClientRecorder;
use SymPress\Profiler\Recorder\ProfilerErrorRecorder;
use SymPress\Profiler\Support\ArraySanitizer;
use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Contract\ProfileStorageInterface;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ProfileSearchCriteria;
use SymPress\Profiler\Value\ToolbarBlock;
use SymPress\Profiler\View\ToolbarRenderer;
use SymPress\Profiler\View\WebProfilerAssets;

final class ProfilerTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER = [];
        \ProfilerTestFilters::reset();
    }

    public function testLifecycleStoresCollectorPayloadWithTemplateAndThrowableContext(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/checkout';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $collector = $this->collector();
        $storage = $this->storage();
        $urls = new ProfilerUrlGenerator();
        $viewBuilder = new ProfileViewBuilder(
            [$collector],
            new ToolbarRenderer(new WebProfilerAssets(), $urls),
        );
        $context = WpContext::new()->force(WpContext::FRONTOFFICE);
        $profiler = new Profiler(
            [$collector],
            $this->gate($context),
            $storage,
            $viewBuilder,
            $urls,
            $context,
        );

        $profiler->start();
        $profiler->captureTemplate('/theme/checkout.php');
        $profiler->recordThrowable(new \RuntimeException('Checkout failed'));
        $profiler->finish();

        self::assertInstanceOf(ProfileRecord::class, $storage->saved);
        self::assertSame('POST', $storage->saved->meta['method']);
        self::assertSame('/checkout', $storage->saved->meta['path']);
        self::assertSame('/theme/checkout.php', $storage->saved->meta['template']);
        self::assertSame([
            'template' => '/theme/checkout.php',
            'throwable_count' => 1,
        ], $storage->saved->collector('contract'));
    }

    public function testHtmlCallbackPreservesDollarSequencesAndCollectsOnlyOnce(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $collector = $this->collector();
        $storage = $this->storage();
        $urls = new ProfilerUrlGenerator();
        $context = WpContext::new()->force(WpContext::FRONTOFFICE);
        $profiler = new Profiler([$collector], $this->gate($context), $storage,
            new ProfileViewBuilder([$collector], new ToolbarRenderer(new WebProfilerAssets(), $urls)), $urls, $context);
        $profiler->start();
        ob_start();
        $profiler->beginHtmlBuffer();
        echo '<html><body>literal $1 and ${2}</body></html>';
        ob_end_flush();
        $content = ob_get_clean();
        self::assertIsString($content);
        self::assertStringContainsString('literal $1 and ${2}', $content);
        self::assertStringContainsString('Cost $1', $content);
        self::assertStringContainsString('${2}', $content);
        $profiler->finish();
        $profiler->finish();
        self::assertSame(1, $storage->saveCount);
    }

    public function testStoredAndRenderedDiagnosticsRedactAllCollectorCredentialsWithoutTruncatingPayloads(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $secret = 'review-canary-value';
        $url = 'https://user:' . $secret . '@example.test/?access_token=' . $secret . '&view=public';
        $context = WpContext::new()->force(WpContext::FRONTOFFICE);
        $gate = $this->gate($context);
        $http = new ProfilerHttpClientRecorder($gate);
        $http->enable();
        $http->track(false, [], $url);
        $http->record(new \WP_Error('canary', 'Connection failed: ' . $url), 'response', 'test', [], $url);
        $errors = new ProfilerErrorRecorder($gate);
        $errors->enable();
        $errors->record(E_WARNING, 'Warning: ' . $url);
        $errors->captureShutdown();
        $ordinary = str_repeat('ordinary message ', 100);
        $extension = $this->collector([
            'entries' => array_fill(0, 61, ['deep' => ['deeper' => ['detail' => ['message' => $ordinary, 'url' => $url]]]]),
            'credentials' => ['nested' => ['access' => $secret]],
            'has_auth_cookie' => true,
        ]);
        $collectors = [$extension, new HttpClientCollector($http), new ExceptionCollector($errors), new LogCollector($errors, new ArraySanitizer()),
            new RequestCollector(new ArraySanitizer(), $context)];
        $directory = sys_get_temp_dir() . '/profiler-redaction-' . bin2hex(random_bytes(6));
        $storage = new FilesystemProfileStorage($directory);
        $urls = new ProfilerUrlGenerator();
        $profiler = new Profiler($collectors, $gate, $storage,
            new ProfileViewBuilder($collectors, new ToolbarRenderer(new WebProfilerAssets(), $urls)), $urls, $context);

        try {
            $profiler->start();
            $profiler->recordThrowable(new \RuntimeException('Failure: ' . $url));
            $profiler->finish();
            $profile = $storage->latest(1)[0];
            $json = (string) file_get_contents($directory . '/' . $profile->token . '.json');
            self::assertStringNotContainsString($secret, $json);
            self::assertStringContainsString('view=public', $json);
            self::assertCount(61, $profile->collector('contract')['entries']);
            self::assertSame($ordinary, $profile->collector('contract')['entries'][60]['deep']['deeper']['detail']['message']);
            self::assertTrue($profile->collector('contract')['has_auth_cookie']);
            foreach ($collectors as $collector) {
                self::assertStringNotContainsString($secret, $collector->renderPanel($profile->collector($collector->getKey()), $profile)->html);
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** @param array<string, mixed> $extra */
    private function collector(array $extra = []): DataCollectorInterface
    {
        return new class ($extra) implements DataCollectorInterface {
            /** @param array<string, mixed> $extra */
            public function __construct(private readonly array $extra) {}

            public function getKey(): string
            {
                return 'contract';
            }

            public function getLabel(): string
            {
                return 'Contract';
            }

            public function getIcon(): string
            {
                return 'debug';
            }

            public function collect(ProfileContext $context): array
            {
                return [
                    'template' => $context->template(),
                    'throwable_count' => count($context->throwables()),
                ] + $this->extra;
            }

            public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock
            {
                return new ToolbarBlock('contract', 'Cost $1', '${2}', '', '/', 'cyan');
            }

            public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
            {
                return new CollectorPanel('contract', 'Contract', 'debug', '<p>Contract</p>');
            }
        };
    }

    private function storage(): object
    {
        return new class implements ProfileStorageInterface {
            public ?ProfileRecord $saved = null;
            public int $saveCount = 0;

            public function save(ProfileRecord $profile): void
            {
                $this->saveCount++;
                $this->saved = $profile;
            }

            public function load(string $token): ?ProfileRecord
            {
                return $this->saved?->token === $token ? $this->saved : null;
            }

            public function latest(int $limit = 20): array
            {
                return $this->saved instanceof ProfileRecord ? [$this->saved] : [];
            }

            public function search(ProfileSearchCriteria $criteria): array
            {
                return $this->latest($criteria->limit);
            }
        };
    }

    private function gate(WpContext $context): ProfileGate
    {
        $locations = $this->createStub(Locations::class);
        $config = $this->createStub(SiteConfig::class);
        $config->method('env')->willReturn(EnvConfig::LOCAL);
        $config->method('envIs')->willReturnCallback(
            static fn (string $environment): bool => $environment === EnvConfig::LOCAL,
        );
        $config->method('hosting')->willReturn(SiteConfig::HOSTING_OTHER);
        $config->method('hostingIs')->willReturn(false);
        $config->method('locations')->willReturn($locations);
        $config->method('get')->willReturn(null);
        $config->method('jsonSerialize')->willReturn([]);

        return new ProfileGate($config, $context, new ProfilerRequestMatcher());
    }
}
