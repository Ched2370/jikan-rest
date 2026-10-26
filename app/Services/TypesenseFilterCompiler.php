<?php

namespace App\Services;

/**
 * Compiles the model filter pipeline (app/Filters/* plus the filterBy* methods declared by every
 * model) into a Typesense `filter_by` expression.
 *
 * It lets `?q=` searches push the filters down into the search engine request itself: the engine
 * only returns hits that can possibly match, so `found` is real, pagination stops fetching chunks
 * that are going to be thrown away, and hydration works on a small id set.
 *
 * The existing post-filter (SearchEngineSearchService applies the very same filters to the Mongo
 * query that hydrates the results) remains the authority, so the compilation is deliberately
 * one-sided: anything that cannot be expressed safely is dropped, and dropping can only *widen*
 * the expression:
 *
 *  - an AND clause that cannot be emitted simply disappears (superset of the intended filter),
 *  - an OR level whose branches cannot all be emitted is dropped as a whole, because emitting only
 *    the surviving branches would narrow the query down (subset), which is not allowed.
 *
 * The Typesense expression is therefore always a superset of the real filter and can never remove
 * a result that the Mongo post-filter would have kept.
 */
class TypesenseFilterCompiler
{
    /**
     * Attribute paths used by the filterBy* methods => field names of the indexed document.
     * Paths without a counterpart in the index (e.g. demographics.mal_id, which toSearchableArray()
     * never indexes) are missing on purpose: resolveField() rejects them and the clause is dropped.
     */
    private const FIELD_ALIASES = [
        'aired.from' => 'start_date',
        'aired.to' => 'end_date',
        'published.from' => 'start_date',
        'published.to' => 'end_date',
        'genres.mal_id' => 'genres',
        'explicit_genres.mal_id' => 'explicit_genres',
        'demographics.mal_id' => 'demographics',
        'themes.mal_id' => 'themes',
        'producers.mal_id' => 'producers',
        'licensors.mal_id' => 'licensors',
        'studios.mal_id' => 'studios',
        'magazines.mal_id' => 'magazines',
        'authors.mal_id' => 'authors',
        'serializations.mal_id' => 'serializations',
    ];

    private const OPERATORS = [
        '=' => ':=',
        '!=' => ':!=',
        '<>' => ':!=',
        '>' => ':>',
        '<' => ':<',
        '>=' => ':>=',
        '<=' => ':<=',
    ];

    /**
     * Characters/sequences Typesense cannot parse inside a filter value, even when quoted.
     * Parentheses are always treated as grouping operators ("Could not parse the filter query"),
     * `&&`/`||` terminate the quoted value, `"` and backslashes would escape it.
     */
    private const UNSAFE_VALUE_PATTERN = '/[()"\\\\\[\]~\x00-\x1F]|&&|\|\|/';

    /**
     * @param array<string, string> $schemaFields field name => Typesense type, exactly as the
     *        collection declares them (declared or materialized by Typesense when indexing)
     */
    public function __construct(private readonly array $schemaFields)
    {
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query builder that has already gone through ->filter()
     */
    public function compile($query): ?string
    {
        $wheres = $query->wheres ?? [];

        if (empty($wheres)) {
            return null;
        }

        $expression = $this->compileLevel($wheres);

        return ($expression === null || $expression === '') ? null : $expression;
    }

    /**
     * Compiles one level of the where tree. A level is a series of AND segments separated by OR
     * connectors (Laravel stores the connector of every where entry in its `boolean` key), which
     * mirrors SQL precedence: `a AND b OR c` => `(a && b) || c`.
     *
     * @return string|null null when the level imposes no usable constraint
     */
    private function compileLevel(array $wheres): ?string
    {
        $segments = [];
        $current = [];

        foreach ($wheres as $where) {
            if (strtolower($where['boolean'] ?? 'and') === 'or' && !empty($current)) {
                $segments[] = $current;
                $current = [];
            }
            $current[] = $where;
        }

        if (!empty($current)) {
            $segments[] = $current;
        }

        $parts = [];

        foreach ($segments as $segment) {
            $items = [];

            foreach ($segment as $where) {
                $item = $this->compileWhere($where);
                if ($item !== null) {
                    // a nested OR level is a single AND operand here, so it has to keep its
                    // grouping: `a && (b || c)`, never `a && b || c`
                    $items[] = $this->group($item);
                }
            }

            if (empty($items)) {
                // This OR branch cannot restrict anything, so the whole level is unconstrained
                // (emitting the other branches alone would narrow the query down).
                return null;
            }

            $parts[] = implode(' && ', $items);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return implode(' || ', array_map(fn (string $part) => '(' . $part . ')', $parts));
    }

    private function group(string $expression): string
    {
        return str_contains($expression, ' || ') ? '(' . $expression . ')' : $expression;
    }

    /**
     * @return string|null null when this where cannot be expressed and must be dropped
     */
    private function compileWhere(array $where): ?string
    {
        switch ($where['type'] ?? null) {
            case 'Nested':
                return $this->compileLevel($where['query']->wheres ?? []);

            case 'Basic':
                return $this->compileComparison(
                    (string) ($where['column'] ?? ''),
                    (string) ($where['operator'] ?? '='),
                    $where['value'] ?? null
                );

            case 'In':
                return $this->compileIn((string) ($where['column'] ?? ''), $where['values'] ?? [], false);

            case 'NotIn':
                return $this->compileIn((string) ($where['column'] ?? ''), $where['values'] ?? [], true);

            default:
                // Null, NotNull, Raw, Column, Between, ... -> unsupported, drop (widens the query)
                return null;
        }
    }

    private function compileComparison(string $column, string $operator, mixed $value): ?string
    {
        $field = $this->resolveField($column);

        if ($field === null) {
            return null;
        }

        $typesenseOperator = self::OPERATORS[strtolower(trim($operator))] ?? null;

        if ($typesenseOperator === null) {
            // like/ilike/regexp/... -> unsupported
            return null;
        }

        $rendered = $this->renderValue($field, $value);

        if ($rendered === null) {
            return null;
        }

        return $field . $typesenseOperator . $rendered;
    }

    private function compileIn(string $column, array $values, bool $negated): ?string
    {
        $field = $this->resolveField($column);

        if ($field === null) {
            return null;
        }

        $rendered = [];

        foreach ($values as $value) {
            $item = $this->renderValue($field, $value);
            if ($item === null) {
                return null;
            }
            $rendered[] = $item;
        }

        if (empty($rendered)) {
            return null;
        }

        return $field . ($negated ? ':!=' : ':=') . '[' . implode(',', $rendered) . ']';
    }

    /**
     * Maps a query column onto an indexed field, rejecting everything that is not part of the
     * collection schema (Typesense rejects filters on unknown fields with a 400).
     */
    private function resolveField(string $column): ?string
    {
        if ($column === '') {
            return null;
        }

        $field = self::FIELD_ALIASES[$column] ?? $column;

        if ($field === '.*' || !array_key_exists($field, $this->schemaFields)) {
            return null;
        }

        return $field;
    }

    private function renderValue(string $field, mixed $value): ?string
    {
        $type = $this->schemaFields[$field] ?? 'string';

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return json_encode($value);
        }

        if ($value === null || is_array($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DATE_ATOM);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($this->isNumericType($type)) {
            if (is_numeric($value)) {
                return $value;
            }

            // date filters (aired.from/published.to arrive as ISO 8601 strings) are indexed as
            // unix timestamps, so they have to be converted before comparing
            $timestamp = strtotime($value);

            return $timestamp === false ? null : (string) $timestamp;
        }

        if ($type === 'bool') {
            $normalized = strtolower($value);

            if (in_array($normalized, ['1', 'true'], true)) {
                return 'true';
            }

            if (in_array($normalized, ['0', 'false'], true)) {
                return 'false';
            }

            return null;
        }

        if (preg_match(self::UNSAFE_VALUE_PATTERN, $value) === 1) {
            return null;
        }

        return '"' . $value . '"';
    }

    private function isNumericType(string $type): bool
    {
        return in_array($type, ['float', 'double'], true) || str_contains($type, 'int');
    }
}
