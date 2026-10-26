<?php

namespace App\Services;

use App\Contracts\Repository;

final class SearchEngineSearchService extends SearchServiceBase
{
    public function __construct(
        private readonly ScoutSearchService $scoutSearchService,
        Repository $repository
    )
    {
        parent::__construct($repository);
    }

    public function search(string $searchTerms, ?string $orderByFields = null, bool $sortDirectionDescending = false): \Laravel\Scout\Builder|\Illuminate\Database\Eloquent\Builder
    {
        if ($this->scoutSearchService instanceof FilterableScoutSearchService) {
            // let the search service push the filters into the search engine query itself, so the
            // engine only returns hits that can match (the query callback below stays in charge of
            // the authoritative filtering while hydrating the results)
            $this->scoutSearchService->setFilterParameters($this->filterParameters);
        }

        return $this->scoutSearchService->search($searchTerms, $orderByFields,
            $sortDirectionDescending)->query(function (\Illuminate\Database\Eloquent\Builder $query) {
                return $query->filter($this->filterParameters);
        });
    }
}
