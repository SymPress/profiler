<?php

declare(strict_types=1);

use SymPress\Profiler\Stopwatch\ProfilerStopwatch;
use Symfony\Bridge\Twig\Extension\ProfilerExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Stopwatch\Stopwatch;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Profiler\Profile;

// Use an actual consumer autoloader supplying Twig Bridge and Twig; no test doubles.
$autoload = $argv[1] ?? '';
if (!is_file($autoload)) {
    throw new RuntimeException('Usage: php tests/Integration/stopwatch-twig.php /absolute/consumer/vendor/autoload.php');
}
require $autoload;
if (isset($argv[2]) && is_file($argv[2])) {
    require $argv[2];
}
if (!class_exists(Stopwatch::class)) {
    throw new RuntimeException('Supply a real Symfony Stopwatch test autoloader as the second argument when the consumer omits that optional component.');
}

foreach ([false, true] as $nativeAvailable) {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.project_dir', dirname(__DIR__, 2));
    $container->setParameter('kernel.environment', 'test');
    if ($nativeAvailable) {
        $container->register('debug.stopwatch', Stopwatch::class)->setPublic(true);
    }
    (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/Resources/config')))->load('services.yaml');
    // Isolate the real stopwatch wiring from unrelated WordPress hook/application services.
    foreach (array_keys($container->getDefinitions()) as $id) {
        if (!in_array($id, ['service_container', 'debug.stopwatch', ProfilerStopwatch::class], true)) {
            $container->removeDefinition($id);
        }
    }
    foreach (array_keys($container->getAliases()) as $id) {
        if ($id !== 'debug.stopwatch') {
            $container->removeAlias($id);
        }
    }
    $container->getDefinition(ProfilerStopwatch::class)->setPublic(true);
    $container->register('twig.profile', Profile::class)->setPublic(true);
    // Same optional native service reference used by Symfony TwigBundle's twig.php.
    $container->register('twig.extension.profiler', ProfilerExtension::class)
        ->setArguments([new Reference('twig.profile'), new Reference('debug.stopwatch', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
        ->setPublic(true);
    $container->compile();
    $extension = $container->get('twig.extension.profiler');
    if (!$extension instanceof ProfilerExtension) {
        throw new RuntimeException('Symfony Twig profiler extension did not instantiate.');
    }
    $twig = new Environment(new ArrayLoader(['page' => 'Hello {{ name }}{% include "fragment" %}', 'fragment' => '!']), ['debug' => true]);
    $twig->addExtension($extension);
    if ($twig->render('page', ['name' => 'SymPress']) !== 'Hello SymPress!') {
        throw new RuntimeException('Real Twig rendering failed.');
    }
    $profile = $container->get('twig.profile');
    if (!$profile instanceof Profile || count($profile->getProfiles()) === 0) {
        throw new RuntimeException('Twig profile did not retain template timing.');
    }
    if ($nativeAvailable) {
        $native = $container->get('debug.stopwatch');
        if (!$native instanceof Stopwatch || count($native->getRootSectionEvents()) !== 2) {
            throw new RuntimeException('Native stopwatch did not retain both Twig template events.');
        }
    }
    $custom = $container->get(ProfilerStopwatch::class);
    if (!$custom instanceof ProfilerStopwatch) {
        throw new RuntimeException('Custom profiler stopwatch disappeared.');
    }
    $custom->start('custom-event', 'application')->stop();
    if (($custom->events()[0]['name'] ?? '') !== 'custom-event') {
        throw new RuntimeException('Custom profiler timing payload changed.');
    }
    echo sprintf("PASS compiled Symfony container + real Twig render; native stopwatch %s; Twig/custom timing retained\n", $nativeAvailable ? 'present' : 'absent');
}
