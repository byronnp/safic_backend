<?php

namespace App\Core\Storage;

use App\Core\Http\Exceptions\ApiException;

/** La ruta pedida no pertenece al condominio activo. */
final class ArchivoAjenoException extends ApiException
{
    public function __construct()
    {
        parent::__construct('ARCHIVO_NO_ENCONTRADO', 'No se encontró el archivo.', 404);
    }
}
