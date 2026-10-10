<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReconciliationDecisions
{
    public function reviewLinks(int $sessionId, array $left, array $right): array
    {
        Gate::authorize('conciliar-catalogos');

        return DB::transaction(function () use ($sessionId, $left, $right) {
            $this->lockSession($sessionId);
            $this->validateSelection($sessionId, $left, 'fifty');
            $this->validateSelection($sessionId, $right, 'pemex');
            $existing = DB::table('catalog_links')->where('session_id', $sessionId)->whereIn('fifty_id', array_keys($left))->whereIn('pemex_id', array_keys($right))->count();

            return ['newCount' => count($left) * count($right) - $existing, 'existingCount' => $existing];
        });
    }

    public function linkMany(int $sessionId, array $left, array $right, bool $confirmed = false): int
    {
        Gate::authorize('conciliar-catalogos');
        if (! $confirmed) {
            $this->confirmationRequired('Revisa los productos y confirma la creación de equivalencias.');
        }

        return DB::transaction(function () use ($sessionId, $left, $right) {
            $this->lockSession($sessionId);
            $leftRows = $this->validateSelection($sessionId, $left, 'fifty');
            $rightRows = $this->validateSelection($sessionId, $right, 'pemex');
            $existing = DB::table('catalog_links')->where('session_id', $sessionId)->whereIn('fifty_id', array_keys($left))->whereIn('pemex_id', array_keys($right))
                ->get()->mapWithKeys(fn ($link) => [$link->fifty_id.':'.$link->pemex_id => true])->all();
            $newLinks = [];
            $affected = [];
            foreach ($leftRows as $leftRow) {
                foreach ($rightRows as $rightRow) {
                    if (! isset($existing[$leftRow->id.':'.$rightRow->id])) {
                        $newLinks[] = ['session_id' => $sessionId, 'fifty_id' => $leftRow->id, 'pemex_id' => $rightRow->id, 'created_at' => now(), 'updated_at' => now()];
                        $affected[$leftRow->id] = true;
                        $affected[$rightRow->id] = true;
                    }
                }
            }
            foreach (array_merge($leftRows, $rightRows) as $row) {
                if ($row->status !== 'linked') {
                    $affected[$row->id] = true;
                }
            }
            $ids = array_keys($affected);
            if (! $ids) {
                return 0;
            }
            $before = $this->snapshot($ids);
            foreach (array_chunk($newLinks, 500) as $chunk) {
                DB::table('catalog_links')->insert($chunk);
            }
            DB::table('catalog_rows')->whereIn('id', $ids)->update(['status' => 'linked']);
            DB::table('catalog_rows')->whereIn('id', $ids)->increment('version');
            $this->audit($sessionId, 'link_many', $before, $this->snapshot($ids));

            return count($newLinks);
        }, 3);
    }

    public function removeLink(int $sessionId, int $linkId, array $versions, bool $confirmed = false): void
    {
        Gate::authorize('conciliar-catalogos');
        if (! $confirmed) {
            $this->confirmationRequired('Confirma retirar únicamente esta equivalencia.');
        }
        DB::transaction(function () use ($sessionId, $linkId, $versions) {
            $this->lockSession($sessionId);
            $link = DB::table('catalog_links')->where('session_id', $sessionId)->where('id', $linkId)->first();
            if (! $link) {
                $this->conflict();
            }
            $ids = [$link->fifty_id, $link->pemex_id];
            foreach ($ids as $id) {
                $row = $this->row($sessionId, $id);
                $this->checkVersion($row, (int) ($versions[$id] ?? -1));
            }
            $before = $this->snapshot($ids);
            DB::table('catalog_links')->where('id', $linkId)->delete();
            $this->refreshStates($ids);
            DB::table('catalog_rows')->whereIn('id', $ids)->increment('version');
            $this->audit($sessionId, 'remove_link', $before, $this->snapshot($ids));
        }, 3);
    }

    public function decide(int $sessionId, string $action, int $rowId, int $version, ?int $pemexId = null, ?int $pemexVersion = null, bool $confirmed = false): void
    {
        Gate::authorize('conciliar-catalogos');
        if (! in_array($action, ['link', 'outside', 'new', 'undo'], true)) {
            $this->fail('Acción no válida.');
        }
        if ($action === 'link') {
            $this->linkMany($sessionId, [$rowId => $version], [$pemexId ?? 0 => $pemexVersion ?? -1], $confirmed);

            return;
        }
        DB::transaction(function () use ($sessionId, $action, $rowId, $version, $confirmed) {
            $this->lockSession($sessionId);
            $row = $this->row($sessionId, $rowId);
            $this->checkVersion($row, $version);
            if (($action === 'outside' && $row->side !== 'fifty') || ($action === 'new' && $row->side !== 'pemex')) {
                $this->fail('Las filas no corresponden al lado requerido.');
            }
            $links = DB::table('catalog_links')->where('session_id', $sessionId)->where($row->side.'_id', $rowId)->get();
            if (! $confirmed && ($row->status !== 'pending' || $links->isNotEmpty())) {
                $this->confirmationRequired($links->isNotEmpty()
                    ? 'Esta acción eliminará todas las equivalencias de este producto. Confirma explícitamente su eliminación.'
                    : 'Esta acción sustituye una clasificación existente. Confirma para continuar.');
            }
            $ids = array_values(array_unique(array_merge([$rowId], $links->pluck('fifty_id')->all(), $links->pluck('pemex_id')->all())));
            $before = $this->snapshot($ids);
            DB::table('catalog_links')->where('session_id', $sessionId)->where($row->side.'_id', $rowId)->delete();
            $this->refreshStates(array_values(array_diff($ids, [$rowId])));
            DB::table('catalog_rows')->where('id', $rowId)->update(['status' => match ($action) {
                'outside' => 'outside', 'new' => 'new', default => 'pending',
            }]);
            DB::table('catalog_rows')->whereIn('id', $ids)->increment('version');
            $this->audit($sessionId, $action, $before, $this->snapshot($ids));
        }, 3);
    }

    private function validateSelection(int $sessionId, array $selection, string $side): array
    {
        if (! $selection) {
            $this->fail('Selecciona al menos un producto en cada catálogo.');
        }
        $rows = DB::table('catalog_rows')->where('session_id', $sessionId)->where('side', $side)->whereIn('id', array_keys($selection))->get();
        if ($rows->count() !== count($selection)) {
            $this->fail('Una fila seleccionada no pertenece a esta sesión o al lado correcto.');
        }
        foreach ($rows as $row) {
            $this->checkVersion($row, (int) $selection[$row->id]);
            if (trim($row->reference) === '') {
                $this->fail('No se puede relacionar: falta una referencia. Puedes clasificar la fila manualmente.');
            }
        }

        return $rows->all();
    }

    private function lockSession(int $sessionId): void
    {
        DB::table('reconciliation_sessions')->where('id', $sessionId)->lockForUpdate()->firstOrFail();
    }

    private function row(int $sessionId, int $id): object
    {
        $row = DB::table('catalog_rows')->where('session_id', $sessionId)->where('id', $id)->first();
        if (! $row) {
            $this->fail('La fila no pertenece a esta sesión.');
        }

        return $row;
    }

    private function refreshStates(array $ids): void
    {
        foreach ($ids as $id) {
            $hasLinks = DB::table('catalog_links')->where(fn ($q) => $q->where('fifty_id', $id)->orWhere('pemex_id', $id))->exists();
            DB::table('catalog_rows')->where('id', $id)->update(['status' => $hasLinks ? 'linked' : 'pending']);
        }
    }

    private function checkVersion(object $row, int $version): void
    {
        if ((int) $row->version !== $version) {
            $this->conflict();
        }
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['conflict' => 'Otra persona modificó un producto. Se actualizó la selección; revisa las equivalencias y confirma de nuevo.']);
    }

    private function snapshot(array $ids): array
    {
        return [
            'rows' => DB::table('catalog_rows')->whereIn('id', $ids)->select('id', 'status', 'version')->orderBy('id')->get()->toArray(),
            'links' => DB::table('catalog_links')->where(fn ($q) => $q->whereIn('fifty_id', $ids)->orWhereIn('pemex_id', $ids))->select('id', 'fifty_id', 'pemex_id')->orderBy('id')->get()->toArray(),
        ];
    }

    private function audit(int $sessionId, string $action, array $before, array $after): void
    {
        DB::table('reconciliation_audits')->insert(['session_id' => $sessionId, 'user_id' => auth()->id(), 'action' => $action,
            'before' => json_encode($before), 'after' => json_encode($after), 'created_at' => now()]);
    }

    private function confirmationRequired(string $message): never
    {
        throw ValidationException::withMessages(['confirmation' => $message]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['decision' => $message]);
    }
}
