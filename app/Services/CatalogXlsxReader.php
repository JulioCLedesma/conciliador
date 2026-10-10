<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx\Namespaces;
use ZipArchive;

/**
 * Omits header/footer graphics during reading, preserving cell styles and the source archive.
 * PhpSpreadsheet 5.10 assumes VML header images always contain margin-top.
 */
class CatalogXlsxReader extends Xlsx
{
    protected function getFromZipArchive(ZipArchive $archive, string $fileName = ''): string
    {
        $contents = parent::getFromZipArchive($archive, $fileName);
        if (! str_ends_with(strtolower($fileName), '.xml') || ! str_contains($contents, 'legacyDrawingHF')) {
            return $contents;
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        if (! $document->loadXML($this->getSecurityScannerOrThrow()->scan($contents), LIBXML_NONET)) {
            throw new Exception('Invalid worksheet XML.');
        }
        $root = $document->documentElement;
        if ($root?->localName !== 'worksheet' || ! in_array($root->namespaceURI, [Namespaces::MAIN, Namespaces::PURL_MAIN], true)) {
            return $contents;
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('sheet', $root->namespaceURI);
        $drawings = $xpath->query('/sheet:worksheet/sheet:legacyDrawingHF');
        if ($drawings === false || $drawings->length === 0) {
            return $contents;
        }
        foreach ($drawings as $drawing) {
            $drawing->parentNode->removeChild($drawing);
        }

        return $document->saveXML() ?: throw new Exception('Cannot read worksheet XML.');
    }
}
