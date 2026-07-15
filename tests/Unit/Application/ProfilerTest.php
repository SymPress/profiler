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

    private function collector(): DataCollectorInterface
    {
        return new class implements DataCollectorInterface {
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
                ];
            }

            public function createToolbarBlock(array $payload, ProfileRecord $profile): ?ToolbarBlock
            {
                return null;
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

            public function save(ProfileRecord $profile): void
            {
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
