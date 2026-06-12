<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class CronCollector extends AbstractCollector implements DataCollectorInterface
{
    public function getKey(): string
    {
        return 'cron';
    }

    public function getLabel(): string
    {
        return 'Cron';
    }

    public function getIcon(): string
    {
        return 'event';
    }

    public function collect(ProfileContext $context): array
    {
        unset($context);

        $events = $this->events();
        $due = array_filter($events, static fn (array $event): bool => $event['timestamp'] <= time());
        $missed = array_filter($events, static fn (array $event): bool => $event['timestamp'] < time() - 300);

        return [
            'events'       => array_slice($events, 0, 150),
            'event_count'  => count($events),
            'due_count'    => count($due),
            'missed_count' => count($missed),
            'schedules'    => $this->schedules(),
            'doing_cron'   => function_exists('wp_doing_cron') && (bool) wp_doing_cron(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        return new ToolbarBlock(
            'cron',
            'Cron',
            sprintf('%d events', $this->intValue($payload, 'event_count')),
            sprintf('%d due', $this->intValue($payload, 'due_count')),
            $this->profileUrl($profile, 'cron'),
            $this->intValue($payload, 'missed_count') > 0 ? 'yellow' : 'green',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        unset($profile);

        $eventRows = [];

        foreach ($this->eventRows($payload) as $event) {
            $eventRows[] = [
                $event['next_run'],
                $event['status'],
                $event['hook'],
                $event['schedule'],
                $event['interval'],
                $event['args_count'],
            ];
        }

        $scheduleRows = [];

        foreach ($this->scheduleRows($payload) as $schedule) {
            $scheduleRows[] = [$schedule['name'], $schedule['display'], $schedule['interval']];
        }

        $html = '<h2>Cron</h2>';
        $html .= Html::metricTiles([
            ['label' => 'Scheduled events', 'value' => (string) $this->intValue($payload, 'event_count')],
            ['label' => 'Due now', 'value' => (string) $this->intValue($payload, 'due_count')],
            ['label' => 'Missed', 'value' => (string) $this->intValue($payload, 'missed_count')],
            ['label' => 'Doing cron', 'value' => $this->boolValue($payload, 'doing_cron') ? 'yes' : 'no'],
        ]);
        $html .= Html::tabNavigation([
            ['label' => 'Events', 'badge' => count($eventRows), 'active' => true, 'target' => '#cron-events'],
            ['label' => 'Schedules', 'badge' => count($scheduleRows), 'target' => '#cron-schedules'],
        ]);
        $html .= '<div id="cron-events" class="profiler-tab-target">'
            . Html::table(['Next run', 'Status', 'Hook', 'Schedule', 'Interval', 'Args'], $eventRows)
            . '</div>';
        $html .= '<div id="cron-schedules" class="profiler-tab-target">'
            . Html::table(['Name', 'Display', 'Interval'], $scheduleRows)
            . '</div>';

        return $this->panel(
            'cron',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            sprintf('%d', $this->intValue($payload, 'event_count')),
        );
    }

    /** @return list<array{timestamp: int, next_run: string, status: string, hook: string, schedule: string, interval: string, args_count: int}> */
    private function events(): array
    {
        if (!function_exists('_get_cron_array')) {
            return [];
        }

        $crons = _get_cron_array();

        $events = [];
        $now = time();

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $instances) {
                if (!is_array($instances)) {
                    continue;
                }

                foreach ($instances as $instance) {
                    if (!is_array($instance)) {
                        continue;
                    }

                    $eventTimestamp = is_numeric($timestamp) ? (int) $timestamp : 0;
                    $status = $eventTimestamp < $now - 300 ? 'missed' : ($eventTimestamp <= $now ? 'due' : 'scheduled');
                    $args = is_array($instance['args'] ?? null) ? $instance['args'] : [];
                    $interval = is_numeric($instance['interval'] ?? null) ? (int) $instance['interval'] : 0;

                    $events[] = [
                        'timestamp'  => $eventTimestamp,
                        'next_run'   => $eventTimestamp > 0 ? date_i18n('Y-m-d H:i:s', $eventTimestamp) : 'n/a',
                        'status'     => $status,
                        'hook'       => (string) $hook,
                        'schedule'   => is_scalar($instance['schedule'] ?? null) ? (string) $instance['schedule'] : 'single',
                        'interval'   => $interval > 0 ? $this->humanInterval($interval) : 'n/a',
                        'args_count' => count($args),
                    ];
                }
            }
        }

        usort($events, static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);

        return $events;
    }

    /** @return list<array{name: string, display: string, interval: string}> */
    private function schedules(): array
    {
        $schedules = function_exists('wp_get_schedules') ? wp_get_schedules() : [];

        $rows = [];

        foreach ($schedules as $name => $schedule) {
            $interval = $schedule['interval'];
            $rows[] = [
                'name'     => (string) $name,
                'display'  => $this->stringValue($schedule, 'display'),
                'interval' => $interval > 0 ? $this->humanInterval($interval) : 'n/a',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{timestamp: int, next_run: string, status: string, hook: string, schedule: string, interval: string, args_count: int}>
     */
    private function eventRows(array $payload): array
    {
        $events = $payload['events'] ?? [];

        if (!is_array($events)) {
            return [];
        }

        $rows = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $rows[] = [
                'timestamp'  => $this->intValue($event, 'timestamp'),
                'next_run'   => $this->stringValue($event, 'next_run'),
                'status'     => $this->stringValue($event, 'status'),
                'hook'       => $this->stringValue($event, 'hook'),
                'schedule'   => $this->stringValue($event, 'schedule'),
                'interval'   => $this->stringValue($event, 'interval'),
                'args_count' => $this->intValue($event, 'args_count'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name: string, display: string, interval: string}>
     */
    private function scheduleRows(array $payload): array
    {
        $schedules = $payload['schedules'] ?? [];

        if (!is_array($schedules)) {
            return [];
        }

        $rows = [];

        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }

            $rows[] = [
                'name'     => $this->stringValue($schedule, 'name'),
                'display'  => $this->stringValue($schedule, 'display'),
                'interval' => $this->stringValue($schedule, 'interval'),
            ];
        }

        return $rows;
    }

    private function humanInterval(int $seconds): string
    {
        if ($seconds >= 86400 && $seconds % 86400 === 0) {
            return ($seconds / 86400) . ' days';
        }

        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            return ($seconds / 3600) . ' hours';
        }

        if ($seconds >= 60 && $seconds % 60 === 0) {
            return ($seconds / 60) . ' minutes';
        }

        return $seconds . ' seconds';
    }
}
