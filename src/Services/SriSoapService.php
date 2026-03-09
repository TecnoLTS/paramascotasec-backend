<?php

namespace App\Services;

use App\Core\TenantContext;
use SoapClient;
use Exception;

/**
 * Servicio para comunicación SOAP con el SRI Ecuador
 */
class SriSoapService {
    private $config;
    private $environment;
    private $endpoints;
    private $logDir;
    
    public function __construct() {
        $tenantId = TenantContext::id() ?? 'paramascotasec';
        $sriConfig = require __DIR__ . '/../../config/sri.php';
        
        if (!isset($sriConfig[$tenantId])) {
            throw new Exception("Configuración SRI no encontrada para tenant: {$tenantId}");
        }
        
        $this->config = $sriConfig[$tenantId];
        $this->environment = $this->config['environment'];
        $this->endpoints = $this->config['endpoints'][$this->environment];
        
        // Configurar directorio de logs
        $this->logDir = __DIR__ . '/../../storage/logs/sri';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }
    }
    
    /**
     * Guarda logs de comunicación SOAP con el SRI
     */
    private function guardarLog(string $operacion, string $claveAcceso, array $datos): void {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "{$timestamp}_{$operacion}_{$claveAcceso}.txt";
            $filepath = $this->logDir . '/' . $filename;
            
            $logContent = "=".str_repeat("=", 78)."=\n";
            $logContent .= "COMUNICACIÓN SRI - " . strtoupper($operacion) . "\n";
            $logContent .= "=".str_repeat("=", 78)."=\n";
            $logContent .= "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n";
            $logContent .= "Ambiente: " . $this->environment . "\n";
            $logContent .= "Clave Acceso: {$claveAcceso}\n";
            $logContent .= "Endpoint: " . ($datos['endpoint'] ?? 'N/A') . "\n";
            $logContent .= "\n";
            
            if (isset($datos['request'])) {
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= "REQUEST (SOAP)\n";
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= $this->formatearXml($datos['request']) . "\n\n";
            }
            
            if (isset($datos['response'])) {
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= "RESPONSE (SOAP)\n";
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= $this->formatearXml($datos['response']) . "\n\n";
            }
            
            if (isset($datos['resultado'])) {
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= "RESULTADO PARSEADO\n";
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= print_r($datos['resultado'], true) . "\n\n";
            }
            
            if (isset($datos['error'])) {
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= "ERROR\n";
                $logContent .= str_repeat("-", 80) . "\n";
                $logContent .= $datos['error'] . "\n\n";
            }
            
            $logContent .= "=".str_repeat("=", 78)."=\n";
            
            file_put_contents($filepath, $logContent);
            error_log("[SRI] Log guardado: {$filename}");
            
        } catch (Exception $e) {
            error_log("[SRI] Error guardando log: " . $e->getMessage());
        }
    }
    
    /**
     * Formatea XML para mejor legibilidad
     */
    private function formatearXml(string $xml): string {
        try {
            $dom = new \DOMDocument('1.0');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $dom->loadXML($xml);
            return $dom->saveXML();
        } catch (Exception $e) {
            return $xml; // Si falla, retornar sin formato
        }
    }
    
    /**
     * Envía un comprobante al SRI para validación
     * 
     * @param string $xmlFirmado XML del comprobante firmado digitalmente
     * @return array ['estado' => 'RECIBIDA|DEVUELTA', 'mensajes' => [...]]
     */
    public function enviarComprobante(string $xmlFirmado): array {
        $claveAcceso = $this->extraerClaveAcceso($xmlFirmado);
        
        try {
            $client = new SoapClient(
                $this->endpoints['recepcion'], 
                $this->config['soap_options']
            );
            
            // ✅ FIX CRÍTICO: NO codificar en base64 manualmente
            // El WSDL define el parámetro como xs:base64Binary, por lo tanto
            // SoapClient lo codificará AUTOMÁTICAMENTE. Si nosotros lo codificamos,
            // se genera DOBLE CODIFICACIÓN y el SRI rechaza con Error 35.
            
            error_log("[SRI-SOAP] 📤 Enviando XML RAW (SoapClient lo codificará automáticamente)");
            error_log("[SRI-SOAP] 📏 Tamaño XML: " . strlen($xmlFirmado) . " bytes");
            error_log("[SRI-SOAP] 🔍 Primeros 80 chars: " . substr($xmlFirmado, 0, 80));
            
            // Preparar parámetros - enviar XML RAW, NO base64
            $params = [
                'xml' => $xmlFirmado  // ✅ SOAP lo codificará por xs:base64Binary
            ];
            
            // Llamar al servicio SOAP
            $response = $client->__soapCall('validarComprobante', [$params]);
            
            // Parsear respuesta
            $estado = $response->RespuestaRecepcionComprobante->estado ?? 'ERROR';
            
            $mensajes = [];
            if (isset($response->RespuestaRecepcionComprobante->comprobantes->comprobante)) {
                $comprobante = $response->RespuestaRecepcionComprobante->comprobantes->comprobante;
                
                if (isset($comprobante->mensajes->mensaje)) {
                    $mensajesRaw = $comprobante->mensajes->mensaje;
                    
                    // Puede ser un solo mensaje o array
                    if (!is_array($mensajesRaw)) {
                        $mensajesRaw = [$mensajesRaw];
                    }
                    
                    foreach ($mensajesRaw as $msg) {
                        $mensajes[] = [
                            'identificador' => $msg->identificador ?? '',
                            'mensaje' => $msg->mensaje ?? '',
                            'tipo' => $msg->tipo ?? 'ERROR',
                            'informacion_adicional' => $msg->informacionAdicional ?? ''
                        ];
                    }
                }
            }
            
            $resultado = [
                'success' => $estado === 'RECIBIDA',
                'estado' => $estado,
                'mensajes' => $mensajes,
                'raw_response' => $response,
                'soap_request' => $client->__getLastRequest(),
                'soap_response' => $client->__getLastResponse()
            ];
            
            // Guardar log
            $this->guardarLog('recepcion', $claveAcceso, [
                'endpoint' => $this->endpoints['recepcion'],
                'request' => $client->__getLastRequest(),
                'response' => $client->__getLastResponse(),
                'resultado' => $resultado
            ]);
            
            return $resultado;
            
        } catch (Exception $e) {
            $resultado = [
                'success' => false,
                'estado' => 'ERROR',
                'mensajes' => [
                    [
                        'identificador' => 'SOAP_ERROR',
                        'mensaje' => $e->getMessage(),
                        'tipo' => 'ERROR'
                    ]
                ],
                'exception' => $e->getMessage()
            ];
            
            // Guardar log de error
            $this->guardarLog('recepcion', $claveAcceso, [
                'endpoint' => $this->endpoints['recepcion'],
                'error' => $e->getMessage() . "\n\nStack Trace:\n" . $e->getTraceAsString(),
                'resultado' => $resultado
            ]);
            
            return $resultado;
        }
    }
    
    /**
     * Extrae la clave de acceso del XML
     */
    private function extraerClaveAcceso(string $xml): string {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $claveAccesoNode = $dom->getElementsByTagName('claveAcceso')->item(0);
            return $claveAccesoNode ? $claveAccesoNode->nodeValue : 'DESCONOCIDA';
        } catch (Exception $e) {
            return 'DESCONOCIDA';
        }
    }
    
    /**
     * Consulta el estado de autorización de un comprobante
     * 
     * @param string $claveAcceso Clave de acceso de 49 dígitos
     * @return array ['estado' => 'AUTORIZADO|NO_AUTORIZADO|EN_PROCESAMIENTO', ...]
     */
    public function consultarAutorizacion(string $claveAcceso): array {
        try {
            $client = new SoapClient(
                $this->endpoints['autorizacion'],
                $this->config['soap_options']
            );
            
            // Preparar parámetros
            $params = [
                'claveAccesoComprobante' => $claveAcceso
            ];
            
            // Llamar al servicio SOAP
            $response = $client->__soapCall('autorizacionComprobante', [$params]);
            
            // Parsear respuesta
            $autorizaciones = $response->RespuestaAutorizacionComprobante->autorizaciones ?? null;
            
            if (!$autorizaciones || !isset($autorizaciones->autorizacion)) {
                $resultado = [
                    'success' => false,
                    'estado' => 'NO_ENCONTRADO',
                    'mensajes' => [['mensaje' => 'No se encontró información de autorización']]
                ];
                
                // Guardar log
                $this->guardarLog('autorizacion', $claveAcceso, [
                    'endpoint' => $this->endpoints['autorizacion'],
                    'request' => $client->__getLastRequest(),
                    'response' => $client->__getLastResponse(),
                    'resultado' => $resultado
                ]);
                
                return $resultado;
            }
            
            $autorizacion = $autorizaciones->autorizacion;
            
            // Puede ser array si hay múltiples respuestas
            if (is_array($autorizacion)) {
                $autorizacion = $autorizacion[0];
            }
            
            $estado = $autorizacion->estado ?? 'DESCONOCIDO';
            $numeroAutorizacion = $autorizacion->numeroAutorizacion ?? null;
            $fechaAutorizacion = $autorizacion->fechaAutorizacion ?? null;
            $ambiente = $autorizacion->ambiente ?? $this->environment;
            $comprobante = $autorizacion->comprobante ?? null;
            
            // Extraer mensajes
            $mensajes = [];
            if (isset($autorizacion->mensajes->mensaje)) {
                $mensajesRaw = $autorizacion->mensajes->mensaje;
                
                if (!is_array($mensajesRaw)) {
                    $mensajesRaw = [$mensajesRaw];
                }
                
                foreach ($mensajesRaw as $msg) {
                    $mensajes[] = [
                        'identificador' => $msg->identificador ?? '',
                        'mensaje' => $msg->mensaje ?? '',
                        'tipo' => $msg->tipo ?? 'INFORMATIVO',
                        'informacion_adicional' => $msg->informacionAdicional ?? ''
                    ];
                }
            }
            
            $resultado = [
                'success' => $estado === 'AUTORIZADO',
                'estado' => $estado,
                'numero_autorizacion' => $numeroAutorizacion,
                'fecha_autorizacion' => $fechaAutorizacion,
                'ambiente' => $ambiente,
                'xml_autorizado' => $comprobante,
                'mensajes' => $mensajes,
                'raw_response' => $response,
                'soap_request' => $client->__getLastRequest(),
                'soap_response' => $client->__getLastResponse()
            ];
            
            // Guardar log
            $this->guardarLog('autorizacion', $claveAcceso, [
                'endpoint' => $this->endpoints['autorizacion'],
                'request' => $client->__getLastRequest(),
                'response' => $client->__getLastResponse(),
                'resultado' => $resultado
            ]);
            
            return $resultado;
            
        } catch (Exception $e) {
            $resultado = [
                'success' => false,
                'estado' => 'ERROR',
                'mensajes' => [
                    [
                        'identificador' => 'SOAP_ERROR',
                        'mensaje' => $e->getMessage(),
                        'tipo' => 'ERROR'
                    ]
                ],
                'exception' => $e->getMessage()
            ];
            
            // Guardar log de error
            $this->guardarLog('autorizacion', $claveAcceso, [
                'endpoint' => $this->endpoints['autorizacion'],
                'error' => $e->getMessage() . "\n\nStack Trace:\n" . $e->getTraceAsString(),
                'resultado' => $resultado
            ]);
            
            return $resultado;
        }
    }
    
    /**
     * Envía y espera autorización de un comprobante (flujo completo)
     * 
     * @param string $xmlFirmado XML firmado
     * @param string $claveAcceso Clave de acceso
     * @param int $maxIntentos Máximo de reintentos para autorización
     * @param int $delaySegundos Segundos entre reintentos
     * @return array
     */
    public function enviarYAutorizar(
        string $xmlFirmado, 
        string $claveAcceso,
        int $maxIntentos = 10,
        int $delaySegundos = 3
    ): array {
        
        // Paso 1: Enviar a recepción
        $recepcion = $this->enviarComprobante($xmlFirmado);
        
        // Error 70: "CLAVE EN PROCESAMIENTO" significa que el SRI ya la tiene
        // No es un error fatal, debemos consultar autorización
        $error70 = false;
        if (!$recepcion['success']) {
            // Verificar si es Error 70
            foreach ($recepcion['mensajes'] as $msg) {
                if ($msg['identificador'] == '70') {
                    error_log("[SRI] ⚠️ Error 70 detectado: Clave en procesamiento. Consultando autorización...");
                    $error70 = true;
                    break;
                }
            }
            
            // Si NO es Error 70, fallar inmediatamente
            if (!$error70) {
                return [
                    'success' => false,
                    'paso' => 'recepcion',
                    'resultado' => $recepcion
                ];
            }
        }
        
        // Paso 2: Esperar y consultar autorización
        $intentos = 0;
        $autorizado = false;
        $autorizacion = null;
        
        while ($intentos < $maxIntentos && !$autorizado) {
            $intentos++;
            
            // Esperar antes de consultar
            sleep($delaySegundos);
            
            // Consultar autorización
            $autorizacion = $this->consultarAutorizacion($claveAcceso);
            
            if ($autorizacion['estado'] === 'AUTORIZADO') {
                $autorizado = true;
                break;
            }
            
            if ($autorizacion['estado'] === 'NO_AUTORIZADO') {
                // Ya fue rechazado, no reintentar
                break;
            }
            
            // Si está EN_PROCESAMIENTO, continuar reintentando
        }
        
        return [
            'success' => $autorizado,
            'paso' => 'autorizacion',
            'intentos' => $intentos,
            'recepcion' => $recepcion,
            'autorizacion' => $autorizacion
        ];
    }
    
    /**
     * Valida la conectividad con los servicios del SRI
     * 
     * @return array
     */
    public function validarConexion(): array {
        $resultados = [];
        
        // Validar servicio de recepción
        try {
            $client = new SoapClient(
                $this->endpoints['recepcion'],
                array_merge($this->config['soap_options'], [
                    'connection_timeout' => 5
                ])
            );
            
            $functions = $client->__getFunctions();
            $resultados['recepcion'] = [
                'disponible' => true,
                'endpoint' => $this->endpoints['recepcion'],
                'metodos' => $functions
            ];
        } catch (Exception $e) {
            $resultados['recepcion'] = [
                'disponible' => false,
                'endpoint' => $this->endpoints['recepcion'],
                'error' => $e->getMessage()
            ];
        }
        
        // Validar servicio de autorización
        try {
            $client = new SoapClient(
                $this->endpoints['autorizacion'],
                array_merge($this->config['soap_options'], [
                    'connection_timeout' => 5
                ])
            );
            
            $functions = $client->__getFunctions();
            $resultados['autorizacion'] = [
                'disponible' => true,
                'endpoint' => $this->endpoints['autorizacion'],
                'metodos' => $functions
            ];
        } catch (Exception $e) {
            $resultados['autorizacion'] = [
                'disponible' => false,
                'endpoint' => $this->endpoints['autorizacion'],
                'error' => $e->getMessage()
            ];
        }
        
        return $resultados;
    }
    
    /**
     * Obtiene información del ambiente configurado
     * 
     * @return array
     */
    public function getEnvironmentInfo(): array {
        return [
            'environment' => $this->environment,
            'endpoints' => $this->endpoints,
            'emisor' => $this->config['emisor'],
            'certificado_path' => $this->config['certificado']['path'],
            'certificado_exists' => file_exists($this->config['certificado']['path'])
        ];
    }
}
