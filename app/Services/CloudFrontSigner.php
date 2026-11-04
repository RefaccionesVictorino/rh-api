<?php

namespace App\Services;

/**
 * Firma URLs de CloudFront con una canned policy.
 *
 * El bucket es privado: las fotos solo se sirven a través de la distribución de
 * CloudFront y con una firma vigente. La firma se calcula al leer y nunca se
 * persiste; en la base queda la URL cruda que devolvió el microservicio de
 * subida, porque una firma guardada caduca a los pocos minutos y deja el
 * registro inservible.
 *
 * Es una canned policy de CloudFront (RSA-SHA1 sobre la política, base64 con el
 * alfabeto URL-safe de AWS), no un presigned GET de S3: el par de llaves lo
 * define la distribución, no las credenciales del bucket.
 */
class CloudFrontSigner
{
    /**
     * Devuelve la URL con los parámetros de firma.
     *
     * Sin llave o sin key pair id devuelve la URL tal cual: en entornos de
     * desarrollo sin certificado la foto se intenta cargar igual, en vez de
     * romper el expediente completo.
     */
    public function sign(string $url, ?int $lifetimeMinutes = null): string
    {
        $privateKey = $this->privateKey();
        $keyPairId = config('services.cloudfront.key_pair_id');

        if (! $privateKey || ! $keyPairId) {
            return $url;
        }

        $expires = now()
            ->addMinutes($lifetimeMinutes ?? (int) config('services.cloudfront.url_lifetime_minutes'))
            ->getTimestamp();

        $policy = json_encode([
            'Statement' => [[
                'Resource' => $url,
                'Condition' => [
                    'DateLessThan' => ['AWS:EpochTime' => $expires],
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $signature = '';
        if (! openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            return $url;
        }

        $query = http_build_query([
            'Expires' => $expires,
            'Signature' => $this->encodeForUrl($signature),
            'Key-Pair-Id' => $keyPairId,
        ], '', '&', PHP_QUERY_RFC3986);

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    private function privateKey(): ?string
    {
        $path = config('services.cloudfront.private_key_path');

        if (! $path || ! is_readable($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    /** Base64 con el alfabeto que exige CloudFront en la query string. */
    private function encodeForUrl(string $value): string
    {
        return strtr(base64_encode($value), '+=/', '-_~');
    }
}
