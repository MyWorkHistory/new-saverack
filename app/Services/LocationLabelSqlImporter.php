<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports phpMyAdmin-style INSERT dumps for beta_locations into the CRM DB.
 */
class LocationLabelSqlImporter
{
    /**
     * @return array{imported:int, skipped:int}
     */
    public function importFromDump(string $path, int $chunkSize = 250): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Location labels SQL dump not found or unreadable: '.$path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open location labels SQL dump.');
        }

        $imported = 0;
        $skipped = 0;
        $buffer = '';
        $inInsert = false;
        $chunk = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);
                if (! $inInsert) {
                    if (stripos($trimmed, 'INSERT INTO `beta_locations`') === 0
                        || stripos($trimmed, 'INSERT INTO beta_locations') === 0) {
                        $inInsert = true;
                        $buffer = $line;
                        if (strpos(rtrim($line), ';') !== false) {
                            $result = $this->flushInsert($buffer, $chunk, $chunkSize);
                            $imported += $result['imported'];
                            $skipped += $result['skipped'];
                            $chunk = $result['chunk'];
                            $buffer = '';
                            $inInsert = false;
                        }
                    }
                    continue;
                }

                $buffer .= $line;
                if (substr(rtrim($line), -1) === ';') {
                    $result = $this->flushInsert($buffer, $chunk, $chunkSize);
                    $imported += $result['imported'];
                    $skipped += $result['skipped'];
                    $chunk = $result['chunk'];
                    $buffer = '';
                    $inInsert = false;
                }
            }

            if ($chunk !== []) {
                $this->insertChunk($chunk);
                $imported += count($chunk);
            }
        } finally {
            fclose($handle);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @return array{imported:int, skipped:int, chunk:list<array<string, mixed>>}
     */
    private function flushInsert(string $statement, array $chunk, int $chunkSize): array
    {
        $imported = 0;
        $skipped = 0;
        foreach ($this->parseRows($statement) as $row) {
            if ($row === null) {
                $skipped++;
                continue;
            }
            $chunk[] = $row;
            if (count($chunk) >= $chunkSize) {
                $this->insertChunk($chunk);
                $imported += count($chunk);
                $chunk = [];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'chunk' => $chunk,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunk(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('beta_locations')->insert($rows);
    }

    /**
     * @return \Generator<int, array<string, mixed>|null>
     */
    private function parseRows(string $statement): \Generator
    {
        $pos = stripos($statement, 'VALUES');
        if ($pos === false) {
            return;
        }

        $valuesPart = trim(substr($statement, $pos + 6));
        $valuesPart = rtrim($valuesPart, " \t\n\r\0\x0B;");
        $len = strlen($valuesPart);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && (ctype_space($valuesPart[$i]) || $valuesPart[$i] === ',')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($valuesPart[$i] !== '(') {
                break;
            }
            $i++;
            $fields = [];
            $current = '';
            $inString = false;
            $stringQuote = '';

            while ($i < $len) {
                $ch = $valuesPart[$i];

                if ($inString) {
                    if ($ch === '\\' && $i + 1 < $len) {
                        $next = $valuesPart[$i + 1];
                        if ($next === 'n') {
                            $current .= "\n";
                        } elseif ($next === 'r') {
                            $current .= "\r";
                        } elseif ($next === 't') {
                            $current .= "\t";
                        } elseif ($next === '0') {
                            $current .= "\0";
                        } else {
                            $current .= $next;
                        }
                        $i += 2;
                        continue;
                    }
                    if ($ch === $stringQuote) {
                        if ($i + 1 < $len && $valuesPart[$i + 1] === $stringQuote) {
                            $current .= $stringQuote;
                            $i += 2;
                            continue;
                        }
                        $inString = false;
                        $i++;
                        continue;
                    }
                    $current .= $ch;
                    $i++;
                    continue;
                }

                if ($ch === "'" || $ch === '"') {
                    $inString = true;
                    $stringQuote = $ch;
                    $i++;
                    continue;
                }

                if ($ch === ',') {
                    $fields[] = $this->castSqlValue($current);
                    $current = '';
                    $i++;
                    continue;
                }

                if ($ch === ')') {
                    $fields[] = $this->castSqlValue($current);
                    $i++;
                    break;
                }

                $current .= $ch;
                $i++;
            }

            if (count($fields) < 6) {
                yield null;
                continue;
            }

            yield [
                'id' => (int) $fields[0],
                'location' => $fields[1],
                'type' => $fields[2],
                'label' => $fields[3],
                'is_deleted' => (int) ((bool) $fields[4]),
                'created_at' => $fields[5],
                'updated_at' => $fields[6] ?? $fields[5],
            ];
        }
    }

    /**
     * @param  mixed  $raw
     * @return mixed
     */
    private function castSqlValue($raw)
    {
        $value = is_string($raw) ? trim($raw) : $raw;
        if (! is_string($value)) {
            return $value;
        }
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        return $value;
    }
}
