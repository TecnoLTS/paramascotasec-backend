<?php

/**
 * Script de prueba para verificar la generación de XML del SRI
 * Ejecutar: docker exec -it paramascotasec-backend-app php /var/www/html/scripts/test_xml_generation.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\SriXmlGenerator;

echo "=== TEST DE GENERACIÓN XML SRI ===\n\n";

try {
    $xmlGenerator = new SriXmlGenerator();
    
    // Datos de prueba
    $orderData = [
        'secuencial' => 1,
        'fecha_emision' => date('Y-m-d H:i:s'),
        'customer' => [
            'name' => 'CONSUMIDOR FINAL',
            'document_type' => 'consumidor_final',
            'document_number' => '9999999999999',
            'email' => 'test@example.com',
            'phone' => '0999999999',
            'address' => 'Ecuador'
        ],
        'subtotal_sin_impuestos' => 10.00,
        'total_descuento' => 0.00,
        'impuestos' => [
            [
                'codigo' => '2',
                'codigo_porcentaje' => '0',
                'base_imponible' => 10.00,
                'valor' => 0
            ]
        ],
        'total' => 10.00,
        'formas_pago' => [
            [
                'codigo' => '01',
                'total' => 10.00,
                'plazo' => null,
                'unidad_tiempo' => null
            ]
        ],
        'items' => [
            [
                'codigo' => '123',
                'descripcion' => 'Producto de Prueba',
                'cantidad' => 1,
                'precio_unitario' => 10.00,
                'descuento' => 0,
                'precio_total_sin_impuesto' => 10.00,
                'impuestos' => [
                    [
                        'codigo' => '2',
                        'codigo_porcentaje' => '0',
                        'tarifa' => 0,
                        'base_imponible' => 10.00,
                        'valor' => 0
                    ]
                ]
            ]
        ],
        'info_adicional' => [
            'ORDEN_ID' => 'TEST-001'
        ]
    ];
    
    // Generar clave de acceso
    $accessKey = $xmlGenerator->generateAccessKey([
        'fecha_emision' => $orderData['fecha_emision'],
        'tipo_comprobante' => '01',
        'secuencial' => $orderData['secuencial']
    ]);
    
    $orderData['access_key'] = $accessKey;
    
    echo "Clave de acceso generada: $accessKey\n";
    echo "Longitud: " . strlen($accessKey) . " caracteres\n\n";
    
    // Generar XML
    $xml = $xmlGenerator->generateInvoiceXml($orderData);
    
    echo "XML generado exitosamente!\n";
    echo "Tamaño: " . strlen($xml) . " bytes\n\n";
    
    // Guardar en archivo
    $storageDir = __DIR__ . '/../storage/sri/xml';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0775, true);
        echo "Directorio creado: $storageDir\n";
    }
    
    $filename = "test_factura_" . date('YmdHis') . ".xml";
    $filepath = $storageDir . '/' . $filename;
    
    file_put_contents($filepath, $xml);
    echo "XML guardado en: $filepath\n\n";
    
    // Mostrar primeras líneas del XML
    echo "Primeras líneas del XML:\n";
    echo "---\n";
    $lines = explode("\n", $xml);
    echo implode("\n", array_slice($lines, 0, 10)) . "\n";
    echo "...\n";
    
    echo "\n✅ TEST EXITOSO\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
