<?php

namespace Fico7489\Laravel\SortJoin\Tests\Models;

use Fico7489\Laravel\SortJoin\SortJoinTrait;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{
	use SortJoinTrait;
}
