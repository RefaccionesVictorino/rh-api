<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sube archivos al bucket a través del microservicio de carga.
 *
 * El bucket no se toca directamente: las credenciales viven en el
 * microservicio, que resuelve a qué cuenta pertenece cada proyecto y devuelve
 * la URL de la distribución de CloudFront. Por eso aquí no hay SDK de AWS ni
 * llaves, solo una llamada HTTP con el identificador del proyecto.
 */
class FileUploader
{
    /**
     * Sube un archivo y devuelve su URL sin firmar.
     *
     * `$path` es la carpeta dentro del bucket y debe terminar en `/`: el
     * microservicio concatena el nombre sin separador, y sin la diagonal la
     * carpeta se pega al nombre del archivo.
     *
     * @throws RuntimeException si la subida no se completa
     */
    public function upload(UploadedFile $file, string $path): string
    {
        $config = config('services.file_upload');

        if (! $config['url'] || ! $config['bucket'] || ! $config['project_key']) {
            throw new RuntimeException('El servicio de carga de archivos no está configurado.');
        }

        $name = $this->safeName($file);

        // El timeout largo cubre archivos grandes en conexiones lentas: el
        // microservicio tarda lo que tarde la transferencia hacia S3.
        $response = Http::timeout(600)
            ->attach('file', fopen($file->getRealPath(), 'r'), $name)
            ->post($config['url'], [
                'bucket' => $config['bucket'],
                'projectId' => $config['project_key'],
                'path' => $path,
            ]);

        if (! $response->ok()) {
            Log::error('Falló la subida de un archivo al bucket', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
                'file' => $name,
                'path' => $path,
                'size_mb' => round($file->getSize() / 1048576, 2),
            ]);

            throw new RuntimeException('No se pudo subir el archivo. Inténtalo de nuevo.');
        }

        $location = $response->json('location');

        if (! is_string($location) || $location === '') {
            Log::error('El microservicio de carga no devolvió la ubicación del archivo', [
                'body' => Str::limit($response->body(), 500),
            ]);

            throw new RuntimeException('No se pudo subir el archivo. Inténtalo de nuevo.');
        }

        return $location;
    }

    /**
     * Nombre saneado y único.
     *
     * El aleatorio va por delante del slug para que dos archivos con el mismo
     * nombre original no se pisen dentro de la misma carpeta: el microservicio
     * sobrescribe la llave si ya existe.
     */
    private function safeName(UploadedFile $file): string
    {
        $extension = Str::lower($file->getClientOriginalExtension());
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = Str::slug($base) ?: 'archivo';
        $unique = Str::lower(Str::random(8));

        return $extension === ''
            ? $unique.'-'.$slug
            : $unique.'-'.$slug.'.'.$extension;
    }
}
