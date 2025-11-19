<?php
/**
 * Stock Calculator Service
 * Calculates available stock by subtracting pending orders from inventory
 * Supports both base inventory and variation-level calculations
 */
class StockCalculator {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get available stock for a variation (total stock - pending orders)
     * @param int $inventory_id
     * @param string $variation Variation name (null for base inventory)
     * @param string $unit_type Unit type filter
     * @return int Available stock quantity
     */
    public function getAvailableStock($inventory_id, $variation = null, $unit_type = null) {
        try {
            if (!empty($variation)) {
                // Variation-specific stock
                require_once __DIR__ . '/inventory_variation.php';
                $invVariation = new InventoryVariation($this->conn);
                
                // Get total stock for this variation
                $totalStock = $invVariation->getStock($inventory_id, $variation, $unit_type ?: 'per piece');
                
                // Calculate pending orders for this specific variation
                $orderQuery = "SELECT COALESCE(SUM(quantity), 0) as pending_qty 
                             FROM orders 
                             WHERE inventory_id = :inventory_id 
                             AND variation = :variation 
                             AND confirmation_status NOT IN ('cancelled', 'completed')";
                
                $orderStmt = $this->conn->prepare($orderQuery);
                $orderStmt->execute([
                    ':inventory_id' => $inventory_id,
                    ':variation' => $variation
                ]);
                $pendingRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
                $pendingQty = (int)($pendingRow['pending_qty'] ?? 0);
                
                // Available = Total - Pending
                $available = $totalStock - $pendingQty;
                return max(0, $available); // Don't allow negative
                
            } else {
                // Base inventory stock
                $inventoryStmt = $this->conn->prepare("SELECT quantity FROM inventory WHERE id = :id");
                $inventoryStmt->execute([':id' => $inventory_id]);
                $invRow = $inventoryStmt->fetch(PDO::FETCH_ASSOC);
                $totalStock = $invRow ? (int)$invRow['quantity'] : 0;
                
                // Calculate pending orders for base inventory (only orders without variations)
                $orderQuery = "SELECT COALESCE(SUM(quantity), 0) as pending_qty 
                             FROM orders 
                             WHERE inventory_id = :inventory_id 
                             AND (variation IS NULL OR variation = '') 
                             AND confirmation_status NOT IN ('cancelled', 'completed')";
                
                $orderStmt = $this->conn->prepare($orderQuery);
                $orderStmt->execute([':inventory_id' => $inventory_id]);
                $pendingRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
                $pendingQty = (int)($pendingRow['pending_qty'] ?? 0);
                
                // Available = Total - Pending
                $available = $totalStock - $pendingQty;
                return max(0, $available);
            }
        } catch (Exception $e) {
            error_log("Stock calculator error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get available stock map for all variations of an inventory item
     * @param int $inventory_id
     * @return array ['variation_name' => available_stock]
     */
    public function getVariationStockMap($inventory_id) {
        try {
            require_once __DIR__ . '/inventory_variation.php';
            $invVariation = new InventoryVariation($this->conn);
            
            // Get all variations for this inventory
            $variations = $invVariation->getByInventory($inventory_id);
            $stockMap = [];
            
            foreach ($variations as $var) {
                $variationName = $var['variation'];
                $unitType = $var['unit_type'] ?? 'per piece';
                
                // Get available stock (total - pending orders)
                $available = $this->getAvailableStock($inventory_id, $variationName, $unitType);
                $stockMap[$variationName] = $available;
            }
            
            return $stockMap;
        } catch (Exception $e) {
            error_log("Variation stock map error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get comprehensive variation data with available stock (not total stock)
     * Similar to InventoryVariation::getVariationDataByInventory but with available stock
     * @param int $inventory_id
     * @return array ['stocks' => [...], 'prices' => [...], 'units' => [...]]
     */
    public function getVariationDataWithAvailableStock($inventory_id) {
        try {
            require_once __DIR__ . '/inventory_variation.php';
            $invVariation = new InventoryVariation($this->conn);
            
            // Get base variation data (total stocks)
            $varData = $invVariation->getVariationDataByInventory($inventory_id);
            
            // Replace total stocks with available stocks (minus pending orders)
            $availableStockMap = $this->getVariationStockMap($inventory_id);
            
            // Update stocks array with available quantities
            foreach ($varData['stocks'] as $variation => &$stock) {
                if (isset($availableStockMap[$variation])) {
                    $stock = $availableStockMap[$variation];
                } else {
                    // If not in map, calculate it
                    $stock = $this->getAvailableStock($inventory_id, $variation);
                }
            }
            
            return $varData;
        } catch (Exception $e) {
            error_log("Variation data with available stock error: " . $e->getMessage());
            return ['stocks' => [], 'prices' => [], 'units' => []];
        }
    }
    
    /**
     * Batch calculate available stock for multiple inventory items
     * @param array $inventory_ids Array of inventory IDs
     * @return array ['inventory_id' => ['variation' => available_stock]]
     */
    public function batchGetAvailableStock($inventory_ids) {
        if (empty($inventory_ids)) {
            return [];
        }
        
        $result = [];
        foreach ($inventory_ids as $inv_id) {
            $result[$inv_id] = $this->getVariationStockMap($inv_id);
        }
        
        return $result;
    }
}
?>

