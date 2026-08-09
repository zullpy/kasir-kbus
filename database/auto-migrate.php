<?php
// =========================================================================
// AUTO DATABASE MIGRATION RUNNER (mysqli) - Aplikasi Kasir
// =========================================================================

function runAutoMigrationsKasir($db, $dbNameLabel = 'db1')
{
    if (!$db || !($db instanceof mysqli) || $db->connect_error) return;

    // 1. Buat tabel schema_migrations jika belum ada
    $createTable = "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) NOT NULL PRIMARY KEY,
        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    @$db->query($createTable);

    // 2. Ambil daftar migrasi yang sudah pernah dieksekusi
    $executed = [];
    $res = @$db->query("SELECT version FROM schema_migrations");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $executed[$row['version']] = true;
        }
        $res->free();
    }

    // 3. Scan folder database/migrations/
    $migrationDir = __DIR__ . '/migrations';
    if (!is_dir($migrationDir)) {
        return;
    }

    $migrationFiles = [];
    $files = array_merge(
        glob($migrationDir . '/*.sql') ?: [],
        glob($migrationDir . '/*.php') ?: []
    );
    foreach ($files as $f) {
        $version = basename($f);
        $migrationFiles[$version] = $f;
    }

    ksort($migrationFiles);

    // 4. Eksekusi file migrasi yang belum pernah dijalankan
    foreach ($migrationFiles as $version => $filePath) {
        if (isset($executed[$version])) {
            continue;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $success = false;

        if ($ext === 'sql') {
            $sql = file_get_contents($filePath);
            if (trim($sql) !== '') {
                $queries = array_filter(array_map('trim', explode(';', $sql)));
                $success = true;
                foreach ($queries as $q) {
                    if ($q !== '') {
                        try {
                            if (!@$db->query($q)) {
                                if (!in_array($db->errno, [1060, 1061, 1050, 1146], true)) {
                                    $success = false;
                                }
                            }
                        } catch (Throwable $tq) {
                            // Suppress
                        }
                    }
                }
            } else {
                $success = true;
            }
        } elseif ($ext === 'php') {
            try {
                $koneksi = $db;
                include $filePath;
                $success = true;
            } catch (Throwable $e) {
                $success = false;
            }
        }

        if ($success) {
            $stmt = $db->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param("s", $version);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

if (isset($koneksi_kasir) && $koneksi_kasir instanceof mysqli) {
    runAutoMigrationsKasir($koneksi_kasir, 'db_kasir');
}

if (isset($koneksi_mbg) && $koneksi_mbg instanceof mysqli) {
    runAutoMigrationsKasir($koneksi_mbg, 'db_mbg');
}

if (isset($koneksi_draft) && $koneksi_draft instanceof mysqli) {
    runAutoMigrationsKasir($koneksi_draft, 'db_draft_barang');
}
