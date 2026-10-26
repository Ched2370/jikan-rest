<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * A Scout search service which can take the request's filter parameters and push them into the
 * search engine query itself (Typesense `filter_by`), instead of only relying on the post-filter
 * that SearchEngineSearchService applies while hydrating the results.
 */
interface FilterableScoutSearchService extends ScoutSearchService
{
    public function setFilterParameters(Collection $requestParameters): self;
}
