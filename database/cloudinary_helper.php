<?php
// =========================================================================
// CLOUDINARY HELPER (APLIKASI KASIR)
// Mendukung upload langsung ke Cloudinary REST API via native PHP cURL,
// fallback otomatis ke penyimpanan lokal jika koneksi gagal / belum diset,
// dan kompatibilitas penuh untuk foto/tanda tangan lama.
// =========================================================================

// Muat konfigurasi Cloudinary
$configFile = __DIR__ . '/cloudinary.php';
if (file_exists($configFile)) {
    require_once $configFile;
} elseif (file_exists(__DIR__ . '/cloudinary.example.php')) {
    require_once __DIR__ . '/cloudinary.example.php';
}

/**
 * Cek apakah kredensial Cloudinary sudah diisi valid
 */
function cloudinary_is_configured(): bool
{
    return defined('CLOUDINARY_CLOUD_NAME')
        && defined('CLOUDINARY_API_KEY')
        && defined('CLOUDINARY_API_SECRET')
        && !empty(trim(CLOUDINARY_CLOUD_NAME))
        && !empty(trim(CLOUDINARY_API_KEY))
        && !empty(trim(CLOUDINARY_API_SECRET))
        && trim(CLOUDINARY_CLOUD_NAME) !== 'YOUR_CLOUD_NAME';
}

/**
 * Upload file langsung ke Cloudinary API menggunakan cURL
 *
 * @param string $filePath Path file fisik di server ATAU data-uri base64
 * @param string $subfolder Subfolder di dalam aplikasi-kasir (misal 'bukti', 'ttd')
 * @param string|null $publicId Nama custom public_id (opsional)
 * @param string $resourceType 'auto' (mendukung gambar & PDF), 'image', atau 'raw'
 * @return array ['success' => bool, 'url' => string, 'public_id' => string, 'format' => string]
 * @throws Exception Jika upload gagal
 */
function cloudinary_upload(string $filePath, string $subfolder = '', ?string $publicId = null, string $resourceType = 'auto'): array
{
    $isBase64 = str_starts_with($filePath, 'data:image/');

    if (!$isBase64 && !file_exists($filePath)) {
        throw new Exception("File sumber tidak ditemukan: " . $filePath);
    }

    if (!cloudinary_is_configured()) {
        throw new Exception("Cloudinary belum dikonfigurasi. Silakan lengkapi kredensial di database/cloudinary.php");
    }

    $cloudName = trim(CLOUDINARY_CLOUD_NAME);
    $apiKey    = trim(CLOUDINARY_API_KEY);
    $apiSecret = trim(CLOUDINARY_API_SECRET);

    $baseFolder = defined('CLOUDINARY_BASE_FOLDER') ? trim(CLOUDINARY_BASE_FOLDER, '/') : 'aplikasi-kasir';
    if (!empty($subfolder)) {
        $cleanSub = trim($subfolder, '/');
        if ($cleanSub === $baseFolder || str_starts_with($cleanSub, $baseFolder . '/')) {
            $targetFolder = $cleanSub;
        } else {
            $targetFolder = $baseFolder . '/' . $cleanSub;
        }
    } else {
        $targetFolder = $baseFolder;
    }

    $timestamp = time();

    // Parameter untuk kalkulasi SHA1 signature
    $paramsToSign = [
        'folder'    => $targetFolder,
        'timestamp' => $timestamp,
    ];

    if (!empty($publicId)) {
        $paramsToSign['public_id'] = $publicId;
    }

    ksort($paramsToSign);

    $signParts = [];
    foreach ($paramsToSign as $k => $v) {
        $signParts[] = "{$k}={$v}";
    }
    $toSignStr = implode('&', $signParts) . $apiSecret;
    $signature = sha1($toSignStr);

    // Payload POST multipart
    $postFields = [
        'file'      => $isBase64 ? $filePath : new CURLFile($filePath),
        'api_key'   => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature,
        'folder'    => $targetFolder,
    ];

    if (!empty($publicId)) {
        $postFields['public_id'] = $publicId;
    }

    $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Koneksi ke Cloudinary gagal: " . $curlError);
    }

    $json = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($json['secure_url'])) {
        $secureUrl = $json['secure_url'];
        // Tambahkan transformasi f_auto,q_auto untuk auto-kompresi & auto WebP
        if (str_contains($secureUrl, '/image/upload/') && !str_contains($secureUrl, 'f_auto')) {
            $secureUrl = str_replace('/image/upload/', '/image/upload/f_auto,q_auto/', $secureUrl);
        }
        return [
            'success'   => true,
            'url'       => $secureUrl,
            'public_id' => $json['public_id'] ?? '',
            'format'    => $json['format'] ?? '',
            'bytes'     => $json['bytes'] ?? 0,
        ];
    }

    $errorMsg = $json['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . $response);
    throw new Exception("Cloudinary Error: " . $errorMsg);
}

/**
 * Upload cerdas untuk $_FILES: Otomatis upload ke Cloudinary jika dikonfigurasi,
 * atau simpan lokal jika Cloudinary belum diset / fallback diaktifkan.
 *
 * @param array $fileItem Elemen dari $_FILES (misal $_FILES['bukti_tf'])
 * @param string $subfolder Subfolder di Cloudinary (misal 'bukti')
 * @param string $localDir Folder lokal cadangan (misal '../../uploads/bukti/')
 * @param string $prefix Prefix nama file jika disimpan lokal
 * @return string Mengembalikan URL Cloudinary (https://...) ATAU nama file lokal
 * @throws Exception
 */
function smart_upload_foto(array $fileItem, string $subfolder, string $localDir, string $prefix = 'img'): string
{
    $tmpName = $fileItem['tmp_name'] ?? '';
    $origName = $fileItem['name'] ?? '';

    if (empty($tmpName) || !file_exists($tmpName)) {
        throw new Exception("File upload tidak ditemukan");
    }

    // Coba upload ke Cloudinary terlebih dahulu jika sudah dikonfigurasi
    if (cloudinary_is_configured()) {
        try {
            $res = cloudinary_upload($tmpName, $subfolder);
            if (!empty($res['url'])) {
                return $res['url'];
            }
        } catch (Exception $e) {
            $fallback = defined('CLOUDINARY_FALLBACK_LOCAL') ? CLOUDINARY_FALLBACK_LOCAL : true;
            if (!$fallback) {
                throw $e;
            }
            error_log("Cloudinary upload failed, falling back to local: " . $e->getMessage());
        }
    }

    // Penyimpanan Lokal Cadangan
    if (!file_exists($localDir)) {
        mkdir($localDir, 0755, true);
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $cleanPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix);
    $newName = $cleanPrefix . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $targetPath = rtrim($localDir, '/') . '/' . $newName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new Exception("Gagal menyimpan file ke penyimpanan lokal: " . $targetPath);
    }

    return $newName;
}

/**
 * Upload cerdas untuk data Base64 Canvas Signature (Tanda Tangan)
 *
 * @param string $base64Data Data URI base64 (misal data:image/png;base64,...)
 * @param string $subfolder Subfolder di Cloudinary (misal 'ttd')
 * @param string $localDir Folder lokal cadangan (misal '../../uploads/ttd/')
 * @param string $prefix Prefix nama file jika disimpan lokal
 * @return string Mengembalikan URL Cloudinary (https://...) ATAU nama file lokal
 * @throws Exception
 */
function smart_upload_base64(string $base64Data, string $subfolder, string $localDir, string $prefix = 'ttd'): string
{
    if (empty($base64Data)) {
        throw new Exception("Data tanda tangan kosong");
    }

    // Coba upload langsung base64 string ke Cloudinary
    if (cloudinary_is_configured()) {
        try {
            $res = cloudinary_upload($base64Data, $subfolder);
            if (!empty($res['url'])) {
                return $res['url'];
            }
        } catch (Exception $e) {
            $fallback = defined('CLOUDINARY_FALLBACK_LOCAL') ? CLOUDINARY_FALLBACK_LOCAL : true;
            if (!$fallback) {
                throw $e;
            }
            error_log("Cloudinary signature upload failed, falling back to local: " . $e->getMessage());
        }
    }

    // Penyimpanan Lokal Cadangan
    if (!file_exists($localDir)) {
        mkdir($localDir, 0755, true);
    }

    $data = $base64Data;
    if (preg_match('/^data:image\/(png|jpeg);base64,/', $data)) {
        $data = substr($data, strpos($data, ',') + 1);
    }
    $decoded = base64_decode($data);
    if ($decoded === false) {
        throw new Exception("Gagal mendekode data tanda tangan base64");
    }

    $cleanPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix);
    $newName = $cleanPrefix . '_' . date('Ymd_His') . '_' . uniqid() . '.png';
    $targetPath = rtrim($localDir, '/') . '/' . $newName;

    if (file_put_contents($targetPath, $decoded) === false) {
        throw new Exception("Gagal menyimpan tanda tangan ke folder lokal: " . $targetPath);
    }

    return $newName;
}

/**
 * Menyelesaikan path foto: Mengembalikan URL Cloudinary jika sudah berupa link web,
 * atau path lokal lengkap jika masih berupa nama file lokal lama.
 *
 * @param string|null $photo Filename atau Full URL
 * @param string $localPrefix Path relatif folder lokal (misal '../../uploads/bukti/')
 * @return string
 */
function resolve_photo_url(?string $photo, string $localPrefix = ''): string
{
    if (empty($photo)) {
        return '';
    }

    $photo = trim($photo);

    // Jika sudah berupa URL Cloudinary atau web link
    if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
        // Otomatis optimalkan format (WebP) dan kompresi (q_auto) jika berasal dari Cloudinary
        if (str_contains($photo, 'res.cloudinary.com') && str_contains($photo, '/image/upload/') && !str_contains($photo, 'f_auto')) {
            return str_replace('/image/upload/', '/image/upload/f_auto,q_auto/', $photo);
        }
        return $photo;
    }

    $cleanPrefix = trim($localPrefix, '/');
    $cleanPhoto  = ltrim($photo, '/');

    // Cegah prefix berulang jika photo sudah diawali dengan folder prefix yang sama
    if (!empty($cleanPrefix) && str_starts_with($cleanPhoto, $cleanPrefix . '/')) {
        return $cleanPhoto;
    }

    return (!empty($cleanPrefix) ? $cleanPrefix . '/' : '') . $cleanPhoto;
}

/**
 * Ekstrak public_id dari URL Cloudinary
 */
function cloudinary_extract_public_id(string $url): ?string
{
    if (!str_contains($url, 'res.cloudinary.com')) {
        return null;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) return null;

    $uploadPos = strpos($path, '/image/upload/');
    if ($uploadPos === false) return null;

    $afterUpload = substr($path, $uploadPos + strlen('/image/upload/'));
    $parts = explode('/', $afterUpload);

    // Hilangkan transformasi (misal f_auto,q_auto) dan versi (v1234567)
    while (!empty($parts)) {
        $first = $parts[0];
        if (str_contains($first, ',') || preg_match('/^v\d+$/', $first)) {
            array_shift($parts);
        } else {
            break;
        }
    }

    if (empty($parts)) return null;

    $publicIdWithExt = implode('/', $parts);
    return pathinfo($publicIdWithExt, PATHINFO_DIRNAME) !== '.' 
        ? pathinfo($publicIdWithExt, PATHINFO_DIRNAME) . '/' . pathinfo($publicIdWithExt, PATHINFO_FILENAME)
        : pathinfo($publicIdWithExt, PATHINFO_FILENAME);
}

/**
 * Hapus aset foto: Hapus dari Cloudinary jika berupa URL, atau unlink dari folder lokal jika file lokal
 */
function delete_photo_asset(?string $photo, string $localDir = ''): bool
{
    if (empty($photo)) {
        return false;
    }

    $photo = trim($photo);

    if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
        if (!cloudinary_is_configured()) return false;
        $publicId = cloudinary_extract_public_id($photo);
        if (!$publicId) return false;

        $cloudName = trim(CLOUDINARY_CLOUD_NAME);
        $apiKey    = trim(CLOUDINARY_API_KEY);
        $apiSecret = trim(CLOUDINARY_API_SECRET);
        $timestamp = time();

        $paramsToSign = [
            'public_id' => $publicId,
            'timestamp' => $timestamp,
        ];
        ksort($paramsToSign);

        $signParts = [];
        foreach ($paramsToSign as $k => $v) {
            $signParts[] = "{$k}={$v}";
        }
        $signature = sha1(implode('&', $signParts) . $apiSecret);

        $postFields = [
            'public_id' => $publicId,
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];

        $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return true;
    }

    $localPath = rtrim($localDir, '/') . '/' . basename($photo);
    if (file_exists($localPath) && is_file($localPath)) {
        return @unlink($localPath);
    }

    return false;
}
