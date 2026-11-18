<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TablePagination
{
    public const SIZES = [10, 20, 50, 100, 200];

    public static function settings(string $table): array
    {
        $input = request()->query('tables', []);
        $input = is_array($input) && is_array($input[$table] ?? null) ? $input[$table] : [];
        $size = filter_var($input['size'] ?? 10, FILTER_VALIDATE_INT);
        $filters = [];
        foreach (is_array($input['filters'] ?? null) ? $input['filters'] : [] as $column => $value) {
            if (ctype_digit((string) $column) && (int) $column < 50 && is_string($value) && trim($value) !== '') {
                $filters[(int) $column] = mb_substr(trim($value), 0, 100);
            }
        }

        $sort = null;
        if (is_array($input['sort'] ?? null)) {
            $column = filter_var($input['sort']['column'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 49]]);
            $direction = $input['sort']['direction'] ?? null;
            if ($column !== false && in_array($direction, ['asc', 'desc'], true)) {
                $sort = ['column' => $column, 'direction' => $direction];
            }
        }

        return ['id' => $table, 'size' => in_array($size, self::SIZES, true) ? $size : 10, 'filters' => $filters, 'sort' => $sort];
    }

    public static function query(Builder $query, string $table, string $pageName = 'page', ?callable $transform = null): LengthAwarePaginator
    {
        $settings = self::settings($table);
        if ($settings['filters'] === [] && $settings['sort'] === null) {
            $paginator = $query->paginate($settings['size'], ['*'], $pageName)->withQueryString();
            if ($transform) {
                $paginator->setCollection($paginator->getCollection()->map($transform));
            }

            return self::describe($paginator, $settings);
        }

        // Evaluate displayed labels as well as stored fields. Lazy batches retain eager
        // loading while searching and sorting beyond the current page.
        $rows = $query->lazy(200);

        return self::paginate($transform ? $rows->map($transform) : $rows, $table, $pageName, $settings);
    }

    public static function collection(Collection $rows, string $table, string $pageName = 'page', ?callable $columns = null): LengthAwarePaginator
    {
        return self::paginate($rows, $table, $pageName, self::settings($table), $columns);
    }

    private static function paginate(iterable $rows, string $table, string $pageName, array $settings, ?callable $columns = null): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);
        $offset = ($page - 1) * $settings['size'];
        $matches = 0;
        $items = [];
        $sorted = [];
        foreach ($rows as $row) {
            if ($settings['filters'] !== [] || $settings['sort'] !== null) {
                $values = $columns ? $columns($row) : TableColumns::values($table, $row);
                foreach ($settings['filters'] as $index => $needle) {
                    // Action columns are deliberately not searchable.
                    if (array_key_exists($index, $values) && ! str_contains(self::normalize($values[$index]), self::normalize($needle))) {
                        continue 2;
                    }
                }
            }
            if ($settings['sort'] !== null) {
                $sorted[] = ['row' => $row, 'value' => $values[$settings['sort']['column']] ?? null, 'position' => $matches];
            } elseif ($matches >= $offset && count($items) < $settings['size']) {
                $items[] = $row;
            }
            $matches++;
        }

        if ($settings['sort'] !== null) {
            usort($sorted, fn ($a, $b) => TableSort::compare($a['value'], $b['value'], $settings['sort']['direction']) ?: $a['position'] <=> $b['position']);
            $items = array_column(array_slice($sorted, $offset, $settings['size']), 'row');
        }

        return self::describe((new LengthAwarePaginator($items, $matches, $settings['size'], $page, [
            'path' => request()->url(), 'pageName' => $pageName,
        ]))->withQueryString(), $settings);
    }

    private static function normalize(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private static function describe(LengthAwarePaginator $paginator, array $settings): LengthAwarePaginator
    {
        // Public metadata is consumed by the shared Blade marker and JS controls.
        $paginator = new TablePaginator($paginator->getCollection(), $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->getOptions());
        $paginator->withQueryString();
        $paginator->tableSettings = $settings;

        return $paginator;
    }
}
