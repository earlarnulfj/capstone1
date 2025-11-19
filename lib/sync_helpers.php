<?php
// lib/sync_helpers.php
// Shared sync helper used to bump admin inventory change-feed versions

require_once __DIR__ . '/../models/inventory_variation.php';

function bump_admin_inventory_version(PDO $db, int $inventoryId, string $unitType = 'per piece', ?float $unitPrice = null): void {
    try {
        $baseCacheDir = __DIR__ . '/../cache/admin_inventory';
        $changesDir = $baseCacheDir . '/changes';
        if (!is_dir($changesDir)) { @mkdir($changesDir, 0777, true); }
        $versionsFile = $baseCacheDir . '/versions.json';
        $versions = [];
        if (file_exists($versionsFile)) {
            $raw = file_get_contents($versionsFile);
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) { $versions = $parsed; }
        }
        $invId = (int)$inventoryId;
        $newVersion = isset($versions[$invId]) ? (int)$versions[$invId] + 1 : 1;

        // Collect current variation prices for tracking
        $invVarModel = new InventoryVariation($db);
        $variantRows = $invVarModel->getByInventory($invId);
        $variants = [];
        if (is_array($variantRows)) {
            foreach ($variantRows as $vr) {
                $variants[$vr['variation']] = [
                    'unit_type' => $vr['unit_type'] ?? $unitType,
                    'unit_price' => isset($vr['unit_price']) ? (float)$vr['unit_price'] : null,
                    'stock' => isset($vr['stock']) ? (int)$vr['stock'] : null,
                    'last_updated' => $vr['last_updated'] ?? null
                ];
            }
        }

        $record = [
            'inventory_id' => $invId,
            'version' => $newVersion,
            'committed' => true,
            'changed_by' => $_SESSION['admin']['user_id'] ?? $_SESSION['supplier']['user_id'] ?? $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['admin'] ? 'admin' : 'supplier',
            'timestamp' => date('c'),
            'unit_type' => $unitType,
            'unit_price' => $unitPrice,
            'variants' => $variants
        ];

        @file_put_contents($changesDir . "/inventory_{$invId}.json", json_encode($record, JSON_PRETTY_PRINT));
        $versions[$invId] = $newVersion;
        @file_put_contents($versionsFile, json_encode($versions, JSON_PRETTY_PRINT));
    } catch (Throwable $e) {
        // Best-effort; ignore failures
    }
}