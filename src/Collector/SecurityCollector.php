<?php

declare(strict_types=1);

namespace SymPress\Profiler\Collector;

use SymPress\Profiler\Contract\DataCollectorInterface;
use SymPress\Profiler\Support\Html;
use SymPress\Profiler\Value\ProfileContext;
use SymPress\Profiler\Value\ProfileRecord;
use SymPress\Profiler\Value\ToolbarBlock;

final class SecurityCollector extends AbstractCollector implements DataCollectorInterface
{
    public function getKey(): string
    {
        return 'security';
    }

    public function getLabel(): string
    {
        return 'Security';
    }

    public function getIcon(): string
    {
        return 'user';
    }

    public function collect(ProfileContext $context): array
    {
        $loggedIn = function_exists('is_user_logged_in') && is_user_logged_in();
        $currentUser = $this->currentUser();

        return [
            'logged_in'          => $loggedIn,
            'user'               => $currentUser,
            'has_auth_cookie'    => $this->hasAuthCookie(),
            'ssl'                => function_exists('is_ssl') ? is_ssl() : false,
            'can_manage_options' => $loggedIn && function_exists('current_user_can')
                ? current_user_can('manage_options')
                : false,
            'captured_at'        => $context->finishedAtIso(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createToolbarBlock(array $payload, ProfileRecord $profile): ToolbarBlock
    {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $login = $this->stringValue($user, 'login', 'n/a');

        return new ToolbarBlock(
            'security',
            'Security',
            $login,
            $this->boolValue($payload, 'logged_in') ? 'Authenticated request' : 'Anonymous request',
            $this->profileUrl($profile, 'security'),
            $this->boolValue($payload, 'logged_in') ? 'green' : 'cyan',
        );
    }

    /** @param array<string, mixed> $payload */
    public function renderPanel(array $payload, ProfileRecord $profile): CollectorPanel
    {
        $overview = Html::definitionTable([
            'Captured at'        => $this->stringValue($payload, 'captured_at'),
            'Logged in'          => $this->boolValue($payload, 'logged_in'),
            'Has auth cookie'    => $this->boolValue($payload, 'has_auth_cookie'),
            'SSL'                => $this->boolValue($payload, 'ssl'),
            'Can manage options' => $this->boolValue($payload, 'can_manage_options'),
        ]);

        $html = Html::section('Overview', $overview);
        $html .= Html::section('User', Html::codeBlock($payload['user'] ?? []));

        return $this->panel(
            'security',
            $this->getLabel(),
            $this->getIcon(),
            $html,
            $this->boolValue($payload, 'logged_in') ? $this->stringValue((array) ($payload['user'] ?? []), 'login', 'user') : 'n/a',
            $this->boolValue($payload, 'logged_in') || $this->boolValue($payload, 'has_auth_cookie'),
        );
    }

    /** @return array{id: int, login: string, display_name: string, roles: list<string>}|array{} */
    private function currentUser(): array
    {
        if (!function_exists('wp_get_current_user')) {
            return [];
        }

        $user = wp_get_current_user();

        if ((int) $user->ID <= 0) {
            return [];
        }

        return [
            'id'           => (int) $user->ID,
            'login'        => is_scalar($user->user_login ?? null) ? (string) $user->user_login : '',
            'display_name' => is_scalar($user->display_name ?? null) ? (string) $user->display_name : '',
            'roles'        => array_values($user->roles),
        ];
    }

    private function hasAuthCookie(): bool
    {
        foreach (array_keys($_COOKIE) as $cookieName) {
            if (!is_string($cookieName)) {
                continue;
            }

            if (
                str_starts_with($cookieName, 'wordpress_logged_in_')
                || str_starts_with($cookieName, 'wordpress_sec_')
            ) {
                return true;
            }
        }

        return false;
    }
}
