<?php

namespace Fico7489\Laravel\EloquentJoin;

use Fico7489\Laravel\EloquentJoin\Exceptions\InvalidRelationClause;
use Fico7489\Laravel\EloquentJoin\Exceptions\InvalidRelationScope;
use Illuminate\Database\Eloquent\Builder;
use Fico7489\Laravel\EloquentJoin\Relations\BelongsToJoin;
use Fico7489\Laravel\EloquentJoin\Exceptions\EloquentJoinException;
use Fico7489\Laravel\EloquentJoin\Relations\HasOneJoin;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EloquentJoinBuilder extends Builder
{
    //use table alias for join (real table name or uniqid())
    private $useTableAlias = false;

    //store if ->select(...) is already called on builder (we want only one select)
    private $selected = false;

    //store joined tables, we want join table only once (e.g. when you call orderByJoin more time)
    private $joinedTables = [];

    //store not allowed clauses on join relations for throw exception (e.g. whereHas, orderBy etc.)
    public $relationNotAllowedClauses = [];

    //store where clauses which we will use for join
    public $relationWhereClauses = [];

    //store where clauses which we will use for join
    public $relationClauses = [];

    public function whereJoin($column, $operator = null, $value = null, $boolean = 'and')
    {
        $column = $this->performJoin($column);

        return $this->where($column, $operator, $value, $boolean);
    }

    public function orWhereJoin($column, $operator = null, $value = null)
    {
        $column = $this->performJoin($column);

        return $this->orWhere($column, $operator, $value);
    }

    public function orderByJoin($column, $sortBy = 'asc')
    {
        $column = $this->performJoin($column);

        return $this->orderBy($column, $sortBy);
    }

    private function performJoin($relations)
    {
        $relations = explode('.', $relations);

        $column    = end($relations);
        $baseModel = $this->getModel();
        $baseTable = $baseModel->getTable();

        $currentModel      = $baseModel;
        $currentPrimaryKey = $baseModel->getKeyName();
        $currentTable      = $baseModel->getTable();

        $relationAccumulated      = [];
        $relationAccumulatedAlias = [];

        foreach ($relations as $relation) {
            if ($relation == $column) {
                //last item in $relations argument is sort|where column
                break;
            }

            /** @var Relation $relatedRelation */
            $relatedRelation   = $currentModel->$relation();

            $relatedModel      = $relatedRelation->getRelated();
            $relatedPrimaryKey = $relatedModel->getKeyName();
            $relatedTable      = $relatedModel->getTable();

            if (array_key_exists($relation, $this->joinedTables)) {
                $relatedTableAlias = $this->useTableAlias ? uniqid() : $relation;
            } else {
                $relatedTableAlias = $this->useTableAlias ? uniqid() : $relatedTable;
            }

            $relationAccumulated[]      = $relatedTable;
            $relationAccumulatedAlias[] = $relatedTableAlias;

            $relationAccumulatedAliasString = implode('.', $relationAccumulatedAlias);
            if ( ! array_key_exists($relationAccumulatedAliasString, $this->joinedTables)) {
                $relatedTableAlias = $this->useTableAlias ? uniqid() : $relatedTable;

                $joinQuery = $relatedTable . ($this->useTableAlias ? ' as ' . $relatedTableAlias : '');
                if ($relatedRelation instanceof BelongsToJoin) {
                    $keyRelated = $relatedRelation->getForeignKey();

                    $this->leftJoin($joinQuery, function ($join) use ($relatedTableAlias, $keyRelated, $currentTable, $relatedPrimaryKey, $relatedModel, $relatedRelation) {
                        $join->on($relatedTableAlias . '.' . $relatedPrimaryKey, '=', $currentTable . '.' . $keyRelated);

                        $this->leftJoinQuery($join, $relatedRelation, $relatedTableAlias);
                    });
                } elseif ($relatedRelation instanceof HasOneJoin) {
                    $keyRelated = $relatedRelation->getQualifiedForeignKeyName();
                    $keyRelated = last(explode('.', $keyRelated));

                    $this->leftJoin($joinQuery, function ($join) use ($relatedTableAlias, $keyRelated, $currentTable, $relatedPrimaryKey, $relatedModel, $currentPrimaryKey, $relatedRelation) {
                        $join->on($relatedTableAlias . '.' . $keyRelated, '=', $currentTable . '.' . $currentPrimaryKey);

                        $this->leftJoinQuery($join, $relatedRelation, $relatedTableAlias);
                    });
                } else {
                    throw new EloquentJoinException('Only allowed relations for whereJoin, orWhereJoin and orderByJoin are BelongsToJoin, HasOneJoin');
                }
            }

            $currentModel      = $relatedModel;
            $currentPrimaryKey = $relatedPrimaryKey;
            $currentTable      = $relatedTableAlias;

            $this->joinedTables[implode('.', $relationAccumulatedAlias)] = implode('.', $relationAccumulated);
        }

        if (! $this->selected  &&  count($relations) > 1) {
            $this->selected = true;
            $this->select($baseTable . '.*')->groupBy($baseTable . '.' . $baseModel->getKeyName());
        }

        return $currentTable . '.' . $column;
    }

    private function leftJoinQuery($join, $relation, $relatedTableAlias)
    {
        /** @var Builder $relationQuery */
        $relationBuilder = $relation->getQuery();

        foreach ($relationBuilder->getScopes() as $scope) {
            if($scope instanceof SoftDeletingScope){
                call_user_func_array([$join, 'where'], [$relatedTableAlias . '.deleted_at', '=', null]);
            }else{
                throw new InvalidRelationScope('Package allows only SoftDeletingScope scope .');
            }
        }

        foreach ($relationBuilder->relationClauses as $clause) {
            foreach ($clause as $method => $params) {
                if(in_array($method, ['where', 'orWhere'])){
                    if(is_array($params[0])){
                        foreach($params[0] as $k => $param){
                            $params[0][$relatedTableAlias . '.' . $k] = $param;
                            unset($params[0][$k]);
                        }
                    }else{
                        $params[0] = $relatedTableAlias . '.' . $params[0];
                    }

                    call_user_func_array([$join, $method], $params);
                }elseif(in_array($method, ['withoutTrashed', 'onlyTrashed', 'withTrashed'])){
                    if ($method == 'withTrashed') {
                        //do nothing
                    } elseif ($method == 'withoutTrashed') {
                        call_user_func_array([$join, 'where'], [$relatedTableAlias . '.deleted_at', '=', null]);
                    } elseif ($method == 'onlyTrashed') {
                        call_user_func_array([$join, 'where'], [$relatedTableAlias . '.deleted_at', '<>', null]);
                    }
                }else{
                    throw new InvalidRelationClause('Package allows only following clauses on relation : where, orWhere, withTrashed, onlyTrashed and withoutTrashed.');
                }
            }
        }
    }

    private function applyClauseOnRelation(){
        
    }

    /**
     * @return array
     */
    public function getScopes()
    {
        return $this->scopes;
    }
}
