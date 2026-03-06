<?php

/**
 * Script de prueba para verificar conectividad con el SRI
 * 
 * Uso:
 *   php scripts/test_sri_connection.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\SriSoapService;
use App\Core\TenantContext;

// Cargar variables de entorno
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Configurar tenant para las pruebas
$tenants = require __DIR__ . '/../config/tenants.php';
$testTenant = $tenants['paramascotasec'] ?? null;

if (!$testTenant) {
    echo "❌ Error: No se encontró configuración de tenant 'paramascotasec'\n";
    exit(1);
}

TenantContext::set($testTenant);

echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "  PRUEBA DE CONECTIVIDAD CON EL SRI ECUADOR\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "\n";

try {
    $sriService = new SriSoapService();
    
    // Obtener información del ambiente
    echo "📊 INFORMACIÓN DEL AMBIENTE\n";
    echo "────────────────────────────────────────────────────────────────\n";
    $info = $sriService->getEnvironmentInfo();
    
    echo "Environment: " . strtoupper($info['environment']) . "\n";
    echo "RUC Emisor:  " . $info['emisor']['ruc'] . "\n";
    echo "Razón Social: " . $info['emisor']['razon_social'] . "\n";
    echo "Establecimiento: " . $info['emisor']['codigo_establecimiento'] . "\n";
    echo "Punto Emisión: " . $info['emisor']['punto_emision'] . "\n";
    echo "\n";
    
    echo "📁 CERTIFICADO DIGITAL\n";
    echo "────────────────────────────────────────────────────────────────\n";
    echo "Path: " . $info['certificado_path'] . "\n";
    echo "Existe: " . ($info['certificado_exists'] ? '✅ Sí' : '❌ No') . "\n";
    
    if (!$info['certificado_exists']) {
        echo "\n";
        echo "⚠️  ADVERTENCIA: El certificado digital no existe.\n";
        echo "   Para obtener tu certificado:\n";
        echo "   1. Solicítalo al SRI (https://www.sri.gob.ec)\n";
        echo "   2. Guárdalo en: {$info['certificado_path']}\n";
        echo "   3. Configura la contraseña en .env: SRI_CERT_PASSWORD\n";
    }
    echo "\n";
    
    // Probar conectividad
    echo "🌐 ENDPOINTS DEL SRI\n";
    echo "────────────────────────────────────────────────────────────────\n";
    echo "Recepción:    " . $info['endpoints']['recepcion'] . "\n";
    echo "Autorización: " . $info['endpoints']['autorizacion'] . "\n";
    echo "\n";
    
    echo "🔌 PROBANDO CONECTIVIDAD...\n";
    echo "────────────────────────────────────────────────────────────────\n";
    
    $conexion = $sriService->validarConexion();
    
    // Servicio de Recepción
    echo "\n📥 Servicio de Recepción:\n";
    if ($conexion['recepcion']['disponible']) {
        echo "   Estado: ✅ DISPONIBLE\n";
        echo "   Métodos disponibles:\n";
        foreach ($conexion['recepcion']['metodos'] as $metodo) {
            // Limpiar la salida del método
            $metodo = preg_replace('/\s+/', ' ', $metodo);
            echo "   - " . substr($metodo, 0, 80) . "\n";
        }
    } else {
        echo "   Estado: ❌ NO DISPONIBLE\n";
        echo "   Error: " . $conexion['recepcion']['error'] . "\n";
    }
    
    // Servicio de Autorización
    echo "\n📤 Servicio de Autorización:\n";
    if ($conexion['autorizacion']['disponible']) {
        echo "   Estado: ✅ DISPONIBLE\n";
        echo "   Métodos disponibles:\n";
        foreach ($conexion['autorizacion']['metodos'] as $metodo) {
            $metodo = preg_replace('/\s+/', ' ', $metodo);
            echo "   - " . substr($metodo, 0, 80) . "\n";
        }
    } else {
        echo "   Estado: ❌ NO DISPONIBLE\n";
        echo "   Error: " . $conexion['autorizacion']['error'] . "\n";
    }
    
    echo "\n";
    echo "════════════════════════════════════════════════════════════════\n";
    
    // Verificar si ambos servicios están disponibles
    if ($conexion['recepcion']['disponible'] && $conexion['autorizacion']['disponible']) {
        echo "✅ RESULTADO: Conectividad exitosa con el SRI\n";
        echo "\n";
        echo "🎉 ¡Todo está listo para empezar a generar facturas!\n";
        echo "\n";
        echo "Siguiente paso:\n";
        echo "1. Asegúrate de tener tu certificado digital (.p12)\n";
        echo "2. Configura el RUC correcto en config/sri.php\n";
        echo "3. Ejecuta una factura de prueba\n";
        exit(0);
    } else {
        echo "⚠️  RESULTADO: Hay problemas de conectividad\n";
        echo "\n";
        echo "Posibles causas:\n";
        echo "- Firewall bloqueando conexiones SOAP\n";
        echo "- Extensión PHP SOAP no instalada\n";
        echo "- Endpoints del SRI caídos (poco común)\n";
        echo "- Problema de conectividad a internet\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n";
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Detalles técnicos:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
