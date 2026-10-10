<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CatalogWorkbook
{
    public function sheets(string $path): array
    {
        try {
            $this->validateArchive($path);
            $names = (new CatalogXlsxReader)->listWorksheetNames($path);
            if (! $names) {
                throw ValidationException::withMessages(['file' => 'El archivo Excel no contiene hojas legibles.']);
            }

            return $names;
        } catch (SpreadsheetException|\ErrorException|\DOMException|\ValueError $e) {
            throw $this->unreadableFile();
        }
    }

    private function validateArchive(string $path): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'El archivo no es un Excel .xlsx válido.']);
        }
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $size += $entry['size'];
            if (str_contains(strtolower($entry['name']), 'vbaproject')) {
                $zip->close();
                throw ValidationException::withMessages(['file' => 'No se admiten archivos con macros.']);
            }
        }
        $valid = $zip->locateName('xl/workbook.xml') !== false && $size <= 100 * 1024 * 1024;
        $zip->close();
        if (! $valid) {
            throw ValidationException::withMessages(['file' => 'Excel inválido o demasiado grande al descomprimir (máximo 100 MB).']);
        }
    }

    public function read(string $path, string $sheet, int $headerRow): array
    {
        try {
            return $this->readWorksheet($path, $sheet, $headerRow);
        } catch (SpreadsheetException|\ErrorException|\DOMException|\ValueError $e) {
            throw $this->unreadableFile();
        }
    }

    private function unreadableFile(): ValidationException
    {
        return ValidationException::withMessages(['file' => 'No se pudo leer el archivo Excel. Puede estar dañado, cifrado o contener elementos no válidos. Ábrelo en Excel o LibreOffice, guarda una copia como .xlsx y vuelve a cargarla.']);
    }

    private function readWorksheet(string $path, string $sheet, int $headerRow): array
    {
        $names = $this->sheets($path);
        if (! in_array($sheet, $names, true)) {
            throw ValidationException::withMessages(['sheet' => 'Selecciona una hoja disponible.']);
        }
        $reader = new CatalogXlsxReader;
        $info = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', $sheet);
        if (! $info) {
            throw $this->unreadableFile();
        }
        if ($info['totalColumns'] > 200 || $info['totalRows'] > 20000 || $info['totalRows'] * $info['totalColumns'] > 200000) {
            throw ValidationException::withMessages(['file' => 'La hoja supera el límite de 20,000 filas, 200 columnas o 200,000 celdas.']);
        }
        $reader->setLoadSheetsOnly([$sheet]);
        $book = $reader->load($path);
        try {
            $worksheet = $book->getSheetByName($sheet);
            if (! $worksheet) {
                throw $this->unreadableFile();
            }
            $width = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
            $height = $worksheet->getHighestDataRow();
            if ($width > 200 || $height > 20000 || $headerRow < 1 || $headerRow >= $height) {
                throw ValidationException::withMessages(['headerRow' => 'Selecciona un encabezado con datos debajo. Límite: 200 columnas y 20,000 filas.']);
            }
            $rows = [];
            $formulas = 0;
            for ($r = 1; $r <= $height; $r++) {
                $values = [];
                for ($c = 1; $c <= $width; $c++) {
                    $cell = $worksheet->getCell([$c, $r]);
                    $value = $cell->getValue();
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $formulas++;
                        $value = (string) $value;
                    } elseif ($cell->getDataType() === DataType::TYPE_NUMERIC && is_numeric($value)) {
                        $value = NumberFormat::toFormattedString($value, $cell->getStyle()->getNumberFormat()->getFormatCode());
                    } elseif ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    $values[] = (string) ($value ?? '');
                }
                $rows[$r] = $values;
            }

            return ['headers' => $rows[$headerRow], 'preamble' => array_values(array_slice($rows, 0, $headerRow - 1, true)), 'rows' => array_slice($rows, $headerRow, null, true), 'formulas' => $formulas];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function inspect(array $data, int $reference, array $descriptions): array
    {
        $groups = [];
        $empty = [];
        foreach ($data['rows'] as $number => $values) {
            $row = ['row' => $number, 'reference' => $values[$reference], 'description' => implode(' · ', array_map(fn ($column) => $values[$column], $descriptions))];
            if (trim($row['reference']) === '') {
                $empty[] = $row;
            } else {
                $groups[$row['reference']][] = $row;
            }
        }
        $duplicates = array_merge([], ...array_values(array_filter($groups, fn ($rows) => count($rows) > 1)));

        return ['count' => count($data['rows']), 'empty' => $empty, 'duplicates' => $duplicates, 'formulas' => $data['formulas']];
    }

    public function import(int $sessionId, string $side, string $path, string $filename, string $sheet, int $headerRow, int $reference, array $descriptions, array $sensitive, ?int $result, string $resultName, ?string $classificationText = null): int
    {
        $session = DB::table('reconciliation_sessions')->where('id', $sessionId)->firstOrFail();
        $classificationText ??= CatalogConfiguration::defaults($session, $side)['classification_text'];
        if (trim($classificationText) === '' || mb_strlen($classificationText) > 255) {
            throw ValidationException::withMessages(['classificationText' => 'Escribe un texto de clasificación de hasta 255 caracteres.']);
        }
        $data = $this->read(Storage::disk('local')->path($path), $sheet, $headerRow);
        $width = count($data['headers']);
        $columns = range(0, $width - 1);
        if (! in_array($side, ['fifty', 'pemex'], true) || ! in_array($reference, $columns, true) || ! $descriptions || array_diff(array_merge($descriptions, $sensitive), $columns) || ($result !== null && ! in_array($result, $columns, true))) {
            throw ValidationException::withMessages(['mapping' => 'La configuración de columnas no es válida.']);
        }
        if (array_intersect(array_merge([$reference], $descriptions, $result === null ? [] : [$result]), $sensitive) || ($result !== null && in_array($result, array_merge([$reference], $descriptions), true))) {
            throw ValidationException::withMessages(['mapping' => 'Referencia, descripciones y resultado deben ser columnas no sensibles; el resultado debe tener su propia columna.']);
        }

        return DB::transaction(function () use ($sessionId, $side, $path, $filename, $sheet, $headerRow, $reference, $descriptions, $sensitive, $result, $resultName, $classificationText, $data, $width) {
            DB::table('reconciliation_sessions')->where('id', $sessionId)->lockForUpdate()->firstOrFail();
            if (DB::table('catalogs')->where('session_id', $sessionId)->where('side', $side)->exists()) {
                throw ValidationException::withMessages(['file' => 'Este catálogo ya está importado. Crea otra sesión para cargar archivos nuevos.']);
            }
            $id = DB::table('catalogs')->insertGetId([
                'session_id' => $sessionId, 'side' => $side, 'filename' => $filename, 'path' => $path, 'sheet' => $sheet, 'header_row' => $headerRow,
                'headers' => json_encode($data['headers']), 'preamble' => json_encode($data['preamble']), 'reference_column' => $reference,
                'description_columns' => json_encode($descriptions), 'sensitive_columns' => json_encode($sensitive), 'result_column' => $result ?? $width,
                'result_name' => $resultName, 'classification_text' => $classificationText, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['rows'] as $number => $values) {
                DB::table('catalog_rows')->insert([
                    'catalog_id' => $id, 'session_id' => $sessionId, 'side' => $side, 'original_row' => $number, 'reference' => $values[$reference], 'values' => json_encode($values),
                    'description' => implode(' · ', array_map(fn ($column) => $values[$column], $descriptions)),
                ]);
            }

            return $id;
        });
    }

    public function safeRow(object $row, object $catalog, bool $withAmounts): array
    {
        $values = json_decode($row->values, true);
        $headers = json_decode($catalog->headers, true);
        $sensitive = json_decode($catalog->sensitive_columns, true);
        $details = [];
        foreach ($values as $column => $value) {
            if (! $withAmounts && in_array($column, $sensitive, true)) {
                continue;
            }
            if ($column === $catalog->result_column) {
                continue;
            }
            $details[] = ['label' => Coordinate::stringFromColumnIndex($column + 1).' · '.($headers[$column] ?: '(sin encabezado)'), 'value' => $value];
        }

        return ['id' => $row->id, 'reference' => $row->reference, 'description' => $row->description, 'original_row' => $row->original_row, 'status' => $row->status, 'version' => $row->version, 'details' => $details];
    }

    public function export(int $sessionId, string $side, bool $withAmounts): Spreadsheet
    {
        $catalog = DB::table('catalogs')->where('session_id', $sessionId)->where('side', $side)->firstOrFail();
        $headers = json_decode($catalog->headers, true);
        $headers[$catalog->result_column] = $catalog->result_name;
        $sensitive = json_decode($catalog->sensitive_columns, true);
        $columns = array_values(array_filter(array_keys($headers), fn ($column) => $withAmounts || ! in_array($column, $sensitive, true)));
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($catalog->sheet);
        if ($withAmounts) {
            foreach (json_decode($catalog->preamble, true) as $index => $values) {
                $this->writeRow($sheet, $index + 1, array_map(fn ($column) => $values[$column] ?? '', $columns));
            }
        }
        $this->writeRow($sheet, $catalog->header_row, array_map(fn ($column) => $headers[$column], $columns));
        $results = [];
        $links = DB::table('catalog_links')
            ->join('catalog_rows as fifty', 'fifty.id', '=', 'catalog_links.fifty_id')
            ->join('catalog_rows as pemex', 'pemex.id', '=', 'catalog_links.pemex_id')
            ->where('catalog_links.session_id', $sessionId)->orderBy('fifty.original_row')->orderBy('pemex.original_row')
            ->get(['fifty_id', 'pemex_id', 'fifty.reference as fifty_reference', 'pemex.reference as pemex_reference']);
        foreach ($links as $link) {
            if ($side === 'fifty') {
                $results[$link->fifty_id][] = $link->pemex_reference;
            } else {
                $results[$link->pemex_id][] = $link->fifty_reference;
            }
        }
        foreach (DB::table('catalog_rows')->where('catalog_id', $catalog->id)->orderBy('original_row')->get() as $row) {
            $values = json_decode($row->values, true);
            $values[$catalog->result_column] = $this->result($row, $side, $results, $catalog->classification_text);
            $this->writeRow($sheet, $row->original_row, array_map(fn ($column) => $values[$column] ?? '', $columns));
        }

        return $book;
    }

    private function result(object $row, string $side, array $results, string $classificationText): string
    {
        if (in_array($row->status, ['outside', 'new'], true)) {
            return $classificationText;
        }

        return implode(';', $results[$row->id] ?? []);
    }

    public function report(int $sessionId): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $catalogs = DB::table('catalogs')->where('session_id', $sessionId)->get();
        $session = DB::table('reconciliation_sessions')->where('id', $sessionId)->firstOrFail();
        $names = CatalogConfiguration::names($session);
        $entries = ['Referencias duplicadas' => [], 'Relaciones múltiples' => [], 'Múltiples desde izquierda' => [], 'Referencias vacías' => [], 'Pendientes' => []];
        foreach ($catalogs as $catalog) {
            $rows = DB::table('catalog_rows')->where('catalog_id', $catalog->id)->orderBy('original_row')->get();
            $counts = $rows->filter(fn ($row) => trim($row->reference) !== '')->countBy('reference');
            foreach ($rows as $row) {
                $entry = [$catalog->filename, $catalog->sheet, $row->original_row, $row->reference, $row->description];
                if (($counts[$row->reference] ?? 0) > 1) {
                    $entries['Referencias duplicadas'][] = array_merge($entry, [$names[$catalog->side]]);
                }
                if (trim($row->reference) === '') {
                    $entries['Referencias vacías'][] = array_merge($entry, [$names[$catalog->side]]);
                }
                if ($row->status === 'pending') {
                    $entries['Pendientes'][] = array_merge($entry, [$names[$catalog->side]]);
                }
                if ($catalog->side === 'fifty') {
                    $links = DB::table('catalog_links')->join('catalog_rows', 'catalog_rows.id', '=', 'catalog_links.pemex_id')->where('fifty_id', $row->id)->orderBy('original_row')->get();
                    if ($links->count() > 1) {
                        $right = $catalogs->firstWhere('side', 'pemex');
                        foreach ($links as $link) {
                            $entries['Múltiples desde izquierda'][] = array_merge($entry, [$right->filename, $right->sheet, $link->original_row, $link->reference, $link->description, 'Relación múltiple permitida', $names['fifty'], $names['pemex']]);
                        }
                    }
                }
                if ($catalog->side === 'pemex') {
                    $links = DB::table('catalog_links')->join('catalog_rows', 'catalog_rows.id', '=', 'catalog_links.fifty_id')->where('pemex_id', $row->id)->orderBy('original_row')->get();
                    if ($links->count() > 1) {
                        $fifty = $catalogs->firstWhere('side', 'fifty');
                        foreach ($links as $link) {
                            $entries['Relaciones múltiples'][] = array_merge($entry, [$fifty->filename, $fifty->sheet, $link->original_row, $link->reference, $link->description, 'Relación múltiple permitida', $names['pemex'], $names['fifty']]);
                        }
                    }
                }
            }
        }
        foreach ($entries as $title => $rows) {
            $sheet = new Worksheet($book, $title);
            $book->addSheet($sheet);
            $headers = ['Archivo', 'Hoja', 'Fila original', 'Referencia', 'Descripción'];
            if ($title === 'Relaciones múltiples') {
                $headers = array_merge($headers, ['Archivo '.$names['fifty'], 'Hoja '.$names['fifty'], 'Fila '.$names['fifty'], 'Referencia '.$names['fifty'], 'Descripción '.$names['fifty'], 'Nota', 'Catálogo derecho', 'Catálogo izquierdo']);
            }
            if ($title === 'Múltiples desde izquierda') {
                $headers = array_merge($headers, ['Archivo '.$names['pemex'], 'Hoja '.$names['pemex'], 'Fila '.$names['pemex'], 'Referencia '.$names['pemex'], 'Descripción '.$names['pemex'], 'Nota', 'Catálogo izquierdo', 'Catálogo derecho']);
            }
            if (! in_array($title, ['Relaciones múltiples', 'Múltiples desde izquierda'], true)) {
                $headers[] = 'Catálogo';
            }
            $this->writeRow($sheet, 1, $headers);
            foreach ($rows as $index => $values) {
                $this->writeRow($sheet, $index + 2, $values);
            }
        }

        return $book;
    }

    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            $sheet->setCellValueExplicit([$index + 1, $row], (string) $value, DataType::TYPE_STRING);
        }
    }
}
