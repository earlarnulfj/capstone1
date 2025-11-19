<?php
// Shared unit type codes and variation attribute/value mappings

$UNIT_TYPE_CODE_MAP = [
    'pc' => 'per piece',
    'set' => 'per set',
    'box' => 'per box',
    'pack' => 'per pack',
    'bag' => 'per bag',
    'roll' => 'per roll',
    'bar' => 'per bar',
    'sheet' => 'per sheet',
    'm' => 'per meter',
    'L' => 'per liter',
    'gal' => 'per gallon',
    'tube' => 'per tube',
    'btl' => 'per bottle',
    'can' => 'per can',
    'sack' => 'per sack'
];

// Attribute groups and sample options per unit type
$UNIT_VARIATION_OPTIONS = [
    'pc' => [
        'Size' => ['Small','Medium','Large'],
        'Type' => ['Claw Hammer','Sledge Hammer'],
        'Brand' => ['Generic','ProTools']
    ],
    'set' => [
        'Size' => ['Small','Medium','Large'],
        'Type' => ['Wrench Set','Screwdriver Set'],
        'Brand' => ['Generic','ProTools']
    ],
    'box' => [
        'Quantity' => ['10','20','50'],
        'Size' => ['Small','Medium','Large']
    ],
    'pack' => [
        'Quantity' => ['5','10','20'],
        'Size' => ['Small','Medium','Large']
    ],
    'bag' => [
        'Brand' => ['Generic','Premium'],
        'Type' => ['Sand','Cement']
    ],
    'roll' => [
        'Length' => ['5m','10m','20m'],
        'Thickness' => ['1mm','2mm','3mm']
    ],
    'bar' => [
        'Size' => ['10mm','12mm','16mm']
    ],
    'sheet' => [
        'Thickness' => ['0.5mm','1mm','2mm'],
        'Size' => ['2x4','4x8'],
        'Type' => ['Plywood','Metal']
    ],
    'm' => [
        'Length' => ['1m','2m','3m'],
        'Diameter' => ['10mm','20mm','30mm']
    ],
    'L' => [
        'Color' => ['White','Red','Blue'],
        'Brand' => ['Acme Paint','ProCoat'],
        'Finish' => ['Matte','Gloss']
    ],
    'gal' => [
        'Color' => ['White','Red','Blue'],
        'Brand' => ['Acme Paint','ProCoat'],
        'Finish' => ['Matte','Gloss']
    ],
    'tube' => [
        'Size' => ['1/2"','1"','2"'],
        'Material' => ['PVC','Steel']
    ],
    'btl' => [
        'Size' => ['500ml','1L','2L'],
        'Type' => ['Water','Chemical']
    ],
    'can' => [
        'Size' => ['Small','Medium','Large'],
        'Color' => ['Silver','Black'],
        'Brand' => ['Generic','BrandX']
    ],
    'sack' => [
        'Type' => ['Sand','Cement'],
        'Material' => ['Jute','Plastic']
    ]
];

function unit_code_to_type($code) {
    global $UNIT_TYPE_CODE_MAP;
    return $UNIT_TYPE_CODE_MAP[$code] ?? null;
}

function get_unit_variation_options($code) {
    global $UNIT_VARIATION_OPTIONS;
    return $UNIT_VARIATION_OPTIONS[$code] ?? [];
}