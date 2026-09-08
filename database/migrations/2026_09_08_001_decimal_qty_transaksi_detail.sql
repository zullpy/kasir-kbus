-- Migration: Support decimal qty pada transaksi_detail
-- Date: 2026-09-08
-- Context: Sebelumnya qty disimpan sebagai INT(10) UNSIGNED sehingga nilai pecahan
--          seperti 0.5 atau 1.5 dibulatkan. Diubah ke DECIMAL agar kasir bisa
--          mencatat penjualan satuan pecahan (misal 0.5 kg, 1.5 liter, dll).

-- db_kasir: kolom qty di transaksi_detail (INT UNSIGNED -> DECIMAL)
ALTER TABLE transaksi_detail
    MODIFY COLUMN qty DECIMAL(12,3) NOT NULL;
