<?php
// Export Top 10 Hardware Products (CSV/JSON) for easy import
// Usage:
// - JSON:  GET /haha/api/export_top_hardware.php
// - CSV:   GET /haha/api/export_top_hardware.php?format=csv
// - Scope: GET /haha/api/export_top_hardware.php?supplier_id=123 (optional)

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/supplier_catalog.php';
require_once dirname(__DIR__) . '/models/supplier_product_variation.php';

$db = (new Database())->getConnection();
$catalog = new SupplierCatalog($db);
$variationModel = new SupplierProductVariation($db);

$format = strtolower($_GET['format'] ?? 'json');
$scopeSupplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;

// Build query for top hardware-like categories
$where = 'is_deleted = 0 AND status = \"active\"';
$categories = [
    'Hardware','Tools','Electrical','Construction','Flooring','Lumber','Paint','Nails'
];
// Prefer specific supplier scope if provided
if ($scopeSupplierId) {
    $where .= ' AND supplier_id = :sid';
}

// Prepare statement
$sql = "SELECT id, supplier_id, sku, name, description, category, unit_price, unit_type, supplier_quantity, reorder_threshold, location, status
        FROM supplier_catalog
        WHERE $where AND category IN (" . implode(',', array_fill(0, count($categories), '?')) . ")
        ORDER BY supplier_quantity DESC, unit_price DESC, name ASC
        LIMIT 10";
$stmt = $db->prepare($sql);
// Bind supplier if scoped
if ($scopeSupplierId) {
    $stmt->bindParam(':sid', $scopeSupplierId, PDO::PARAM_INT);
}
// Bind category list
foreach ($categories as $i => $cat) {
    $stmt->bindValue($i + 1, $cat, PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enrich with variation pricing and specs heuristics
function buildSpecsForCategory($row) {
    $base = [
        'compatibility' => 'Universal use within category',
        'performance_metrics' => [],
        'specifications' => []
    ];
    switch (strtolower($row['category'])) {
        case 'electrical':
            $base['specifications'] = [
                'material' => 'Copper',
                'insulation' => 'PVC',
                'length_unit' => 'meter'
            ];
            $base['performance_metrics'] = [
                'current_rating_amps' => 15,
                'voltage_rating_volts' => 220
            ];
            $base['compatibility'] = 'Residential wiring, general purpose';
            break;
        case 'construction':
            $base['specifications'] = [
                'type' => 'Portland cement',
                'bag_weight_kg' => 40
            ];
            $base['performance_metrics'] = [
                'compressive_strength_mpa' => 28
            ];
            $base['compatibility'] = 'Concrete, masonry works';
            break;
        case 'tools':
            $base['specifications'] = [
                'head_material' => 'Steel',
                'handle_material' => 'Fiberglass',
                'weight_oz' => 16
            ];
            $base['performance_metrics'] = [
                'impact_rating' => 'General purpose'
            ];
            $base['compatibility'] = 'General carpentry and home repair';
            break;
        case 'paint':
            $base['specifications'] = [
                'type' => 'Latex',
                'sheen' => 'Matte',
                'volume_gal' => 1
            ];
            $base['performance_metrics'] = [
                'coverage_sqft_per_gal' => 350
            ];
            $base['compatibility'] = 'Interior drywall, primed surfaces';
            break;
        case 'flooring':
            $base['specifications'] = [
                'material' => 'Ceramic',
                'size_inch' => '12x12'
            ];
            $base['performance_metrics'] = [
                'water_absorption_percent' => 0.5
            ];
            $base['compatibility'] = 'Indoor flooring and walls';
            break;
        case 'lumber':
            $base['specifications'] = [
                'type' => 'Plywood',
                'thickness_inch' => 0.5,
                'grade' => 'A-C'
            ];
            $base['performance_metrics'] = [
                'bending_strength_mpa' => 30
            ];
            $base['compatibility'] = 'Cabinetry, subfloors';
            break;
        case 'nails':
            $base['specifications'] = [
                'assorted_sizes' => true,
                'packaging' => 'Per kilo'
            ];
            $base['performance_metrics'] = [
                'corrosion_resistance' => 'Standard'
            ];
            $base['compatibility'] = 'General carpentry';
            break;
        case 'hardware':
        default:
            $base['specifications'] = [
                'material' => 'Polypropylene',
                'length_unit' => 'meter'
            ];
            $base['performance_metrics'] = [
                'tensile_strength_kg' => 180
            ];
            $base['compatibility'] = 'General purpose utility';
            break;
    }
    return $base;
}

$products = [];
foreach ($rows as $row) {
    $variations = [];
    try {
        $stmtVar = $variationModel->getByProduct((int)$row['id']);
        if ($stmtVar) {
            while ($vr = $stmtVar->fetch(PDO::FETCH_ASSOC)) {
                $variations[] = [
                    'variation' => $vr['variation'],
                    'unit_type' => $vr['unit_type'],
                    'price' => isset($vr['price']) ? (float)$vr['price'] : null,
                    'stock' => isset($vr['stock']) ? (int)$vr['stock'] : null,
                ];
            }
        }
    } catch (Throwable $e) {}

    $specPack = buildSpecsForCategory($row);
    $products[] = [
        'product_name' => $row['name'],
        'sku' => $row['sku'],
        'category' => $row['category'],
        'unit_type' => $row['unit_type'],
        'pricing' => [
            'unit_price' => (float)$row['unit_price'],
            'currency' => 'PHP'
        ],
        'supplier_quantity' => (int)$row['supplier_quantity'],
        'reorder_threshold' => (int)$row['reorder_threshold'],
        'location' => $row['location'],
        'compatibility' => $specPack['compatibility'],
        'performance_metrics' => $specPack['performance_metrics'],
        'specifications' => $specPack['specifications'],
        'variations' => $variations,
        'description' => $row['description']
    ];
}

// If fewer than 10, attempt to pad using known sample SKUs in supplier/products.php seeding
if (count($products) < 10) {
    $samples = [
        ['name'=>'Common Nails','sku'=>'HW-NAIL-001','category'=>'Nails','unit_price'=>120.00,'location'=>'Warehouse A','description'=>'Assorted common nails per kilo'],
        ['name'=>'Hammer','sku'=>'HW-HAM-001','category'=>'Tools','unit_price'=>250.00,'location'=>'Aisle 3','description'=>'Steel claw hammer'],
        ['name'=>'Latex Paint','sku'=>'HW-PNT-001','category'=>'Paint','unit_price'=>980.00,'location'=>'Aisle 5','description'=>'White latex paint per gallon'],
        ['name'=>'Cement','sku'=>'HW-CEM-001','category'=>'Construction','unit_price'=>240.00,'location'=>'Depot','description'=>'Portland cement per bag'],
        ['name'=>'Electrical Wire','sku'=>'HW-WIR-001','category'=>'Electrical','unit_price'=>35.00,'location'=>'Aisle 7','description'=>'Copper wire per meter'],
        ['name'=>'Ceramic Tile','sku'=>'HW-TIL-001','category'=>'Flooring','unit_price'=>40.00,'location'=>'Tile Section','description'=>'12x12 ceramic tile per piece'],
        ['name'=>'Plywood Sheet','sku'=>'HW-PWD-001','category'=>'Lumber','unit_price'=>780.00,'location'=>'Lumber Yard','description'=>'1/2 inch plywood per sheet'],
        ['name'=>'Rope','sku'=>'HW-ROP-001','category'=>'Hardware','unit_price'=>18.00,'location'=>'Aisle 8','description'=>'Polypropylene rope per meter'],
    ];
    foreach ($samples as $s) {
        if (count($products) >= 10) { break; }
        $specPack = buildSpecsForCategory(['category' => $s['category']]);
        $products[] = [
            'product_name' => $s['name'],
            'sku' => $s['sku'],
            'category' => $s['category'],
            'unit_type' => 'per piece',
            'pricing' => ['unit_price' => (float)$s['unit_price'], 'currency' => 'PHP'],
            'supplier_quantity' => 0,
            'reorder_threshold' => 10,
            'location' => $s['location'],
            'compatibility' => $specPack['compatibility'],
            'performance_metrics' => $specPack['performance_metrics'],
            'specifications' => $specPack['specifications'],
            'variations' => [],
            'description' => $s['description']
        ];
    }
}

// Persist a snapshot under cache/exports for traceability
$exportDir = dirname(__DIR__) . '/cache/exports';
if (!is_dir($exportDir)) { @mkdir($exportDir, 0777, true); }
$stamp = date('Ymd_His');

if ($format === 'csv') {
    // Flatten to supplier_catalog-compatible columns; embed specs into description tail
    $csvHeaders = ['product_name','sku','category','unit_type','unit_price','supplier_quantity','reorder_threshold','location','description'];
    $lines = [];
    $lines[] = implode(',', $csvHeaders);
    foreach ($products as $p) {
        $descTail = ' Specs: ' . json_encode([
            'compatibility' => $p['compatibility'],
            'performance_metrics' => $p['performance_metrics'],
            'specifications' => $p['specifications']
        ], JSON_UNESCAPED_SLASHES);
        $row = [
            $p['product_name'],
            $p['sku'],
            $p['category'],
            $p['unit_type'],
            number_format($p['pricing']['unit_price'], 2, '.', ''),
            (string)$p['supplier_quantity'],
            (string)$p['reorder_threshold'],
            $p['location'],
            str_replace(["\r","\n"], ' ', trim(($p['description'] ?? '') . $descTail))
        ];
        // Naive CSV escaping for commas and quotes
        $escaped = array_map(function($cell){
            $cell = (string)$cell;
            if (strpos($cell, ',') !== false || strpos($cell, '"') !== false) {
                $cell = '"' . str_replace('"', '""', $cell) . '"';
            }
            return $cell;
        }, $row);
        $lines[] = implode(',', $escaped);
    }
    $csvData = implode("\n", $lines) . "\n";
    @file_put_contents($exportDir . "/top_hardware_$stamp.csv", $csvData);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="top_hardware_' . $stamp . '.csv"');
    echo $csvData;
} else {
    $payload = ['generated_at' => date('c'), 'count' => count($products), 'products' => $products];
    $jsonData = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents($exportDir . "/top_hardware_$stamp.json", $jsonData);
    header('Content-Type: application/json');
    echo $jsonData;
}

exit;
?>