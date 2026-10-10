@props(['status', 'classification'])
@php($classified = in_array($status, ['outside', 'new'], true))
<span @class(['inline-block rounded px-2 py-1 font-semibold', 'bg-green-100 text-green-900' => $status === 'linked', 'bg-yellow-100 text-yellow-900' => $classified, 'bg-gray-100 text-gray-800' => $status === 'pending'])>{{ $status === 'linked' ? 'Verificado' : ($classified ? $classification : 'Pendiente') }}</span>
