<?php
namespace App\Services;
use Typesense\LaravelTypesense\Typesense;
use App\JikanApiSearchableModel;
use Typesense\Collection as TypesenseCollection;
use Illuminate\Support\Collection;

/**
 * A service which helps to describe a collection in Typesense based on an eloquent model
 */
final class TypesenseCollectionDescriptor
{
    private readonly Collection $cache;

    public function __construct(private readonly Typesense $typesense)
    {
        $this->cache = collect();   
    }

    public function getSearchableAttributes(JikanApiSearchableModel $model): array
    {
        return array_keys($this->getFields($model));
    }

    /**
     * Fields of the collection as Typesense really knows them (declared in getCollectionSchema(),
     * plus the ones Typesense materialized while indexing documents): field name => field type.
     *
     * Used to decide which filters can be pushed down to the search engine: filtering on a field
     * which is not part of the schema is rejected by Typesense with a 400.
     */
    public function getFields(JikanApiSearchableModel $model): array
    {
        $modelSearchableAs = $model->searchableAs();
        if ($this->cache->has($modelSearchableAs)) {
            return $this->cache->get($modelSearchableAs);
        }
        /**
         * @var TypesenseCollection $collection
         */
        $collection = $this->typesense->getCollectionIndex($model);
        $collectionDetails = $collection->retrieve();
        $fields = collect($collectionDetails["fields"]);
        $fieldTypes = $fields->mapWithKeys(fn ($field) => [$field['name'] => $field['type']]);
        $this->cache->put($modelSearchableAs, $fieldTypes->all());

        return $fieldTypes->all();
    }
}