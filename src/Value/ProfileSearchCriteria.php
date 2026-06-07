<?php

declare(strict_types=1);

namespace SymPress\Profiler\Value;

final class ProfileSearchCriteria
{
    private readonly string $textValue;
    private readonly string $tokenValue;
    private readonly string $methodValue;
    private readonly string $urlValue;
    private readonly string $ipValue;
    private readonly ?int $statusCodeValue;
    private readonly string $contextValue;
    private readonly int $limitValue;
    private readonly string $startValue;
    private readonly string $endValue;

    public string $text {
        get => $this->textValue;
    }

    public string $token {
        get => $this->tokenValue;
    }

    public string $method {
        get => $this->methodValue;
    }

    public string $url {
        get => $this->urlValue;
    }

    public string $ip {
        get => $this->ipValue;
    }

    public ?int $statusCode {
        get => $this->statusCodeValue;
    }

    public string $context {
        get => $this->contextValue;
    }

    public int $limit {
        get => $this->limitValue;
    }

    public string $start {
        get => $this->startValue;
    }

    public string $end {
        get => $this->endValue;
    }

    public function __construct(
        string $text = '',
        string $token = '',
        string $method = '',
        string $url = '',
        string $ip = '',
        ?int $statusCode = null,
        string $context = '',
        int $limit = 50,
        string $start = '',
        string $end = '',
    ) {
        $this->textValue = trim($text);
        $this->tokenValue = trim($token);
        $this->methodValue = strtoupper(trim($method));
        $this->urlValue = trim($url);
        $this->ipValue = trim($ip);
        $this->statusCodeValue = $statusCode;
        $this->contextValue = strtolower(trim($context));
        $this->limitValue = max(1, min($limit, 200));
        $this->startValue = trim($start);
        $this->endValue = trim($end);
    }

    /**
     * @return array{q?: string, token?: string, method?: string, url?: string, ip?: string, status?: int, context?: string, limit?: int, start?: string, end?: string}
     */
    public function toQueryArgs(): array
    {
        $query = [];

        if ($this->text !== '') {
            $query['q'] = $this->text;
        }

        if ($this->token !== '') {
            $query['token'] = $this->token;
        }

        if ($this->method !== '') {
            $query['method'] = $this->method;
        }

        if ($this->url !== '') {
            $query['url'] = $this->url;
        }

        if ($this->ip !== '') {
            $query['ip'] = $this->ip;
        }

        if ($this->statusCode !== null) {
            $query['status'] = $this->statusCode;
        }

        if ($this->context !== '') {
            $query['context'] = $this->context;
        }

        if ($this->limit !== 50) {
            $query['limit'] = $this->limit;
        }

        if ($this->start !== '') {
            $query['start'] = $this->start;
        }

        if ($this->end !== '') {
            $query['end'] = $this->end;
        }

        return $query;
    }
}
