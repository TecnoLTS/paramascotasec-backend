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
    
    public function __construct() {
        $tenantId = TenantContext::id() ?? 'paramascotasec';
        $sriConfig = require __DIR__ . '/../../config/sri.php';
        
        if (!isset($sriConfig[$tenantId])) {
            throw new Exception("Configuración SRI no encontrada para tenant: {$tenantId}");
        }
        
        $this->config = $sriConfig[$tenantId];
        $this->environment = $this->config['environment'];
        $this->endpoints = $this->config['endpoints'][$this->environment];
    }
    
    /**
     * Envía un comprobante al SRI para validación
     * 
     * @param string $xmlFirmado XML del comprobante firmado digitalmente
     * @return array ['estado' => 'RECIBIDA|DEVUELTA', 'mensajes' => [...]]
     */
    public function enviarComprobante(string $xmlFirmado): array {
        try {
            $client = new SoapClient(
                $this->endpoints['recepcion'], 
                $this->config['soap_options']
            );
            
            // Convertir XML a Base64 como requiere el SRI
            $xmlBase64 = base64_encode($xmlFirmado);
            
            // Preparar parámetros para el método validarComprobante
            $params = [
                'xml' => $xmlBase64
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
            
            return [
                'success' => $estado === 'RECIBIDA',
                'estado' => $estado,
                'mensajes' => $mensajes,
                'raw_response' => $response,
                'soap_request' => $client->__getLastRequest(),
                'soap_response' => $client->__getLastResponse()
            ];
            
        } catch (Exception $e) {
            return [
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
                return [
                    'success' => false,
                    'estado' => 'NO_ENCONTRADO',
                    'mensajes' => [['mensaje' => 'No se encontró información de autorización']]
                ];
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
            
            return [
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
            
        } catch (Exception $e) {
            return [
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
        
        if (!$recepcion['success']) {
            return [
                'success' => false,
                'paso' => 'recepcion',
                'resultado' => $recepcion
            ];
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
