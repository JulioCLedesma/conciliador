<?php

namespace App\Http\Controllers;

use App\Services\CatalogConfiguration;
use App\Services\CatalogWorkbook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogDownloadController extends Controller
{
    public function export(int $sessionId, string $kind, CatalogWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('exportar-catalogos');
        $session = DB::table('reconciliation_sessions')->where('id', $sessionId)->firstOrFail();
        abort_unless(in_array($kind, ['fifty', 'pemex', 'report'], true), 404);
        $book = DB::transaction(fn () => $kind === 'report' ? $workbook->report($sessionId) : $workbook->export($sessionId, $kind, Gate::allows('ver-importes')));
        $name = CatalogConfiguration::downloadName($session, $kind);

        return response()->streamDownload(function () use ($book) {
            try {
                $writer = new Xlsx($book);
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }

    public function original(int $catalogId): StreamedResponse
    {
        Gate::authorize('importar-catalogos');
        $catalog = DB::table('catalogs')->where('id', $catalogId)->firstOrFail();

        return Storage::disk('local')->download($catalog->path, $catalog->filename);
    }
}
