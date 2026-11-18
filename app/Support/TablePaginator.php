<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

class TablePaginator extends LengthAwarePaginator
{
    public array $tableSettings = [];
}
