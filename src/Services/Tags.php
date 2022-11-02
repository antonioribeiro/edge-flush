<?php declare(strict_types=1);

namespace A17\EdgeFlush\Services;

use A17\EdgeFlush\EdgeFlush;
use A17\EdgeFlush\Models\Tag;
use A17\EdgeFlush\Models\Url;
use A17\EdgeFlush\Jobs\StoreTags;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use A17\EdgeFlush\Support\Helpers;
use SebastianBergmann\Timer\Timer;
use A17\EdgeFlush\Support\Constants;
use A17\EdgeFlush\Jobs\InvalidateTags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use A17\EdgeFlush\Behaviours\MakeTag;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use A17\EdgeFlush\Behaviours\ControlsInvalidations;

class Tags
{
    use ControlsInvalidations, MakeTag;

    protected Collection $tags;

    public Collection $processedTags;

    public function __construct()
    {
        $this->tags = collect();

        $this->processedTags = collect();
    }

    public function addTag(
        Model $model,
        string $key = null,
        array $allowedKeys = []
    ): void {
        if (
            !EdgeFlush::enabled() ||
            blank($model->getAttributes()[$key] ?? null)
        ) {
            return;
        }

        $tags = [
            $this->makeModelName($model, Constants::ANY_TAG, $allowedKeys),
            $this->makeModelName($model, $key, $allowedKeys),
        ];

        foreach ($tags as $tag) {
            if (blank($this->tags[$tag] ?? null)) {
                $this->tags[$tag] = $tag;
            }
        }
    }

    protected function getAllTagsForModel(
        string|null $modelString
    ): Collection|null {
        if (filled($modelString)) {
            return Tag::where('model', $modelString)->get();
        }

        return null;
    }

    public function getTags(): Collection
    {
        return collect($this->tags)
            ->reject(function (string $tag) {
                return $this->tagIsExcluded($tag);
            })
            ->values();
    }

    public function getTagsHash(Response $response, Request $request): string
    {
        $tag = $this->makeEdgeTag($models = $this->getTags());

        if (
            EdgeFlush::cacheControl()->isCachable($response) &&
            EdgeFlush::storeTagsServiceIsEnabled()
        ) {
            StoreTags::dispatch(
                $models,
                [
                    'cdn' => $tag,
                ],
                $this->getCurrentUrl($request),
            );
        }

        return $tag;
    }

    public function makeEdgeTag(Collection|null $models = null): string
    {
        $models ??= $this->getTags();

        $format = Helpers::toString(
            config('edge-flush.tags.format', 'app-%environment%-%sha1%'),
        );

        return str_replace(
            ['%environment%', '%sha1%'],
            [
                app()->environment(),
                sha1(
                    collect($models)
                        ->sort()
                        ->join(', '),
                ),
            ],
            $format,
        );
    }

    public function tagIsExcluded(string $tag): bool
    {
        /**
         * @param callable(string $pattern): boolean $pattern
         */
        return collect(
            config('edge-flush.tags.excluded-model-classes'),
        )->contains(fn(string $pattern) => EdgeFlush::match($pattern, $tag));
    }

    public function tagIsNotExcluded(string $tag): bool
    {
        return !$this->tagIsExcluded($tag);
    }

    public function storeCacheTags(
        Collection $models,
        array $tags,
        string $url
    ): void {
        if (
            !EdgeFlush::enabled() ||
            !EdgeFlush::storeTagsServiceIsEnabled() ||
            !$this->domainAllowed($url)
        ) {
            return;
        }

        Helpers::debug(
            'STORE-TAGS: ' .
                json_encode([
                    'models' => $models,
                    'tags' => $tags,
                    'url' => $url,
                ]),
        );

        $indexes = collect();

        DB::transaction(function () use ($models, $tags, $url, &$indexes) {
            $url = $this->createUrl($url);

            $now = (string) now();

            $indexes = collect($models)
                ->filter()
                ->map(function (mixed $model) use ($tags, $url, $now) {
                    $model = Helpers::toString($model);

                    $index = $this->makeTagIndex($url, $tags, $model);

                    $this->dbStatement("
                        insert into edge_flush_tags (index, url_id, tag, model, created_at, updated_at)
                        select '{$index}', {$url->id}, '{$tags['cdn']}', '{$model}', '{$now}', '{$now}'
                        where not exists (
                            select 1
                            from edge_flush_tags
                            where index = '{$index}'
                        )
                        ");

                    return $index;
                });
        }, 5);

        if ($indexes->isNotEmpty()) {
            $indexes = $indexes
                ->map(fn(mixed $item) => "'" . Helpers::toString($item) . "'")
                ->join(',');

            $this->dbStatement("
                        update edge_flush_urls
                        set obsolete = false,
                            was_purged_at = null,
                            invalidation_id = null
                        where is_valid = true
                          and was_purged_at is not null
                          and id in (
                            select url_id
                            from edge_flush_tags
                            where index in ({$indexes})
                              and is_valid = true
                              and obsolete = false
                          )
                        ");
        }
    }

    public function dispatchInvalidationsForModel(
        Collection|string|Model $models
    ): void {
        if (blank($models)) {
            return;
        }

        $models =
            $models instanceof Model ? collect([$models]) : collect($models);

        $models = $models->filter(
            fn($model) => $this->tagIsNotExcluded(
                $model instanceof Model ? get_class($model) : $model,
            ),
        );

        if ($models->isEmpty()) {
            return;
        }

        $modelNames = collect();

        /**
         * @var Model $model
         */
        foreach ($models as $model) {
            foreach ($model->getAttributes() as $key => $updated) {
                if (
                    $updated !== $model->getOriginal($key) &&
                    $this->granularPropertyIsAllowed($key, $model)
                ) {
                    $modelName = $this->makeModelName($model, $key);

                    if (filled($modelName)) {
                        $modelNames[] = $modelName;
                    }
                }
            }
        }

        if ($modelNames->isEmpty()) {
            return;
        }

        Helpers::debug(
            'Dispatching invalidation job for models: ' .
                $modelNames->join(', '),
        );

        InvalidateTags::dispatch((new Invalidation())->setModels($modelNames));
    }

    public function invalidateTags(Invalidation $invalidation): void
    {
        if (!EdgeFlush::invalidationServiceIsEnabled()) {
            return;
        }

        if ($invalidation->isEmpty()) {
            $this->invalidateObsoleteTags();

            return;
        }

        config('edge-flush.invalidations.type') === 'batch'
            ? $this->markTagsAsObsolete($invalidation)
            : $this->dispatchInvalidations($invalidation);
    }

    protected function invalidateObsoleteTags(): void
    {
        if (!$this->enabled()) {
            return;
        }

        /**
         * Filter purged urls from obsolete tags.
         * Making sure we invalidate the most busy pages first.
         */
        $rows = collect(
            DB::select(
                "
            select distinct edge_flush_urls.id, edge_flush_urls.hits, edge_flush_urls.url
            from edge_flush_urls
            where edge_flush_urls.was_purged_at is null
              and edge_flush_urls.obsolete = true
              and edge_flush_urls.is_valid = true
            order by edge_flush_urls.hits desc
            ",
            ),
        )->map(fn($row) => new Url((array) $row));

        $invalidation = (new Invalidation())->setUrls($rows);

        /**
         * Let's first calculate the number of URLs we are invalidating.
         * If it's above max, just flush the whole website.
         */
        if ($rows->count() >= EdgeFlush::cdn()->maxUrls()) {
            $this->invalidateEntireCache($invalidation);

            return;
        }

        /**
         * Let's dispatch invalidations only for what's configured.
         */
        $this->dispatchInvalidations($invalidation);
    }

    protected function markTagsAsObsolete(Invalidation $invalidation): void
    {
        $type = $invalidation->type();

        $items = $invalidation->queryItemsList();

        /**
         * Search for URls:
         *    - Not obsolete
         *    - Not purged yet, if it was purged, it's waiting for a warmup or a user to request it
         */
        $this->dbStatement("
            update edge_flush_urls efu
            set obsolete = true
                from (
                         select id
                         from edge_flush_urls
                         where is_valid = true
                           and obsolete = false
                           and was_purged_at is null
                           and id in (
                                 select url_id
                                 from edge_flush_tags
                                 where is_valid = true
                                 and {$type} in ({$items})
                             )
                         order by id
                             for update
                     ) urls
                        where efu.id = urls.id
        ");
    }

    protected function dispatchInvalidations(Invalidation $invalidation): void
    {
        if ($invalidation->isEmpty() || !$this->enabled()) {
            return;
        }

        $invalidation = EdgeFlush::cdn()->invalidate($invalidation);

        if ($invalidation->success()) {
            // TODO: what happens here on Akamai?
            $this->markUrlsAsPurged($invalidation);
        }
    }

    protected function invalidateEntireCache(Invalidation $invalidation): void
    {
        if (!$this->enabled()) {
            return;
        }

        Helpers::debug('INVALIDATING: entire cache...');

        $invalidation->setMustInvalidateAll(true);

        EdgeFlush::cdn()->invalidate(
            $invalidation->setPaths(
                collect(config('edge-flush.invalidations.batch.roots')),
            ),
        );

        $this->markUrlsAsPurged($invalidation);
    }

    /*
     * Optimized for speed, 2000 calls to EdgeFlush::tags()->addTag($model) are now only 8ms
     */
    protected function wasNotProcessed(Model $model): bool
    {
        $id = $model->getAttributes()[$model->getKeyName()] ?? null;

        if ($id === null) {
            return false; /// don't process models with no ID yet
        }

        $key = $model->getTable() . '-' . $id;

        if (
            filled($this->processedTags[$key] ?? null) &&
            (bool) $this->processedTags[$key]
        ) {
            return false;
        }

        $this->processedTags[$key] = true;

        return true;
    }

    public function invalidateAll(bool $force = false): Invalidation
    {
        if (!$this->enabled() && !$force) {
            return $this->unsuccessfulInvalidation();
        }

        $count = 0;

        do {
            if ($count++ > 0) {
                sleep(2);
            }

            $success = EdgeFlush::cdn()
                ->invalidateAll()
                ->success();
        } while ($count < 3 && !$success);

        if (!$success) {
            return $this->unsuccessfulInvalidation();
        }

        $this->deleteAllTags();

        return $this->successfulInvalidation();
    }

    public function getCurrentUrl(Request $request): string
    {
        $result = $request->header('X-Edge-Flush-Warmed-Url') ?? url()->full();

        if (is_array($result)) {
            $result = $result[0] ?? '';
        }

        return $result;
    }

    protected function deleteAllTags(): void
    {
        $now = (string) now();

        $this->dbStatement("
            update edge_flush_urls efu
            set obsolete = true,
                set was_purged_at = '$now'
            from (
                    select id
                    from edge_flush_urls
                    where obsolete = false
                    order by id
                    for update
                ) urls
            where efu.id = urls.id
        ");
    }

    public function domainAllowed(string|null $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $allowed = collect(config('edge-flush.domains.allowed'))->filter();

        $blocked = collect(config('edge-flush.domains.blocked'))->filter();

        if ($allowed->isEmpty() && $blocked->isEmpty()) {
            return true;
        }

        $domain = Helpers::parseUrl($url)['host'];

        return $allowed->contains($domain) && !$blocked->contains($domain);
    }

    public function getMaxInvalidations(): int
    {
        return Helpers::toInt(
            min(
                EdgeFlush::cdn()->maxUrls(),
                config('edge-flush.invalidations.batch.size'),
            ),
        );
    }

    public function dbStatement(string $sql): bool
    {
        return DB::statement((string) DB::raw($sql));
    }

    public function enabled(): bool
    {
        return EdgeFlush::invalidationServiceIsEnabled() &&
            EdgeFlush::cdn()->enabled();
    }

    /**
     * @param string $url
     * @return Url
     */
    function createUrl(string $url): Url
    {
        $url = Helpers::sanitizeUrl($url);

        return Url::firstOrCreate(
            ['url_hash' => sha1($url)],
            [
                'url' => Str::limit($url, 255),
                'hits' => 1,
            ],
        );
    }

    public function makeTagIndex(
        Url|string $url,
        array $tags,
        string $model
    ): string {
        if (is_string($url)) {
            $url = $this->createUrl($url);
        }

        $index = "{$url->id}-{$tags['cdn']}-{$model}";

        return sha1($index);
    }

    public function markUrlsAsPurged(Invalidation $invalidation): void
    {
        $list = $invalidation->queryItemsList('url');

        $time = (string) now();

        $invalidationId = $invalidation->id();

        if ($invalidation->mustInvalidateAll()) {
            $sql = "
                update edge_flush_urls efu
                set was_purged_at = '{$time}',
                    invalidation_id = '{$invalidationId}'
                from (
                        select efu.id
                        from edge_flush_urls efu
                        where efu.is_valid = true
                          and was_purged_at is null
                           or obsolete = false
                        order by efu.id
                        for update
                    ) urls
                where efu.id = urls.id
            ";
        } elseif ($invalidation->type() === 'tag') {
            throw new \Exception("Invalidating tags directly is not supported for now.");
        } elseif ($invalidation->type() === 'url') {
            $sql = "
                update edge_flush_urls efu
                set was_purged_at = '{$time}',
                    invalidation_id = '{$invalidationId}'
                from (
                        select efu.id
                        from edge_flush_urls efu
                        where efu.is_valid = true
                          and efu.url in ({$list})
                        order by efu.id
                        for update
                    ) urls
                where efu.id = urls.id
            ";
        }

        $this->dbStatement($sql);
    }

    public function markUrlsAsWarmed(Collection $urls): void
    {
        $list = $urls
            ->pluck('id')
            ->map(fn($item) => Helpers::toString($item))
            ->join(',');

        $sql = "
                update edge_flush_urls efu
                set obsolete = false,
                    was_purged_at = null,
                    invalidation_id = null
                from (
                        select efu.id
                        from edge_flush_urls efu
                        where efu.is_valid = true
                          and efu.id in ({$list})
                        order by efu.id
                        for update
                    ) urls
                where efu.id = urls.id
            ";

        $this->dbStatement($sql);
    }

    public function granularPropertyIsAllowed(string $name, Model $model): bool
    {
        $ignored = collect(
            Helpers::configArray('edge-flush.invalidations.properties.ignored'),
        );

        return !$ignored->contains($name) &&
            !$ignored->contains(get_class($model) . "@$name");
    }
}
