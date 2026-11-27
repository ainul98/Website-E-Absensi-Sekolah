<?php
declare(strict_types=1);

function biometric_key(array $config): string
{
    $encoded = (string) ($config['app']['biometric_key'] ?? '');
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('BIOMETRIC_KEY harus berupa base64 dari kunci acak 32 byte.');
    }
    return $key;
}

function encrypt_template(array $descriptor, array $config): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        json_encode($descriptor, JSON_THROW_ON_ERROR),
        'aes-256-gcm',
        biometric_key($config),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Enkripsi template biometrik gagal.');
    }
    return [
        'ciphertext' => $tag . $ciphertext,
        'nonce' => $nonce,
    ];
}

function decrypt_template(string $ciphertext, string $nonce, array $config): array
{
    if (strlen($nonce) !== 12 || strlen($ciphertext) < 17) {
        throw new RuntimeException('Template wajah tidak valid.');
    }
    $tag = substr($ciphertext, 0, 16);
    $plain = openssl_decrypt(
        substr($ciphertext, 16),
        'aes-256-gcm',
        biometric_key($config),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if ($plain === false) {
        throw new RuntimeException('Template wajah tidak dapat dibuka. Periksa BIOMETRIC_KEY.');
    }
    $descriptor = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($descriptor)) {
        throw new RuntimeException('Template wajah tidak valid.');
    }
    return $descriptor;
}

function valid_descriptor(mixed $descriptor): bool
{
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        return false;
    }
    foreach ($descriptor as $value) {
        if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 10) {
            return false;
        }
    }
    return true;
}

function descriptor_distance(array $left, array $right): float
{
    if (count($left) !== 128 || count($right) !== 128) {
        return INF;
    }
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $delta = (float) $left[$i] - (float) $right[$i];
        $sum += $delta * $delta;
    }
    return sqrt($sum);
}
