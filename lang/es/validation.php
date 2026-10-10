<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser texto.',
    'email' => 'El correo no es válido.',
    'unique' => 'El valor de :attribute ya está registrado.',
    'in' => 'Selecciona un valor válido para :attribute.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'array' => 'El campo :attribute debe ser una lista.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'extensions' => 'El archivo debe tener una extensión permitida: :values.',
    'min' => ['string' => ':attribute debe tener al menos :min caracteres.', 'numeric' => ':attribute debe ser como mínimo :min.', 'array' => 'Selecciona al menos :min elemento para :attribute.'],
    'max' => ['string' => ':attribute no puede superar :max caracteres.', 'numeric' => ':attribute no puede superar :max.', 'file' => 'El archivo no puede superar :max KB.', 'array' => ':attribute no puede superar :max elementos.'],
];
