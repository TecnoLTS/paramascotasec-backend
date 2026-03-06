#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

if ($argc < 2) {
    echo "Uso: php generate_xml_for_order.php <order_id>\n";
    exit(1);
}

$orderId = $argv[1];

try {
    $controller = new \App\Controllers\SriController();
    
    // Obtener la orden
    $orderRepo = new \App\Repositories\OrderRepository();
    $order = $orderRepo->getById($orderId);
    
    if (!$order) {
        echo "❌ Orden no encontrada: $orderId\n";
        exit(1);
    }
    
    // Usar reflexión para acceder a métodos privados
    $reflection = new ReflectionClass($controller);
    
    $prepareMethod = $reflection->getMethod('prepareOrderDataForSri');
    $prepareMethod->setAccessible(true);
    $xmlData = $prepareMethod->invoke($controller, $order);
    
    // Obtener XMLGenerator
    $xmlGeneratorProp = $reflection->getProperty('xmlGenerator');
    $xmlGeneratorProp->setAccessible(true);
    $xmlGenerator = $xmlGeneratorProp->getValue($controller);
    
    // Generar XML
    $xml = $xmlGenerator->generateInvoiceXml($xmlData);
    
    // Guardar
    $storageDir = __DIR__ . '/../storage/sri/xml';
    $secuencial = str_pad($xmlData['secuencial'], 9, '0', STR_PAD_LEFT);
    $timestamp = date('YmdHis');
    $filename = "factura_{$secuencial}_{$timestamp}.xml";
    $filepath = $storageDir . '/' . $filename;
    
    file_put_contents($filepath, $xml);
    
    echo "✅ XML generado correctamente\n";
    echo "Archivo: $filename\n";
    echo "Ruta: $filepath\n";
    echo "\n";
    echo "Datos del cliente:\n";
    echo "  Nombre: {$xmlData['customer']['name']}\n";
    echo "  Tipo Doc: {$xmlData['customer']['document_type']}\n";
    echo "  Documento: {$xmlData['customer']['document_number']}\n";
    echo "  Email: {$xmlData['customer']['email']}\n";
    echo "  Teléfono: {$xmlData['customer']['phone']}\n";
    echo "  Dirección: {$xmlData['customer']['address']}\n";
    
    exit(0);
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
