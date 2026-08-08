<?php
declare(strict_types=1);

/**
 * CSV export, and nothing else.
 *
 * The rest of this file drew the engine's own entry screens and went with them.
 * Export stayed because it has no equivalent anywhere else yet - a real feature
 * with a real user rather than a leftover.
 */

/**
 * The columns of the CSV, in order, mapping header text to how the value is
 * produced. One list, used by both export and import, so a round trip through
 * a spreadsheet lines up rather than nearly lining up.
 */
function csv_columns(): array
{
    return [
        'ID'             => 'id',
        'Library'        => 'library_name',
        'Platform'       => 'platform_name',
        'Type'           => 'category_name',

        'Title'          => 'title',
        'Subtitle'       => 'subtitle',
        'Sort title'     => 'sort_title',
        'Developer'      => 'developer_name',
        'Publisher'      => 'publisher_name',
        'Year'           => 'release_year',
        'Release date'   => 'release_date',
        'Rating'         => 'rating',
        'Condition'      => 'condition_grade',
        'Completeness'   => 'completeness',
        'Media'          => 'media_type',
        'Media count'    => 'media_count',
        'Catalog no'     => 'catalog_number',
        'Barcode'        => 'barcode',
        'Language'       => 'language',
        'Region'         => 'region',
        'Acquired'       => 'acquired_on',
        'Price'          => 'acquired_price',
        'Currency'       => 'currency',
        'Current value'  => 'current_value',
        'Copies'         => 'copies',
        'Status'         => 'status',
        'Sold on'        => 'sold_on',
        'Sold for'       => 'sold_price',
        'Sold currency'  => 'sold_currency',
        'Has box'        => 'has_box',
        'Box'            => 'condition_box',
        'Manual'         => 'condition_manual',
        'Media condition' => 'condition_media',
        'Location'       => 'location_name',
        'Original'       => 'is_original',
        'Photos'         => 'image_count',
        'Reference URL'  => 'external_url',
        'Notes'          => 'notes',
    ];
}

/**
 * Neutralise a cell that a spreadsheet would treat as a formula.
 *
 * Excel and LibreOffice execute anything beginning =, +, -, @, tab or CR. Retro
 * titles legitimately start with '+' - the cracking scene is full of them - so
 * this is not a theoretical concern, and a leading apostrophe is the standard
 * way to say "this is text".
 */
function csv_safe($value): string
{
    $s = (string) $value;
    if ($s !== '' && str_contains("=+-@\t\r", $s[0])) {
        return "'" . $s;
    }
    return $s;
}

function items_export_csv(): void
{
    [$where, $params] = build_item_filters($_GET);
    $columns = csv_columns();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="retrohive-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, array_keys($columns));

    // Streamed a page at a time rather than loaded whole. all() on an
    // unfiltered export means the entire collection in memory at once, which is
    // fine at a thousand entries and not at fifty thousand.
    $offset = 0;
    $chunk  = 500;
    do {
        $rows = all(
            "SELECT * FROM v_items WHERE $where
             ORDER BY library_name, platform_name, COALESCE(sort_title, title)
             LIMIT $chunk OFFSET $offset",
            $params
        );
        foreach ($rows as $r) {
            $line = [];
            foreach ($columns as $field) {
                $value = $r[$field] ?? null;
                $value = match ($field) {
                    'condition_grade'  => condition_label($r['condition_grade']),
                    'completeness'     => completeness_label($r['completeness']),
                    'status'           => status_label($r['status']),
                    'condition_box',
                    'condition_manual',
                    'condition_media'  => condition_label($value),
                    'is_original'      => $value ? 'yes' : 'no',
                    default            => $value,
                };
                $line[] = csv_safe($value);
            }
            fputcsv($out, $line);
        }
        $offset += $chunk;
        flush();
    } while (count($rows) === $chunk);

    fclose($out);
    exit;
}
