<?php

declare(strict_types=1);

namespace SymPress\Profiler\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use SymPress\Profiler\Collector\CollectorPanel;
use SymPress\Profiler\Collector\LogCollector;
use SymPress\Profiler\Contract\DataCollectorInterface;

final class CollectorCatalogTest extends TestCase
{
    public function testCatalogMatchesCollectorPayloadsViewsAndHookWiring(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = json_decode(
            (string) file_get_contents($root . '/docs/collectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $configuration = Yaml::parseFile(
            $root . '/Resources/config/services.yaml',
            Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS,
        );
        self::assertSame(1, $catalog['version'] ?? null);
        self::assertIsArray($catalog['collectors'] ?? null);
        self::assertIsArray($configuration);
        self::assertIsArray($configuration['services'] ?? null);
        $services = $configuration['services'];
        $catalogClasses = [];
        $keys = [];

        foreach ($catalog['collectors'] as $entry) {
            self::assertIsArray($entry);
            $class = $entry['class'] ?? null;
            $key = $entry['key'] ?? null;
            $priority = $entry['priority'] ?? null;
            self::assertIsString($class);
            self::assertIsString($key);
            self::assertIsInt($priority);
            self::assertIsArray($entry['sources'] ?? null);
            self::assertNotSame([], $entry['sources']);
            self::assertTrue(is_a($class, DataCollectorInterface::class, true));

            $collector = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(DataCollectorInterface::class, $collector);
            self::assertSame($key, $collector->getKey());
            self::assertArrayNotHasKey($key, $keys, sprintf('Duplicate collector key "%s".', $key));
            self::assertSame($this->payloadKeys($class), $entry['payload_keys'] ?? null);
            self::assertSame([
                'toolbar' => $this->toolbarMode($class),
                'panel' => $this->hasPanel($class),
            ], $entry['views'] ?? null, sprintf('View metadata differs for "%s".', $key));
            $this->assertSensitiveFieldsExist($entry);

            $service = $services[$class] ?? null;
            self::assertIsArray($service);
            $tags = $this->serviceTags($service, 'profiler.collector');
            self::assertCount(1, $tags);
            self::assertSame($priority, $tags[0]['priority'] ?? 0);
            $catalogClasses[] = $class;
            $keys[$key] = true;
        }

        $discoveredClasses = [];

        foreach (glob($root . '/src/Collector/*Collector.php') ?: [] as $file) {
            $class = 'SymPress\\Profiler\\Collector\\' . basename($file, '.php');

            if (is_a($class, DataCollectorInterface::class, true)) {
                $discoveredClasses[] = $class;
            }
        }

        sort($catalogClasses);
        sort($discoveredClasses);
        self::assertSame($discoveredClasses, $catalogClasses);
        $this->assertHookServices($catalog, $services, $keys);
        $this->assertExternalFilters($catalog, $keys);
    }

    /** @return list<string> */
    private function payloadKeys(string $class): array
    {
        $source = $this->methodSource(new \ReflectionMethod($class, 'collect'));
        $returnOffset = strpos($source, "\n        return [");
        self::assertNotFalse($returnOffset);
        preg_match_all("/^ {12}'([^']+)'\\s*=>/m", substr($source, $returnOffset), $matches);

        return array_values($matches[1]);
    }

    private function toolbarMode(string $class): string
    {
        $method = new \ReflectionMethod($class, 'createToolbarBlock');
        $type = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);

        if (!$type->allowsNull()) {
            return 'always';
        }

        return str_contains($this->methodSource($method), 'new ToolbarBlock(')
            ? 'conditional'
            : 'none';
    }

    private function hasPanel(string $class): bool
    {
        $type = (new \ReflectionMethod($class, 'renderPanel'))->getReturnType();

        return $type instanceof \ReflectionNamedType
            && $type->getName() === CollectorPanel::class
            && !$type->allowsNull();
    }

    /** @param array<string, mixed> $entry */
    private function assertSensitiveFieldsExist(array $entry): void
    {
        $payloadKeys = $entry['payload_keys'] ?? null;
        $sensitiveFields = $entry['sensitive_fields'] ?? null;
        self::assertIsArray($payloadKeys);
        self::assertIsArray($sensitiveFields);

        foreach ($sensitiveFields as $field) {
            self::assertIsString($field);
            self::assertContains(explode('.', $field, 2)[0], $payloadKeys);
        }
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $services
     * @param array<string, true> $collectorKeys
     */
    private function assertHookServices(array $catalog, array $services, array $collectorKeys): void
    {
        $hookServices = $catalog['hook_services'] ?? null;
        self::assertIsArray($hookServices);
        $catalogClasses = [];

        foreach ($hookServices as $entry) {
            self::assertIsArray($entry);
            $class = $entry['class'] ?? null;
            self::assertIsString($class);
            self::assertContains($entry['role'] ?? null, ['route', 'lifecycle', 'recorder']);
            self::assertIsArray($entry['collector_keys'] ?? null);
            self::assertIsArray($entry['hooks'] ?? null);
            $service = $services[$class] ?? null;
            self::assertIsArray($service);
            $tags = array_map(
                static function (array $tag): array {
                    unset($tag['name']);

                    return $tag;
                },
                $this->serviceTags($service, $entry['tag'] ?? 'kernel.hook'),
            );
            self::assertSame($entry['hooks'], $tags);

            foreach ($entry['collector_keys'] as $collectorKey) {
                self::assertIsString($collectorKey);

                if ($collectorKey !== '*') {
                    self::assertArrayHasKey($collectorKey, $collectorKeys);
                }
            }

            $catalogClasses[] = $class;
        }

        $configuredClasses = [];

        foreach ($services as $class => $service) {
            if (is_string($class) && is_array($service) && ($this->serviceTags($service, 'kernel.hook') !== [] || $this->serviceTags($service, 'profiler.hook') !== [])) {
                $configuredClasses[] = $class;
            }
        }

        sort($catalogClasses);
        sort($configuredClasses);
        self::assertSame($configuredClasses, $catalogClasses);
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, true> $collectorKeys
     */
    private function assertExternalFilters(array $catalog, array $collectorKeys): void
    {
        $filters = $catalog['external_filters'] ?? null;
        self::assertIsArray($filters);
        $constant = (new \ReflectionClass(LogCollector::class))->getReflectionConstant('FILTER_LOG_ENTRIES');
        self::assertInstanceOf(\ReflectionClassConstant::class, $constant);

        foreach ($filters as $filter) {
            self::assertIsArray($filter);
            self::assertIsString($filter['hook'] ?? null);
            self::assertIsString($filter['producer'] ?? null);
            self::assertIsString($filter['collector_key'] ?? null);
            self::assertArrayHasKey($filter['collector_key'], $collectorKeys);
        }

        self::assertContains([
            'hook' => $constant->getValue(),
            'producer' => 'sympress/monolog-bundle',
            'collector_key' => 'logs',
        ], $filters);
    }

    /**
     * @param array<string, mixed> $service
     * @return list<array<string, mixed>>
     */
    private function serviceTags(array $service, string $name): array
    {
        $tags = $service['tags'] ?? null;

        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter(
            $tags,
            static fn (mixed $tag): bool => is_array($tag) && ($tag['name'] ?? null) === $name,
        ));
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
