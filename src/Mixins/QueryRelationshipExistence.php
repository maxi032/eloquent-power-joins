<?php

namespace Kirschbaum\EloquentPowerJoins\Mixins;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\Relation;

class QueryRelationshipExistence
{
    public function getGroupBy()
    {
        return function () {
            return $this->getQuery()->getGroupBy();
        };
    }

    public function getSelect()
    {
        return function () {
            return $this->getQuery()->getSelect();
        };
    }

    protected function getRelationWithoutConstraintsProxy()
    {
        return function ($relation) {
            return Relation::noConstraints(function () use ($relation) {
                return $this->getModel()->{$relation}();
            });
        };
    }
}
