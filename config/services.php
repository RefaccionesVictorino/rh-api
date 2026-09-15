<?php

/**
 * Resuelve la ruta del certificado de firma. Se acepta absoluta o relativa a
 * `storage/`, para no depender del directorio donde esté clonado el proyecto.
 */
$certPath = static function (?string $path): ?string {
    if (! $path) {
        return null;
    }

    $isAbsolute = str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:/', $path);

    return $isAbsolute ? $path : storage_path($path);
};

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Microservicio de carga al bucket. Las credenciales de AWS no viven aquí:
     * el microservicio las resuelve a partir del identificador del proyecto.
     */
    'file_upload' => [
        'url' => env('FILE_UPLOAD_URL'),
        'bucket' => env('FILE_UPLOAD_BUCKET'),
        'project_key' => env('FILE_UPLOAD_PROJECT_KEY'),
    ],

    /*
     * Firma de lectura. El bucket es privado y se sirve por CloudFront, así que
     * cada URL se firma al momento de entregarla y caduca sola.
     */
    'cloudfront' => [
        'private_key_path' => $certPath(env('PRESIGNED_URL_CERT')),
        'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID'),
        'url_lifetime_minutes' => env('CLOUDFRONT_URL_LIFETIME_MINUTES', 60),
    ],

];
