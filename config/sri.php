<?php

/**
 * Configuración del SRI Ecuador
 * Facturación Electrónica
 */

return [
    // Tenant: paramascotasec
    'paramascotasec' => [
        // Ambiente: 'test' o 'production'
        'environment' => getenv('SRI_ENVIRONMENT') ?: 'test',
        
        // Datos del emisor (RUC registrado en el SRI)
        'emisor' => [
            'ruc' => getenv('SRI_RUC') ?: '1234567890001',
            'razon_social' => 'PARA MASCOTAS ECUADOR S.A.',
            'nombre_comercial' => 'Para Mascotas EC',
            'direccion_matriz' => 'Av. Principal 123 y Secundaria',
            'codigo_establecimiento' => '001',
            'punto_emision' => '001',
            'obligado_contabilidad' => 'NO', // SI o NO
            'contribuyente_especial' => null, // Número o null
            'agente_retencion' => null, // Número o null
            'regimen_microempresas' => 'NO', // SI o NO
        ],
        
        // Certificado de firma electrónica
        'certificado' => [
            'path' => getenv('SRI_CERT_PATH') ?: __DIR__ . '/../storage/sri/certs/certificado.p12',
            'password' => getenv('SRI_CERT_PASSWORD') ?: '',
            'expiration_date' => '2026-12-31', // Para alertas
        ],
        
        // Endpoints SOAP del SRI
        'endpoints' => [
            'test' => [
                'recepcion' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl',
                'autorizacion' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
                'recepcion_online' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantes?wsdl',
                'autorizacion_online' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantes?wsdl',
            ],
            'production' => [
                'recepcion' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl',
                'autorizacion' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
                'recepcion_online' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantes?wsdl',
                'autorizacion_online' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantes?wsdl',
            ],
        ],
        
        // Configuración SOAP (deshabilitada temporalmente hasta implementar envío al SRI)
        'soap_options' => [
            // 'soap_version' => SOAP_1_1,
            // 'trace' => true,
            // 'exceptions' => true,
            // 'connection_timeout' => 30,
            // 'cache_wsdl' => WSDL_CACHE_NONE, // En producción usar WSDL_CACHE_BOTH
            // 'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
        ],
        
        // Configuración de reintentos
        'retry' => [
            'max_attempts' => 3,
            'delay_seconds' => 5,
            'timeout_authorization' => 60, // Segundos para esperar autorización
        ],
        
        // Configuración de secuenciales
        'secuenciales' => [
            'inicial' => 1,
            'longitud' => 9, // 000000001
        ],
    ],
    
    // Tenant: tecnolts
    'tecnolts' => [
        'environment' => getenv('SRI_ENVIRONMENT_TECNOLTS') ?: 'test',
        'emisor' => [
            'ruc' => getenv('SRI_RUC_TECNOLTS') ?: '0987654321001',
            'razon_social' => 'TECNOLTS CIA. LTDA.',
            'nombre_comercial' => 'TecnoLTS',
            'direccion_matriz' => 'Calle Técnica 456',
            'codigo_establecimiento' => '001',
            'punto_emision' => '001',
            'obligado_contabilidad' => 'SI',
            'contribuyente_especial' => null,
            'agente_retencion' => null,
            'regimen_microempresas' => 'NO',
        ],
        'certificado' => [
            'path' => __DIR__ . '/../certs/tecnolts_firma.p12',
            'password' => getenv('SRI_CERT_PASSWORD_TECNOLTS') ?: '',
            'expiration_date' => '2026-12-31',
        ],
        'endpoints' => [
            'test' => [
                'recepcion' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl',
                'autorizacion' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
            ],
            'production' => [
                'recepcion' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl',
                'autorizacion' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
            ],
        ],
        'soap_options' => [
            // 'soap_version' => SOAP_1_1,
            // 'trace' => true,
            // 'exceptions' => true,
            // 'connection_timeout' => 30,
            // 'cache_wsdl' => WSDL_CACHE_NONE,
            // 'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
        ],
        'retry' => [
            'max_attempts' => 3,
            'delay_seconds' => 5,
            'timeout_authorization' => 60,
        ],
        'secuenciales' => [
            'inicial' => 1,
            'longitud' => 9,
        ],
    ],
];
