<?php

namespace App\Services;

class CatalogConfiguration
{
    public static function names(object $session): array
    {
        return ['fifty' => $session->left_catalog_name, 'pemex' => $session->right_catalog_name];
    }

    public static function defaults(object $session, string $side): array
    {
        $names = self::names($session);
        $opposite = $side === 'fifty' ? 'pemex' : 'fifty';
        $legacyPair = mb_strtoupper($names['fifty']) === 'FIFTY' && mb_strtoupper($names['pemex']) === 'PEMEX';

        return [
            'reference_name' => 'ID '.mb_strtoupper($names[$side]),
            'result_name' => 'ID '.mb_strtoupper($names[$opposite]),
            'classification_text' => $legacyPair
                ? ($side === 'fifty' ? 'Producto fuera de cuadro de PEMEX' : 'Producto nuevo')
                : 'Producto sin equivalente en '.$names[$opposite],
        ];
    }

    public static function downloadName(object $session, string $kind): string
    {
        $names = self::names($session);
        $safe = fn (string $name): string => mb_substr(trim(preg_replace('/[^\pL\pN _.\-]/u', '-', $name), ' .'), 0, 80) ?: 'Catálogo';

        return $kind === 'report'
            ? 'Informe de revisión - '.$safe($names['fifty']).' vs '.$safe($names['pemex']).'.xlsx'
            : $safe($names[$kind]).' conciliado.xlsx';
    }
}
