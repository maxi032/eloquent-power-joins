<?php

namespace Kirschbaum\EloquentPowerJoins;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

trait PowerJoins
{
    /**
     * Cache to not join the same relationship twice.
     *
     * @var array
     */
    public static $joinRelationshipCache = [];

    /**
     * Cache to not join the same relationship twice.
     *
     * @var array
     */
    public static $powerJoinAliasesCache = [];

    /**
     * Join method map.
     */
    public static $joinMethodsMap = [
        'join' => 'powerJoin',
        'leftJoin' => 'leftPowerJoin',
        'rightJoin' => 'rightPowerJoin',
    ];

    /**
     * Join the relationship(s).
     */
    public function scopeJoinRelationship(Builder $query, $relationName, $callback = null, $joinType = 'join', $useAlias = false): void
    {
        $joinType = PowerJoins::$joinMethodsMap[$joinType] ?? $joinType;

        if (is_null($query->getSelect())) {
            $query->select(sprintf('%s.*', $query->getModel()->getTable()));
        }

        if (Str::contains($relationName, '.')) {
            $query->joinNestedRelationship($relationName, $callback, $joinType, $useAlias);
            return;
        }

        if ($this->relationshipAlreadyJoined($relationName)) {
            return;
        }

        $relation = $query->getModel()->{$relationName}();
        $alias = $useAlias ? $this->generateAliasForRelationship($relation, $relationName) : null;
        $relation->performJoinForEloquentPowerJoins($query, $joinType, $callback, $alias);

        $this->markRelationshipAsAlreadyJoined($relationName);
        $this->clearPowerJoinCaches();
    }

    /**
     * Join the relationship(s) using table aliases.
     */
    public function scopeJoinRelationshipUsingAlias(Builder $query, $relationName, $callback = null): void
    {
        $query->joinRelationship($relationName, $callback, 'join', true);
    }

    /**
     * Left join the relationship(s) using table aliases.
     */
    public function scopeLeftJoinRelationshipUsingAlias(Builder $query, $relationName, $callback = null): void
    {
        $query->joinRelationship($relationName, $callback, 'leftJoin', true);
    }

    /**
     * Right join the relationship(s) using table aliases.
     */
    public function scopeRightJoinRelationshipUsingAlias(Builder $query, $relationName, $callback = null)
    {
        $query->joinRelationship($relationName, $callback, 'rightJoin', true);
    }

    public function scopeJoinRelation(Builder $query, $relationName, $callback = null, $joinType = 'join'): void
    {
        $query->joinRelationship($relationName, $callback.$joinType);
    }

    public function scopeLeftJoinRelationship(Builder $query, $relation, $callback = null, $useAlias = false)
    {
        $query->joinRelationship($relation, $callback, 'leftJoin', $useAlias);
    }

    public function scopeLeftJoinRelation(Builder $query, $relation, $callback = null, $useAlias = false)
    {
        $query->joinRelationship($relation, $callback, 'leftJoin', $useAlias);
    }

    public function scopeRightJoinRelationship(Builder $query, $relation, $callback = null, $useAlias = false): void
    {
        $query->joinRelationship($relation, $callback, 'rightJoin', $useAlias);
    }

    public function scopeRightJoinRelation(Builder $query, $relation, $callback = null, $useAlias = false): void
    {
        $query->joinRelationship($relation, $callback, 'rightJoin', $useAlias);
    }

    /**
     * Join nested relationships.
     */
    public function scopeJoinNestedRelationship(Builder $query, $relations, $callback = null, $joinType = 'join', $useAlias = false): void
    {
        $relations = explode('.', $relations);

        /** @var \Illuminate\Database\Eloquent\Relations\Relation */
        $latestRelation = null;

        foreach ($relations as $index => $relationName) {
            $currentModel = $latestRelation ? $latestRelation->getModel() : $query->getModel();
            $relation = $currentModel->{$relationName}();
            $alias = $useAlias ? $this->generateAliasForRelationship($relation, $relationName) : null;
            $relationCallback = null;

            if ($useAlias) {
                $this->cachePowerJoinAlias($relation->getModel(), $alias);
            }

            if ($callback && is_array($callback) && isset($callback[$relationName])) {
                $relationCallback = $callback[$relationName];
            }

            if ($this->relationshipAlreadyJoined($relationName)) {
                $latestRelation = $relation;
                continue;
            }

            $relation->performJoinForEloquentPowerJoins(
                $query,
                $joinType,
                $relationCallback,
                $alias
            );

            $latestRelation = $relation;
            $this->markRelationshipAsAlreadyJoined($relationName);
        }

        $this->clearPowerJoinCaches();
    }

    /**
     * Order by a field in the defined relationship.
     */
    public function scopeOrderByUsingJoins(Builder $query, $sort, $direction = 'asc', $aggregation = null): void
    {
        $relationships = explode('.', $sort);
        $column = array_pop($relationships);
        $latestRelationshipName = $relationships[count($relationships) - 1];

        $query->joinRelationship(implode('.', $relationships));

        $latestRelationshipModel = array_reduce($relationships, function ($model, $relationshipName) {
            return $model->$relationshipName()->getModel();
        }, $query->getModel());

        if ($aggregation) {
            $query->selectRaw(
                sprintf(
                    '%s(%s.%s) as %s_aggregation',
                    $aggregation,
                    $latestRelationshipModel->getTable(),
                    $column,
                    $latestRelationshipName
                )
            )
                ->groupBy(sprintf('%s.%s', $this->getModel()->getTable(), $this->getModel()->getKeyName()))
                ->orderBy(sprintf('%s_aggregation', $latestRelationshipName), $direction);
        } else {
            $query->orderBy(sprintf('%s.%s', $latestRelationshipModel->getTable(), $column), $direction);
        }
    }

    /**
     * Order by the COUNT aggregation using joins.
     */
    public function scopeOrderByCountUsingJoins(Builder $query, $sort, $direction = 'asc'): void
    {
        $query->orderByUsingJoins($sort, $direction, 'COUNT');
    }

    /**
     * Order by the SUM aggregation using joins.
     */
    public function scopeOrderBySumUsingJoins(Builder $query, $sort, $direction = 'asc'): void
    {
        $query->orderByUsingJoins($sort, $direction, 'SUM');
    }

    /**
     * Order by the AVG aggregation using joins.
     */
    public function scopeOrderByAvgUsingJoins(Builder $query, $sort, $direction = 'asc'): void
    {
        $query->orderByUsingJoins($sort, $direction, 'AVG');
    }

    /**
     * Order by the MIN aggregation using joins.
     */
    public function scopeOrderByMinUsingJoins(Builder $query, $sort, $direction = 'asc'): void
    {
        $query->orderByUsingJoins($sort, $direction, 'MIN');
    }

    /**
     * Order by the MAX aggregation using joins.
     */
    public function scopeOrderByMaxUsingJoins(Builder $query, $sort, $direction = 'asc'): void
    {
        $query->orderByUsingJoins($sort, $direction, 'MAX');
    }

    /**
     * Same as Laravel 'has`, but using joins instead of where exists.
     */
    public function scopePowerJoinHas(Builder $query, $relation, $operator = '>=', $count = 1, $boolean = 'and', Closure $callback = null): void
    {
        if (is_null($query->getSelect())) {
            $query->select(sprintf('%s.*', $query->getModel()->getTable()));
        }

        if (is_null($query->getGroupBy())) {
            $query->groupBy($query->getModel()->getQualifiedKeyName());
        }

        if (is_string($relation)) {
            if (Str::contains($relation, '.')) {
                $query->hasNestedUsingJoins($relation, $operator, $count, 'and', $callback);
                return;
            }

            $relation = $query->getRelationWithoutConstraintsProxy($relation);
        }

        $relation->performJoinForEloquentPowerJoins($query, 'leftPowerJoin', $callback);
        $relation->performHavingForEloquentPowerJoins($query, $operator, $count);
    }

    public function scopeHasNestedUsingJoins(Builder $query, $relations, $operator = '>=', $count = 1, $boolean = 'and', Closure $callback = null)
    {
        $relations = explode('.', $relations);

        /** @var \Illuminate\Database\Eloquent\Relations\Relation */
        $latestRelation = null;

        foreach ($relations as $index => $relation) {
            if (! $latestRelation) {
                $relation = $query->getRelationWithoutConstraintsProxy($relation);
            } else {
                $relation = $latestRelation->getModel()->query()->getRelationWithoutConstraintsProxy($relation);
            }

            $relation->performJoinForEloquentPowerJoins($query, 'leftPowerJoin', $callback);

            if (count($relations) === ($index + 1)) {
                $relation->performHavingForEloquentPowerJoins($query, $operator, $count);
            }

            $latestRelation = $relation;
        }
    }

    public function scopePowerJoinDoesntHave(Builder $query, $relation, $boolean = 'and', Closure $callback = null): void
    {
        $query->powerJoinHas($relation, '<', 1, $boolean, $callback);
    }

    public function scopePowerJoinWhereHas(Builder $query, $relation, Closure $callback = null, $operator = '>=', $count = 1): void
    {
        $query->powerJoinHas($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Checks if the relationship was already joined.
     */
    public function relationshipAlreadyJoined($relation)
    {
        return isset(PowerJoins::$joinRelationshipCache[spl_object_id($this)][$relation]);
    }

    /**
     * Marks the relationship as already joined.
     */
    public function markRelationshipAsAlreadyJoined($relation)
    {
        PowerJoins::$joinRelationshipCache[spl_object_id($this)][$relation] = true;
    }

    public function generateAliasForRelationship($relation, $relationName)
    {
        if ($relation instanceof BelongsToMany || $relation instanceof HasManyThrough) {
            return [
                md5($relationName.'table1'.time()),
                md5($relationName.'table2'.time()),
            ];
        }

        return md5($relationName.time());
    }

    /**
     * Cache the power join table alias used for the power join.
     */
    public function cachePowerJoinAlias($model, $alias)
    {
        PowerJoins::$powerJoinAliasesCache[spl_object_id($model)] = $alias;
    }

    /**
     * Clear the power join caches.
     */
    public function clearPowerJoinCaches()
    {
        PowerJoins::$powerJoinAliasesCache = [];

        return $this;
    }
}
