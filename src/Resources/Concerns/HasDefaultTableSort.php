<?php

namespace CodeWithDiki\PaymentModule\Resources\Concerns;

use Filament\Tables\Table;

/**
 * Newest records first on every table in this package.
 *
 * Filament's Table::configureUsing() would leak this default into the host application's
 * own tables, so each table opts in instead. Override $defaultSortColumn where a table
 * should order by something other than its creation time.
 */
trait HasDefaultTableSort
{
    protected static string $defaultSortColumn = 'created_at';

    protected static function withDefaultSort(Table $table): Table
    {
        return $table->defaultSort(static::$defaultSortColumn, 'desc');
    }
}
