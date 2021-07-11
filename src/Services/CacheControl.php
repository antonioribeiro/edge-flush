<?php

namespace A17\CDN\Services;

use A17\CDN\CDN;
use Illuminate\Support\Str;
use A17\CDN\Support\Constants;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use A17\CDN\Contracts\Service as ServiceContract;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use A17\CDN\Exceptions\FrontendChecker as FrontendCheckerException;

class CacheControl extends BaseService implements ServiceContract
{
    protected $_isCachable;

    protected $_content;

    protected $public;

    protected $maxAge;

    protected $strategy;

    public function makeResponse(Response $response): Response
    {
        return $this->addHeadersToResponse(
            $response,
            'cache-control',
            $this->getCacheStrategy($response),
        );
    }

    protected function contentContains(Response $response, string $string): bool
    {
        return Str::contains(
            $this->getContent($response),
            $this->minifyContent($string),
        );
    }

    public function isCachable(Response $response = null): bool
    {
        if (filled($this->_isCachable)) {
            return $this->_isCachable;
        }

        return $this->_isCachable = !$this->getCachableMatrix(
            $response,
        )->contains(false);
    }

    public function getCachableMatrix(Response $response): Collection
    {
        return collect([
            'enabled' => CDN::enabled(),
            'isFrontend' => $this->isFrontend(),
            'notValidForm' => $this->doesNotContainAValidForm($response),
            'middlewareAllowCaching' => $this->middlewaresAllowCaching(),
            'routeIsCachable' => $this->routeIsCachable(),
            'responseIsCachable' => $this->responseIsCachable($response),
            'methodIsCachable' => $this->methodIsCachable(),
            'statusCodeIsCachable' => $this->statusCodeIsCachable($response),
        ]);
    }

    public function getCacheStrategy(Response $response): string
    {
        if (filled($this->strategy)) {
            return $this->buildStrategy($this->strategy);
        }

        return $this->isCachable($response)
            ? $this->buildStrategy('cache')
            : $this->buildStrategy('do-not-cache');
    }

    /**
     * @psalm-suppress UndefinedMethod
     */
    protected function getContent(Response $response): string
    {
        if (
            !filled($this->_content) &&
            filled($response) &&
            !($response instanceof BinaryFileResponse)
        ) {
            $this->_content = $this->minifyContent($response->content());
        }

        return $this->_content;
    }

    public function getMaxAge(): int
    {
        if (filled($this->maxAge)) {
            return $this->maxAge;
        }

        return $this->getDefaultMaxAge();
    }

    protected function doesNotContainAValidForm(Response $response): bool
    {
        $hasForm = false;

        if (config('cdn.valid_forms.enabled', false)) {
            $hasForm = collect(
                config('cdn.valid_forms.strings', false),
            )->reduce(function (bool $hasForm, string $string) use ($response) {
                $string = Str::replace('%CSRF_TOKEN%', csrf_token(), $string);

                $hasForm =
                    $hasForm && $this->contentContains($response, $string);

                return $hasForm;
            }, true);
        }

        return !$hasForm;
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    protected function isFrontend(): bool
    {
        $checker = config('cdn.frontend-checker');

        if (is_callable($checker)) {
            return $checker();
        }

        if (is_bool($checker)) {
            return $checker;
        }

        if (is_string($checker) && class_exists($checker)) {
            return app($checker)->runningOnFrontend();
        }

        FrontendCheckerException::unsupportedType(gettype($checker));
    }

    protected function minifyContent(string $content): string
    {
        return str_replace(' ', '', $content);
    }

    /**
     * @psalm-suppress PossiblyNullPropertyFetch
     */
    protected function middlewaresAllowCaching(): bool
    {
        $middleware = blank($route = request()->route())
            ? 'no-middleware'
            : $route->action['middleware'] ?? null;

        return !collect($middleware)->contains('doNotCacheResponse');
    }

    public function setMaxAge(int $maxAge): self
    {
        if (blank($maxAge)) {
            return $this;
        }

        if (config('cdn.max-age.strategy') === 'min') {
            $this->maxAge = min(
                $maxAge,
                $this->maxAge ?? $this->getDefaultMaxAge(),
            );
        }

        if (config('cdn.max-age.strategy') === 'last') {
            $this->maxAge = $maxAge;
        }

        return $this;
    }

    public function getDefaultMaxAge(): int
    {
        return (int) config('cdn.max-age.default', Constants::WEEK);
    }

    public function buildStrategy(string $strategy): string
    {
        return collect(config("cdn.strategies.$strategy"))
            ->map(
                fn(string $header) => [
                    'header' => $header,
                    'value' => $this->getHeaderValue($header),
                ],
            )
            ->map(
                fn(array $item) => $item['header'] === $item['value']
                    ? $item['header']
                    : "{$item['header']}={$item['value']}",
            )
            ->sort()
            ->join(', ');
    }

    /**
     * @param string $header
     * @return int|string
     */
    public function getHeaderValue(string $header)
    {
        if ($header === 'max-age' || $header === 's-maxage') {
            return $this->getMaxAge();
        }

        if (
            $header === 'max-stale' ||
            $header === 'min-fresh' ||
            $header === 'stale-while-revalidate' ||
            $header === 'stale-if-error'
        ) {
            return 'unsupported';
        }

        return $header;
    }

    public function getStrategy(): ?string
    {
        return $this->strategy ?? null;
    }

    public function setStrategy(string $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    public function responseIsCachable(Response $response): bool
    {
        return (collect(config('cdn.responses.cachable'))->isEmpty() ||
            collect(config('cdn.responses.cachable'))->contains(
                get_class($response),
            )) &&
            !collect(config('cdn.responses.not-cachable'))->contains(
                get_class($response),
            );
    }

    /**
     * @psalm-suppress PossiblyNullReference|PossiblyInvalidMethodCall
     */
    public function methodIsCachable(): bool
    {
        return (collect(config('cdn.methods.cachable'))->isEmpty() ||
            collect(config('cdn.methods.cachable'))->contains(
                request()->getMethod(),
            )) &&
            !collect(config('cdn.methods.not-cachable'))->contains(
                request()->getMethod(),
            );
    }

    public function statusCodeIsCachable(Response $response): bool
    {
        return (collect(config('cdn.statuses.cachable'))->isEmpty() ||
            collect(config('cdn.statuses.cachable'))->contains(
                $response->getStatusCode(),
            )) &&
            !collect(config('cdn.statuses.not-cachable'))->contains(
                $response->getStatusCode(),
            );
    }

    /**
     * @psalm-suppress PossiblyInvalidMethodCall|PossiblyNullReference
     */
    public function routeIsCachable(): bool
    {
        $route = request()->route();

        $route = filled($route) ? $route->getName() : null;

        if (blank($route)) {
            return config('cdn.routes.cache_nameless_routes', false);
        }

        /**
         * @param callable(string $pattern): boolean $filter
         */
        $filter = fn(string $pattern) => CDN::match($pattern, $route);

        return (collect(config('cdn.routes.cachable'))->isEmpty() ||
            collect(config('cdn.routes.cachable'))->contains($filter)) &&
            !collect(config('cdn.routes.not-cachable'))->contains($filter);
    }
}
