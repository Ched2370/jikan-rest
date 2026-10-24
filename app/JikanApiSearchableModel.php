<?php

namespace App;

use Jenssegers\Mongodb\Eloquent\Model;
use Typesense\LaravelTypesense\Interfaces\TypesenseDocument;

abstract class JikanApiSearchableModel extends JikanApiModel implements TypesenseDocument
{
    use JikanSearchable;

    /**
     * @return string[]
     */
    public abstract function typesenseQueryBy(): array;

    /**
     * The Typesense schema to be created.
     *
     * @return array
     */
    public function getCollectionSchema(): array
    {
        $titleAttributeName = $this->getTitleAttributeName();

        return [
            'name' => $this->searchableAs(),
            'fields' => array_merge(
                [
                    [
                        'name' => '.*',
                        'type' => 'auto',
                    ],
                    [
                        'name' => $titleAttributeName,
                        'type' => 'string',
                        'sort' => true,
                        'optional' => false
                    ]
                ],
                $this->queryByFieldDeclarations($titleAttributeName),
                $this->sortFieldDeclarations()
            )
        ];
    }

    /**
     * Fields the API may sort on, as field name => Typesense type.
     *
     * They have to be declared explicitly: Typesense rejects sort_by on fields that are not part
     * of the schema, and the catch-all `.*` field only materializes once a document carries the
     * value. On a collection that is still empty (fresh install, wiped volume) every query with a
     * long enough term would therefore fail with e.g.
     * "Could not find a field named `popularity` in the schema for sorting."
     *
     * @return array<string, string>
     */
    public function typesenseSortFields(): array
    {
        return [];
    }

    /**
     * Types of query_by fields that are not plain strings (field name => Typesense type).
     * Everything not listed here is declared as an optional string.
     *
     * @return array<string, string>
     */
    public function typesenseQueryByFieldTypes(): array
    {
        return [];
    }

    /**
     * Declares every field listed in typesenseQueryBy(), which Typesense needs to exist before it
     * accepts it as a query_by parameter (e.g. `name_kanji` for characters, `given_name` for people).
     *
     * @param string $titleAttributeName already declared by getCollectionSchema(), must not be repeated
     * @return array
     */
    protected function queryByFieldDeclarations(string $titleAttributeName): array
    {
        $fieldTypes = $this->typesenseQueryByFieldTypes();
        $fields = [];

        foreach ($this->typesenseQueryBy() as $fieldName) {
            if ($fieldName === $titleAttributeName) {
                continue;
            }

            $fields[] = [
                'name' => $fieldName,
                'type' => $fieldTypes[$fieldName] ?? 'string',
                'optional' => true,
                'sort' => false,
            ];
        }

        return $fields;
    }

    /**
     * Schema entries for typesenseSortFields(): optional so documents without the value (unreleased
     * anime have no score yet, for instance) can still be indexed, sortable so sort_by/order_by work.
     *
     * @return array
     */
    protected function sortFieldDeclarations(): array
    {
        $fields = [];

        foreach ($this->typesenseSortFields() as $fieldName => $type) {
            $fields[] = [
                'name' => $fieldName,
                'type' => $type,
                'optional' => true,
                'sort' => true,
            ];
        }

        return $fields;
    }

    /**
     * Get the name of the index associated with the model.
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return strtolower($this->table) . '_index' . (env("APP_ENV") === "testing" ? "_testing" : "");
    }

    /**
     * Get the value used to index the model.
     *
     * @return mixed
     */
    public function getScoutKey(): mixed
    {
        return $this->mal_id;
    }

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName(): mixed
    {
        return 'mal_id';
    }

    /**
     * Returns what weights to use on query_by fields.
     * https://typesense.org/docs/0.23.0/api/documents.html#search-parameters
     * @return string|null
     */
    public function getTypeSenseQueryByWeights(): string|null
    {
        return null;
    }

    /**
     * Returns which fields the search index should sort on when searching
     * @return array|null
     */
    public function getSearchIndexSortBy(): array|null
    {
        return null;
    }

    protected function simplifyStringForSearch($val): string
    {
        if (!$val) {
            return "";
        }
        return preg_replace("/[^[:alnum:][:space:]]/u", ' ', $val) ?? "";
    }

    public function getTitleAttributeName(): string
    {
        return 'title';
    }
}
