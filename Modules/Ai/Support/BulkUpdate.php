<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sets different values on many rows in ONE statement (UPDATE ... SET col = CASE id WHEN ...), so a
 * loop over questions never becomes a loop of queries.
 */
final class BulkUpdate
{
    /**
     * @param  array<int, array<string, scalar|null>>  $rows  id => [column => value]; every row has the same columns
     */
    public static function byId(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $id = $grammar->wrap('id');
        $sets = [];
        $bindings = [];
        foreach (array_keys(reset($rows)) as $column) {
            $wrapped = $grammar->wrap($column);
            $case = "{$wrapped} = CASE {$id}";
            foreach ($rows as $key => $values) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = (int) $key;
                $bindings[] = $values[$column];
            }
            $sets[] = $case." ELSE {$wrapped} END";
        }
        $ids = array_map('intval', array_keys($rows));
        $sql = 'UPDATE '.$grammar->wrapTable($table).' SET '.implode(', ', $sets).', '.$grammar->wrap('updated_at').' = ? WHERE '
            .$id.' IN ('.implode(',', array_fill(0, count($ids), '?')).')';

        return DB::update($sql, [...$bindings, now(), ...$ids]);
    }
}
