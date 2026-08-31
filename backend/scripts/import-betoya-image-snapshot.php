<?php

declare(strict_types=1);

/**
 * Convert the image-only MySQL export into the committed Betoya files fixture.
 *
 * Usage:
 *   php scripts/import-betoya-image-snapshot.php /path/to/images-staging.sql /path/to/db-images/mapping.json
 */
$source = $argv[1] ?? null;
if (! is_string($source) || ! is_file($source)) {
    fwrite(STDERR, "Pass the extracted images-staging.sql path.\n");
    exit(1);
}

$mappingPath = $argv[2] ?? null;
if (! is_string($mappingPath) || ! is_file($mappingPath)) {
    fwrite(STDERR, "Pass the db-images/mapping.json path as the second argument.\n");
    exit(1);
}

$destination = dirname(__DIR__).'/database/seeders/fixtures/catalog/files.json';
$rows = [];
$handle = fopen($source, 'rb');
if ($handle === false) {
    throw new RuntimeException("Cannot read {$source}");
}

while (($line = fgets($handle)) !== false) {
    if (! preg_match('/^REPLACE INTO `files` \((.+)\) VALUES \((.*)\);$/', trim($line), $matches)) {
        continue;
    }

    preg_match_all('/`([^`]+)`/', $matches[1], $columnMatches);
    $values = parseSqlValues($matches[2]);
    $row = array_combine($columnMatches[1], $values);
    if ($row === false) {
        throw new RuntimeException('Column/value count mismatch in image export.');
    }

    foreach (['size', 'sort_order'] as $integerColumn) {
        if ($row[$integerColumn] !== null) {
            $row[$integerColumn] = (int) $row[$integerColumn];
        }
    }
    $rows[] = $row;
}
fclose($handle);

if (count($rows) !== 1299) {
    throw new RuntimeException('Expected 1299 file rows, parsed '.count($rows).'.');
}

$mapping = json_decode(file_get_contents($mappingPath), true, flags: JSON_THROW_ON_ERROR);
$records = $mapping['records'] ?? null;
if (! is_array($records)) {
    throw new RuntimeException('Media mapping has no records array.');
}

$bundleRoot = dirname($mappingPath);
$liveFileIds = [];
$branchMedia = [];
$media = [];
foreach ($records as $record) {
    if (! is_array($record)) {
        continue;
    }

    $localFile = (string) ($record['local_file'] ?? '');
    $hasBinary = $localFile !== '' && is_file($bundleRoot.'/'.$localFile);
    $objectKey = ltrim((string) ($record['object_key'] ?? ''), '/');
    $isSyntheticGalleryFixture = str_starts_with($objectKey, 'gallery-fixtures/');
    if (($record['db_table'] ?? null) === 'files'
        && ($record['file_status'] ?? null) === 'ok'
        && $hasBinary
        && ! $isSyntheticGalleryFixture) {
        $liveFileIds[(string) $record['db_id']] = true;
        if ($objectKey !== '') {
            $media[$objectKey] = [
                'path' => $objectKey,
                'size' => filesize($bundleRoot.'/'.$localFile),
                'sha256' => hash_file('sha256', $bundleRoot.'/'.$localFile),
            ];
        }
    }

    if (($record['db_table'] ?? null) !== 'branches') {
        continue;
    }

    $column = (string) ($record['db_column'] ?? '');
    if (! in_array($column, ['logo', 'img_branches', 'banner_desktop', 'banner_tablet', 'banner_mobile'], true)) {
        continue;
    }

    // Production seed data must only reference the verified media bundle.
    // External URLs in the old snapshot are placeholders and are deliberately
    // omitted rather than reintroducing synthetic branch imagery.
    $value = null;
    if (($record['file_status'] ?? null) === 'ok' && $hasBinary) {
        $value = '/storage/'.ltrim((string) $record['object_key'], '/');
    }

    if ($value !== null) {
        $sourceBranchId = (string) $record['db_id'];
        $branchMedia[$sourceBranchId] ??= ['source_branch_id' => $sourceBranchId];
        $branchMedia[$sourceBranchId][$column] = $value;
    }

    if (($record['file_status'] ?? null) === 'ok' && $hasBinary && $objectKey !== '') {
        $media[$objectKey] = [
            'path' => $objectKey,
            'size' => filesize($bundleRoot.'/'.$localFile),
            'sha256' => hash_file('sha256', $bundleRoot.'/'.$localFile),
        ];
    }
}

$allRows = $rows;
$rows = array_values(array_filter(
    $rows,
    fn (array $row): bool => isset($liveFileIds[(string) $row['id']]),
));
if (count($rows) !== 305) {
    throw new RuntimeException('Expected 305 real file rows with binaries, found '.count($rows).'.');
}

$prunedIds = array_values(array_map(
    fn (array $row): string => (string) $row['id'],
    array_filter($allRows, fn (array $row): bool => ! isset($liveFileIds[(string) $row['id']])),
));

file_put_contents(
    $destination,
    json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
);

$branchDestination = dirname($destination).'/branch_media.json';
file_put_contents(
    $branchDestination,
    json_encode(array_values($branchMedia), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
);

$pruneDestination = dirname($destination).'/files_prune.json';
file_put_contents(
    $pruneDestination,
    json_encode($prunedIds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

ksort($media);
$mediaDestination = dirname($destination).'/media_manifest.json';
file_put_contents(
    $mediaDestination,
    json_encode(array_values($media), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

$manifestPath = dirname($destination).'/manifest.json';
$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$manifest['counts']['files'] = count($rows);
file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
);

fwrite(STDOUT, sprintf(
    "Wrote %d real files, %d pruned IDs, %d branch media mappings and %d media objects.\n",
    count($rows),
    count($prunedIds),
    count($branchMedia),
    count($media),
));

/** @return list<mixed> */
function parseSqlValues(string $input): array
{
    $values = [];
    $length = strlen($input);
    $offset = 0;

    while ($offset < $length) {
        while ($offset < $length && ctype_space($input[$offset])) {
            $offset++;
        }

        if (($input[$offset] ?? '') === "'") {
            $offset++;
            $value = '';
            while ($offset < $length) {
                $char = $input[$offset++];
                if ($char === '\\' && $offset < $length) {
                    $escaped = $input[$offset++];
                    $value .= match ($escaped) {
                        'n' => "\n", 'r' => "\r", 't' => "\t",
                        default => $escaped,
                    };

                    continue;
                }
                if ($char === "'") {
                    if (($input[$offset] ?? '') === "'") {
                        $value .= "'";
                        $offset++;

                        continue;
                    }
                    break;
                }
                $value .= $char;
            }
            $values[] = $value;
        } else {
            $start = $offset;
            while ($offset < $length && $input[$offset] !== ',') {
                $offset++;
            }
            $raw = trim(substr($input, $start, $offset - $start));
            $values[] = strtoupper($raw) === 'NULL' ? null : $raw;
        }

        while ($offset < $length && ($input[$offset] === ',' || ctype_space($input[$offset]))) {
            $offset++;
        }
    }

    return $values;
}
