<?php

namespace App\Services;

use App\Core\TenantContext;
use Exception;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use DOMDocument;

/**
 * Servicio para firmar digitalmente XMLs de comprobantes electrónicos
 * usando certificados digitales .p12 del SRI Ecuador
 */
class SriXmlSigner {
    
    private array $config;
    private string $certPath;
    private string $certPassword;
    
    public function __construct() {
        $tenantId = TenantContext::id() ?? 'paramascotasec';
        $sriConfig = require __DIR__ . '/../../config/sri.php';
        
        if (!isset($sriConfig[$tenantId])) {
            throw new Exception("Configuración SRI no encontrada para tenant: {$tenantId}");
        }
        
        $this->config = $sriConfig[$tenantId];
        $this->certPath = $this->config['certificado']['path'];
        $this->certPassword = $this->config['certificado']['password'];
        
        // Validar que el certificado existe
        if (!file_exists($this->certPath)) {
            throw new Exception("Certificado no encontrado en: {$this->certPath}");
        }
        
        // Validar que existe la contraseña
        if (empty($this->certPassword)) {
            throw new Exception("Contraseña del certificado no configurada. Define SRI_CERT_PASSWORD en .env");
        }
    }
    
    /**
     * Firma un XML con el certificado digital
     * 
     * @param string $xmlPath Ruta al archivo XML sin firmar
     * @return array ['success' => bool, 'xml_firmado' => string, 'xml_path' => string, 'error' => string]
     */
    public function firmarXml(string $xmlPath): array {
        try {
            if (!file_exists($xmlPath)) {
                throw new Exception("Archivo XML no encontrado: {$xmlPath}");
            }
            
            // Cargar el XML
            $xmlContent = file_get_contents($xmlPath);
            if ($xmlContent === false) {
                throw new Exception("No se pudo leer el archivo XML: {$xmlPath}");
            }
            
            // Firmar el contenido
            $xmlFirmado = $this->firmarContenidoXml($xmlContent);
            
            // DEBUG: Verificar cuántas Signatures hay inmediatamente después de firmar
            // Contar solo elementos Signature raíz, no SignatureValue ni SignatureMethod
            $numSignatures = substr_count($xmlFirmado, '<ds:Signature ') + substr_count($xmlFirmado, '<ds:Signature>')
;
            error_log("[SRI-SIGNER] 🔍 XML firmado creado: " . strlen($xmlFirmado) . " bytes, {$numSignatures} Signature(s) raíz");
            if ($numSignatures !== 1) {
                error_log("[SRI-SIGNER] ⚠️ ERROR CRÍTICO: Se esperaba 1 Signature raíz, se obtuvieron {$numSignatures}");
            }
            
            // Validación local opcional: puede colgarse en algunos entornos de xmlseclibs.
            // Por defecto se desactiva para no bloquear firma/envío al SRI.
            $enableLocalValidation = strtolower((string)getenv('SRI_LOCAL_SIGNATURE_VALIDATION')) === 'true';
            if ($enableLocalValidation) {
                error_log('[SRI-DEBUG] Iniciando validación local de firma...');
                try {
                    $isValidLocal = $this->validarFirma($xmlFirmado);
                    error_log('[SRI-DEBUG] Validación local final: ' . ($isValidLocal ? '✅ OK' : '❌ FAIL'));
                    if (!$isValidLocal) {
                        error_log('[SRI-DEBUG] ⚠️ ADVERTENCIA: La firma no pasa validación local. El SRI probablemente rechazará.');
                    }
                } catch (Exception $e) {
                    error_log('[SRI-DEBUG] ❌ ERROR EN VALIDACIÓN LOCAL: ' . $e->getMessage());
                    error_log('[SRI-DEBUG] Stack trace: ' . $e->getTraceAsString());
                }
            } else {
                error_log('[SRI-DEBUG] Validación local deshabilitada (SRI_LOCAL_SIGNATURE_VALIDATION!=true)');
            }
            error_log('[SRI-DEBUG] Continuando con guardado del XML firmado...');
            
            // Guardar el XML firmado
            $signedPath = $this->guardarXmlFirmado($xmlPath, $xmlFirmado);
            
            // Verificar que el archivo guardado también tiene 1 Signature raíz
            $fileContent = file_get_contents($signedPath);
            $fileSigs = substr_count($fileContent, '<ds:Signature ') + substr_count($fileContent, '<ds:Signature>');
            error_log("[SRI-SIGNER] 🔍 Archivo guardado: {$fileSigs} Signature(s) raíz");
            if ($fileSigs !== $numSignatures) {
                error_log("[SRI-SIGNER] ⚠️ MISMATCH: String tiene {$numSignatures} pero archivo tiene {$fileSigs}");
            }
            
            // LOG TEMPORAL: Guardar copia del XML para comparar con ejemplo del SRI
            $debugPath = dirname($signedPath) . '/DEBUG_' . basename($signedPath);
            file_put_contents($debugPath, $xmlFirmado);
            error_log("[SRI] 🔍 XML DEBUG guardado en: {$debugPath}");
            
            error_log("[SRI] ✅ XML firmado correctamente: {$signedPath}");
            
            return [
                'success' => true,
                'xml_firmado' => $xmlFirmado,
                'xml_path' => $signedPath,
                'error' => null
            ];
            
        } catch (Exception $e) {
            error_log("[SRI] ❌ Error firmando XML: " . $e->getMessage());
            return [
                'success' => false,
                'xml_firmado' => null,
                'xml_path' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Firma el contenido de un XML con estructura XAdES_BES completa según SRI
     * 
     * @param string $xmlContent Contenido XML a firmar
     * @return string XML firmado
     * @throws Exception Si hay errores durante la firma
     */
    public function firmarContenidoXml(string $xmlContent): string {
        try {
            // Cargar certificado .p12
            $certInfo = $this->cargarCertificado();
            
            // Crear documento DOM
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = false;
            $doc->formatOutput = false;
            
            if (!$doc->loadXML($xmlContent)) {
                throw new Exception("XML inválido, no se pudo parsear");
            }
            
            // Verificar que el root tenga id="comprobante" (minúscula)
            // El SRI rechaza 'Id' (mayúscula) pero acepta 'id' (minúscula)
            $root = $doc->documentElement;
            if (!$root->hasAttribute('id')) {
                $root->setAttribute('id', 'comprobante');
                error_log('[SRI-DEBUG] id="comprobante" (minúscula) agregado al root');
            } else {
                error_log('[SRI-DEBUG] id="comprobante" ya existe en el root');
            }
            
            // DEBUG: Loguear el root 
            error_log('[SRI-DEBUG] Root antes de firmar (primeros 200 chars): ' . substr($doc->saveXML($root), 0, 200));
            
            // Generar IDs únicos para la firma (usando números aleatorios como el SRI)
            $signatureId = 'Signature' . rand(100000, 999999);
            $signedInfoId = 'Signature-SignedInfo' . rand(100000, 999999);
            $signatureValueId = 'SignatureValue' . rand(100000, 999999);
            $certificateId = 'Certificate' . rand(1000000, 9999999);
            $signedPropertiesId = $signatureId . '-SignedProperties' . rand(100000, 999999);
            $objectId = $signatureId . '-Object' . rand(100000, 999999);
            $referenceId = 'Reference-ID-' . rand(1000, 9999);
            $signedPropertiesRefId = 'SignedPropertiesID' . rand(100000, 999999);
            
            // Crear la firma manualmente para tener control total de la estructura
            $xmlFirmado = $this->crearFirmaXAdESCompleta(
                $doc,
                $certInfo,
                $signatureId,
                $signedInfoId,
                $signatureValueId,
                $certificateId,
                $signedPropertiesId,
                $objectId,
                $referenceId,
                $signedPropertiesRefId
            );
            
            // LOG DEBUG: Verificar tamaño del XML generado
            error_log("[SRI-DEBUG] XML firmado generado - Tamaño: " . strlen($xmlFirmado) . " bytes");
            error_log("[SRI-DEBUG] Número de '<?xml version': " . substr_count($xmlFirmado, '<?xml version'));
            error_log("[SRI-DEBUG] Primeros 100 caracteres: " . substr($xmlFirmado, 0, 100));
            error_log("[SRI-DEBUG] Últimos 100 caracteres: " . substr($xmlFirmado, -100));
            
            if ($xmlFirmado === false || empty($xmlFirmado)) {
                throw new Exception("Error al generar el XML firmado");
            }
            
            return $xmlFirmado;
            
        } catch (Exception $e) {
            error_log("[SRI] ❌ Error firmando XML: " . $e->getMessage());
            throw new Exception("Error al firmar XML: " . $e->getMessage());
        }
    }
    
    /**
     * Carga el certificado .p12 y extrae la clave privada y el certificado
     * 
     * @return array ['pkey' => clave_privada, 'cert' => certificado]
     * @throws Exception Si no se puede cargar el certificado
     */
    private function cargarCertificado(): array {
        // Leer el archivo .p12
        $p12Content = file_get_contents($this->certPath);
        if ($p12Content === false) {
            throw new Exception("No se pudo leer el certificado: {$this->certPath}");
        }
        
        // Extraer certificado y clave privada
        $certs = [];
        $success = openssl_pkcs12_read($p12Content, $certs, $this->certPassword);
        
        if (!$success) {
            $error = openssl_error_string();
            throw new Exception("Error al leer el certificado .p12. Verifica la contraseña. OpenSSL: {$error}");
        }
        
        if (!isset($certs['pkey']) || !isset($certs['cert'])) {
            throw new Exception("El certificado .p12 no contiene clave privada o certificado válidos");
        }
        
        // Validar que la clave privada es válida
        $keyResource = openssl_pkey_get_private($certs['pkey']);
        if ($keyResource === false) {
            throw new Exception("La clave privada del certificado es inválida");
        }
        openssl_free_key($keyResource);
        
        return [
            'pkey' => $certs['pkey'],
            'cert' => $certs['cert']
        ];
    }
    
    /**
     * Crea una firma XAdES_BES completa según la estructura requerida por el SRI
     * 
     * Basado en el ejemplo de factura autorizada del SRI, la estructura debe ser:
     * 1. SignedInfo con 3 referencias: SignedProperties, Certificate, Comprobante
     * 2. SignatureValue
     * 3. KeyInfo con ID, que contiene X509Data y KeyValue
     * 4. Object con QualifyingProperties completo
     * 
     * @param DOMDocument $doc Documento XML a firmar
     * @param array $certInfo Información del certificado ['pkey', 'cert']
     * @param string $signatureId ID para el elemento Signature
     * @param string $signedInfoId ID para SignedInfo
     * @param string $signatureValueId ID para SignatureValue
     * @param string $certificateId ID para KeyInfo
     * @param string $signedPropertiesId ID para SignedProperties
     * @param string $objectId ID para Object
     * @param string $referenceId ID para la referencia al comprobante
     * @param string $signedPropertiesRefId ID para la referencia a SignedProperties
     * @return string XML firmado completo
     * @throws Exception Si hay errores durante la firma
     */
    private function crearFirmaXAdESCompleta(
        DOMDocument $doc,
        array $certInfo,
        string $signatureId,
        string $signedInfoId,
        string $signatureValueId,
        string $certificateId,
        string $signedPropertiesId,
        string $objectId,
        string $referenceId,
        string $signedPropertiesRefId
    ): string {
        // Parsear certificado
        $certData = openssl_x509_read($certInfo['cert']);
        if ($certData === false) {
            throw new Exception("No se pudo leer el certificado");
        }
        
        // Exportar certificado a PEM y DER
        openssl_x509_export($certData, $certPEMStr);
        $certDER = base64_decode(str_replace([
            '-----BEGIN CERTIFICATE-----',
            '-----END CERTIFICATE-----',
            "\n", "\r", ' '
        ], '', $certPEMStr));
        $certInfo509 = openssl_x509_parse($certData);
        
        // Calcular digest del certificado completo (para SigningCertificate)
        $certSHA1 = base64_encode(sha1($certDER, true));
        
        // Extraer clave pública para KeyValue
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_public($certData));
        $modulus = base64_encode($publicKey['rsa']['n']);
        $exponent = base64_encode($publicKey['rsa']['e']);
        
        // ===== PASO 1: Crear estructura SignedProperties (sin firmar aún) =====
        $signedPropertiesXml = $this->crearSignedProperties(
            $signedPropertiesId,
            $certSHA1,
            $certInfo509,
            $referenceId
        );
        
        // Calcular digest de SignedProperties
        $signedPropertiesC14N = $this->canonicalize($signedPropertiesXml);
        $signedPropertiesDigest = base64_encode(sha1($signedPropertiesC14N, true));
        
        // ===== PASO 2: Crear KeyInfo con certificado =====
        $keyInfoXml = $this->crearKeyInfo(
            $certificateId,
            $certPEMStr,
            $modulus,
            $exponent
        );
        
        // Calcular digest de KeyInfo (todo el elemento)
        $keyInfoC14N = $this->canonicalize($keyInfoXml);
        $keyInfoDigest = base64_encode(sha1($keyInfoC14N, true));
        
        // ===== PASO 3: Calcular digest del comprobante =====
        // ✅ FIX CRÍTICO 2: Calcular digest del nodo root directamente, no del documento clonado
        // Esto garantiza que el digest corresponde exactamente al nodo con Id="comprobante"
        $root = $doc->documentElement;
        $comprobanteC14N = $root->C14N(false, false);
        $comprobanteDigest = base64_encode(sha1($comprobanteC14N, true));
        
        error_log('[SRI-DEBUG] Comprobante digest calculado sobre: ' . substr($comprobanteC14N, 0, 150));
        error_log('[SRI-DEBUG] Comprobante digest: ' . $comprobanteDigest);
        
        // ===== PASO 4: Crear SignedInfo con las 3 referencias =====
        $signedInfoXml = $this->crearSignedInfo(
            $signedInfoId,
            $signedPropertiesRefId,
            $signedPropertiesId,
            $signedPropertiesDigest,
            $certificateId,
            $keyInfoDigest,
            $referenceId,
            $comprobanteDigest
        );
        
        // ===== PASO 5: Firmar SignedInfo =====
        $signedInfoC14N = $this->canonicalize($signedInfoXml);
        
        // Firmar con la clave privada usando RSA-SHA1
        $privateKey = openssl_pkey_get_private($certInfo['pkey']);
        if ($privateKey === false) {
            throw new Exception("No se pudo cargar la clave privada");
        }
        
        $signature = '';
        $signSuccess = openssl_sign($signedInfoC14N, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        openssl_free_key($privateKey);
        
        if (!$signSuccess) {
            throw new Exception("Error al firmar SignedInfo: " . openssl_error_string());
        }
        
        $signatureValue = base64_encode($signature);
        
        // ===== PASO 6: Ensamblar la firma completa =====
        $signatureXml = $this->ensamblarSignatureCompleta(
            $signatureId,
            $signedInfoId,
            $signedInfoXml,
            $signatureValueId,
            $signatureValue,
            $keyInfoXml,
            $objectId,
            $signatureId,
            $signedPropertiesXml
        );
        
        // ===== PASO 7: Insertar firma en el documento =====
        $docFinal = new DOMDocument('1.0', 'UTF-8');
        $docFinal->preserveWhiteSpace = false;
        $docFinal->formatOutput = false;
        $docFinal->loadXML($doc->saveXML());
        
        // Cargar el nodo Signature
        $signatureDoc = new DOMDocument();
        $signatureDoc->loadXML($signatureXml);
        $signatureNode = $docFinal->importNode($signatureDoc->documentElement, true);
        
        // Agregar Signature como último hijo del elemento raíz
        $docFinal->documentElement->appendChild($signatureNode);
        
        // DEBUG: Verificar el contenido ANTES del saveXML
        $numFacturas = $docFinal->getElementsByTagName('factura')->length;
        error_log("[SRI-DEBUG-DOM] El DOMDocument tiene {$numFacturas} elementos <factura>");
        
        $xmlFinal = $docFinal->saveXML();
        
        // DEBUG: Verificar el string DESPUÉS del saveXML
        $numFacturasEnString = substr_count($xmlFinal, '<factura ');
        error_log("[SRI-DEBUG-STRING] El string tiene {$numFacturasEnString} aperturas de <factura>");
        error_log("[SRI-DEBUG-STRING] Longitud del string: " . strlen($xmlFinal) . " bytes");
        
        // ✅ FIX CRÍTICO: NO modificar el XML después de firmar
        // El preg_replace que eliminaba espacios INVALIDABA la firma digital
        // porque alteraba el contenido ya firmado
        
        // LOG: Guardar copia del Signature para comparación
        error_log("[SRI-DEBUG] ===== ESTRUCTURA SIGNATURE GENERADA =====");
        error_log("[SRI-DEBUG] Signature ID: {$signatureId}");
        error_log("[SRI-DEBUG] SignedInfo ID: {$signedInfoId}");
        error_log("[SRI-DEBUG] Certificate ID: {$certificateId}");
        error_log("[SRI-DEBUG] SignedProperties ID: {$signedPropertiesId}");
        
        // Verificar namespaces en el XML final
        if (preg_match('/<ds:Signature[^>]*xmlns:ds="[^"]*"[^>]*xmlns:etsi="[^"]*"/', $xmlFinal)) {
            error_log("[SRI-DEBUG] ✅ Ambos namespaces declarados en Signature");
        } else {
            error_log("[SRI-DEBUG] ⚠️ Problema con declaración de namespaces");
        }
        
        // Contar referencias en SignedInfo
        $refCount = substr_count($xmlFinal, '<ds:Reference');
        error_log("[SRI-DEBUG] Referencias en SignedInfo: {$refCount}");
        if ($refCount === 3) {
            error_log("[SRI-DEBUG] ✅ Tres referencias presentes");
        } else {
            error_log("[SRI-DEBUG] ⚠️ Número de referencias incorrecto (esperado: 3, actual: {$refCount})");
        }
        
        return $xmlFinal;
    }
    
    /**
     * Crea el elemento SignedProperties para XAdES_BES
     * 
     * IMPORTANTE: Los elementos ds: necesitan xmlns:ds para poder ser parseados y canonicalizados
     * independientemente, pero al insertarse en el Signature se eliminan las declaraciones redundantes
     */
    private function crearSignedProperties(
        string $id,
        string $certDigest,
        array $certInfo,
        string $referenceId
    ): string {
        $issuerDN = $this->formatDN($certInfo['issuer']);
        $serialNumber = $certInfo['serialNumber'];
        $signingTime = date('Y-m-d\TH:i:sP');
        
        // Declarar namespaces para parseo y canonicalización, se eliminarán al ensamblar
        return <<<XML
<etsi:SignedProperties xmlns:etsi="http://uri.etsi.org/01903/v1.3.2#" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="{$id}"><etsi:SignedSignatureProperties><etsi:SigningTime>{$signingTime}</etsi:SigningTime><etsi:SigningCertificate><etsi:Cert><etsi:CertDigest><ds:DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"></ds:DigestMethod><ds:DigestValue>{$certDigest}</ds:DigestValue></etsi:CertDigest><etsi:IssuerSerial><ds:X509IssuerName>{$issuerDN}</ds:X509IssuerName><ds:X509SerialNumber>{$serialNumber}</ds:X509SerialNumber></etsi:IssuerSerial></etsi:Cert></etsi:SigningCertificate></etsi:SignedSignatureProperties><etsi:SignedDataObjectProperties><etsi:DataObjectFormat ObjectReference="#{$referenceId}"><etsi:Description>comprobante</etsi:Description><etsi:MimeType>text/xml</etsi:MimeType></etsi:DataObjectFormat></etsi:SignedDataObjectProperties></etsi:SignedProperties>
XML;
    }
    
    /**
     * Crea el elemento KeyInfo con X509Data y KeyValue
     */
    private function crearKeyInfo(
        string $id,
        string $certPEM,
        string $modulus,
        string $exponent
    ): string {
        // Limpiar certificado PEM
        $certClean = str_replace([
            '-----BEGIN CERTIFICATE-----',
            '-----END CERTIFICATE-----',
            "\n", "\r", ' '
        ], '', $certPEM);
        
        return <<<XML
<ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="{$id}"><ds:X509Data><ds:X509Certificate>{$certClean}</ds:X509Certificate></ds:X509Data><ds:KeyValue><ds:RSAKeyValue><ds:Modulus>{$modulus}</ds:Modulus><ds:Exponent>{$exponent}</ds:Exponent></ds:RSAKeyValue></ds:KeyValue></ds:KeyInfo>
XML;
    }
    
    /**
     * Crea el elemento SignedInfo con las 3 referencias requeridas
     */
    private function crearSignedInfo(
        string $id,
        string $signedPropertiesRefId,
        string $signedPropertiesURI,
        string $signedPropertiesDigest,
        string $certificateURI,
        string $certificateDigest,
        string $comprobanteRefId,
        string $comprobanteDigest
    ): string {
        return <<<XML
<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="{$id}"><ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"></ds:CanonicalizationMethod><ds:SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"></ds:SignatureMethod><ds:Reference Id="{$signedPropertiesRefId}" Type="http://uri.etsi.org/01903#SignedProperties" URI="#{$signedPropertiesURI}"><ds:DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"></ds:DigestMethod><ds:DigestValue>{$signedPropertiesDigest}</ds:DigestValue></ds:Reference><ds:Reference URI="#{$certificateURI}"><ds:DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"></ds:DigestMethod><ds:DigestValue>{$certificateDigest}</ds:DigestValue></ds:Reference><ds:Reference Id="{$comprobanteRefId}" URI="#comprobante"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"></ds:Transform></ds:Transforms><ds:DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"></ds:DigestMethod><ds:DigestValue>{$comprobanteDigest}</ds:DigestValue></ds:Reference></ds:SignedInfo>
XML;
    }
    
    /**
     * Ensambla el elemento Signature completo usando DOM para evitar namespaces redundantes
     * 
     * Construye la estructura exacta requerida por el SRI:
     * - Namespaces declarados solo en el elemento Signature raíz
     * - Tres referencias en SignedInfo
     * - SignatureValue con Id
     * - KeyInfo con Id
     * - Object con QualifyingProperties
     */
    private function ensamblarSignatureCompleta(
        string $signatureId,
        string $signedInfoId,
        string $signedInfoXml,
        string $signatureValueId,
        string $signatureValue,
        string $keyInfoXml,
        string $objectId,
        string $qualifyingPropertiesTarget,
        string $signedPropertiesXml
    ): string {
        // Crear documento para Signature
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        
        // Crear elemento Signature con ambos namespaces declarados
        $signature = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
        $signature->setAttribute('Id', $signatureId);
        $signature->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:etsi',
            'http://uri.etsi.org/01903/v1.3.2#'
        );
        $doc->appendChild($signature);
        
        // Importar SignedInfo directamente
        $signedInfoDoc = new DOMDocument();
        $signedInfoDoc->loadXML($signedInfoXml);
        $signedInfoNode = $doc->importNode($signedInfoDoc->documentElement, true);
        $signature->appendChild($signedInfoNode);
        
        // Crear SignatureValue
        $signatureValueNode = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureValue', $signatureValue);
        $signatureValueNode->setAttribute('Id', $signatureValueId);
        $signature->appendChild($signatureValueNode);
        
        // Importar KeyInfo directamente
        $keyInfoDoc = new DOMDocument();
        $keyInfoDoc->loadXML($keyInfoXml);
        $keyInfoNode = $doc->importNode($keyInfoDoc->documentElement, true);
        $signature->appendChild($keyInfoNode);
        
        // Crear Object con QualifyingProperties
        $objectNode = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Object');
        $objectNode->setAttribute('Id', $objectId);
        
        // Crear QualifyingProperties
        $qualifyingProps = $doc->createElement('etsi:QualifyingProperties');
        $qualifyingProps->setAttribute('Target', "#{$qualifyingPropertiesTarget}");
        
        // Importar SignedProperties directamente
        $signedPropsDoc = new DOMDocument();
        $signedPropsDoc->loadXML($signedPropertiesXml);
        $signedPropsNode = $doc->importNode($signedPropsDoc->documentElement, true);
        $qualifyingProps->appendChild($signedPropsNode);
        
        $objectNode->appendChild($qualifyingProps);
        $signature->appendChild($objectNode);
        
        return $doc->saveXML($signature);
    }
    
    /**
     * Canonicaliza un fragmento XML usando C14N
     */
    private function canonicalize(string $xml): string {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML($xml);
        return $doc->C14N(false, false);
    }
    
    /**
     * Agrega elementos XAdES_BES a la firma (requerido por SRI)
     * Según ficha técnica sección 6.1 y 6.2: XAdES_BES versión 1.3.2
     * 
     * OBSOLETO: Este método ya no se usa, se reemplazó por crearFirmaXAdESCompleta
     * Se mantiene por compatibilidad pero no debe llamarse
     * 
     * @param DOMDocument $doc Documento con la firma básica
     * @param string $certPEM Certificado en formato PEM
     * @throws Exception Si no se pueden agregar los elementos XAdES
     */
    private function agregarElementoXAdES(DOMDocument $doc, string $certPEM): void {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        
        // Localizar el nodo Signature
        $signatureNodes = $xpath->query('//ds:Signature');
        if ($signatureNodes->length === 0) {
            throw new Exception("No se encontró el nodo Signature para agregar XAdES");
        }
        $signatureNode = $signatureNodes->item(0);
        
        // Localizar el nodo KeyInfo para insertar Object antes de él
        $keyInfoNodes = $xpath->query('ds:KeyInfo', $signatureNode);
        if ($keyInfoNodes->length === 0) {
            throw new Exception("No se encontró el nodo KeyInfo");
        }
        $keyInfoNode = $keyInfoNodes->item(0);
        
        // Crear elemento Object para XAdES
        $objectNode = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Object');
        
        // Crear QualifyingProperties
        $qualProps = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:QualifyingProperties');
        $qualProps->setAttribute('Target', '#signature');
        
        // Crear SignedProperties
        $signedProps = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SignedProperties');
        $signedProps->setAttribute('Id', 'Signature-SignedProperties');
        
        // Crear SignedSignatureProperties
        $signedSigProps = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SignedSignatureProperties');
        
        // Agregar SigningTime (fecha y hora actual en formato ISO 8601)
        $signingTime = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SigningTime', 
            date('Y-m-d\TH:i:sP'));
        $signedSigProps->appendChild($signingTime);
        
        // Agregar SigningCertificate
        $signingCert = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SigningCertificate');
        $cert = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:Cert');
        
        // Calcular digest del certificado (SHA1)
        $certDigest = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:CertDigest');
        $digestMethod = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $certDigest->appendChild($digestMethod);
        
        // Extraer certificado en DER y calcular SHA1
        $certData = openssl_x509_read($certPEM);
        if ($certData === false) {
            throw new Exception("No se pudo leer el certificado para XAdES");
        }
        openssl_x509_export($certData, $certPEMStr);
        $certDER = base64_decode(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"], '', $certPEMStr));
        $certSHA1 = base64_encode(sha1($certDER, true));
        
        $digestValue = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue', $certSHA1);
        $certDigest->appendChild($digestValue);
        $cert->appendChild($certDigest);
        
        // Agregar IssuerSerial
        $certInfo = openssl_x509_parse($certData);
        $issuerSerial = $doc->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:IssuerSerial');
        $x509IssuerName = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509IssuerName', 
            $this->formatDN($certInfo['issuer']));
        $x509SerialNumber = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509SerialNumber', 
            $certInfo['serialNumber']);
        $issuerSerial->appendChild($x509IssuerName);
        $issuerSerial->appendChild($x509SerialNumber);
        $cert->appendChild($issuerSerial);
        
        $signingCert->appendChild($cert);
        $signedSigProps->appendChild($signingCert);
        
        // Ensamblar estructura
        $signedProps->appendChild($signedSigProps);
        $qualProps->appendChild($signedProps);
        $objectNode->appendChild($qualProps);
        
        // Insertar Object DESPUÉS de KeyInfo (según estructura XAdES estándar)
        $signatureNode->appendChild($objectNode);
    }
    
    /**
     * Formatea un DN (Distinguished Name) en formato RFC 2253
     * 
     * @param array $dn Array con componentes del DN
     * @return string DN formateado
     */
    private function formatDN(array $dn): string {
        $components = [];
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C'];
        
        foreach ($order as $key) {
            if (isset($dn[$key])) {
                $value = is_array($dn[$key]) ? implode(', ', $dn[$key]) : $dn[$key];
                $components[] = "{$key}={$value}";
            }
        }
        
        return implode(',', $components);
    }
    
    /**
     * Guarda el XML firmado en la carpeta signed/
     * 
     * @param string $xmlOriginalPath Ruta del XML original
     * @param string $xmlFirmado Contenido del XML firmado
     * @return string Ruta donde se guardó el XML firmado
     * @throws Exception Si no se puede guardar
     */
    private function guardarXmlFirmado(string $xmlOriginalPath, string $xmlFirmado): string {
        $signedDir = dirname(dirname($xmlOriginalPath)) . '/signed';
        
        if (!is_dir($signedDir)) {
            mkdir($signedDir, 0775, true);
        }
        
        $filename = basename($xmlOriginalPath);
        $signedPath = $signedDir . '/' . $filename;
        
        $bytesWritten = file_put_contents($signedPath, $xmlFirmado);
        
        if ($bytesWritten === false) {
            throw new Exception("No se pudo guardar el XML firmado en: {$signedPath}");
        }
        
        // Verificar que el archivo se guardó correctamente
        if (!file_exists($signedPath)) {
            throw new Exception("El archivo firmado no existe después de escribirlo: {$signedPath}");
        }
        
        error_log("[SRI] XML firmado guardado: {$signedPath} ({$bytesWritten} bytes)");
        
        return $signedPath;
    }
    
    /**
     * Valida que un XML esté correctamente firmado
     * 
     * @param string $xmlFirmado Contenido del XML firmado
     * @return bool True si la firma es válida
     */
    public function validarFirma(string $xmlFirmado): bool {
        try {
            error_log("[SRI-VALIDAR] Paso 1: Creando DOMDocument...");
            $doc = new DOMDocument();
            $doc->loadXML($xmlFirmado);
            error_log("[SRI-VALIDAR] Paso 2: DOMDocument cargado correctamente");
            
            $objDSig = new XMLSecurityDSig();
            error_log("[SRI-VALIDAR] Paso 3: XMLSecurityDSig creado");
            
            // Priorizar 'id' minúscula ya que es la que acepta el SRI
            $objDSig->idKeys = ['id', 'Id', 'ID'];
            $objDSig->idNS = [];
            error_log("[SRI-VALIDAR] Paso 4: idKeys configurados");
            
            $signatureNode = $objDSig->locateSignature($doc);
            error_log("[SRI-VALIDAR] Paso 5: locateSignature completado");
            
            if (!$signatureNode) {
                error_log("[SRI] ⚠️ No se encontró nodo de firma en el XML");
                return false;
            }
            error_log("[SRI-VALIDAR] Paso 6: Signature node encontrado");
            
            error_log("[SRI-VALIDAR] Paso 7: Iniciando canonicalizeSignedInfo...");
            $objDSig->canonicalizeSignedInfo();
            error_log("[SRI-VALIDAR] Paso 8: canonicalizeSignedInfo completado");
            
            $objKey = $objDSig->locateKey();
            error_log("[SRI-VALIDAR] Paso 9: locateKey completado");
            if (!$objKey) {
                error_log("[SRI] ⚠️ No se encontró clave en la firma");
                return false;
            }
            
            error_log("[SRI-VALIDAR] Paso 10: Iniciando staticLocateKeyInfo...");
            XMLSecurityDSig::staticLocateKeyInfo($objKey, $signatureNode);
            error_log("[SRI-VALIDAR] Paso 11: staticLocateKeyInfo completado");
            
            error_log("[SRI-VALIDAR] Paso 12: Iniciando verify...");
            $isValid = $objDSig->verify($objKey);
            error_log("[SRI-VALIDAR] Paso 13: verify completado");
            
            if ($isValid) {
                error_log("[SRI] ✅ Firma XML válida");
            } else {
                error_log("[SRI] ❌ Firma XML inválida");
            }
            
            return $isValid;
            
        } catch (Exception $e) {
            error_log("[SRI] ❌ Error validando firma: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene información del certificado
     * 
     * @return array Información del certificado (subject, issuer, validFrom, validTo)
     */
    public function obtenerInfoCertificado(): array {
        try {
            $certInfo = $this->cargarCertificado();
            $certData = openssl_x509_parse($certInfo['cert']);
            
            if ($certData === false) {
                throw new Exception("No se pudo parsear el certificado");
            }
            
            return [
                'subject' => $certData['subject'] ?? [],
                'issuer' => $certData['issuer'] ?? [],
                'validFrom' => isset($certData['validFrom_time_t']) 
                    ? date('Y-m-d H:i:s', $certData['validFrom_time_t']) 
                    : null,
                'validTo' => isset($certData['validTo_time_t']) 
                    ? date('Y-m-d H:i:s', $certData['validTo_time_t']) 
                    : null,
                'serialNumber' => $certData['serialNumber'] ?? null,
                'version' => $certData['version'] ?? null,
            ];
            
        } catch (Exception $e) {
            error_log("[SRI] Error obteniendo info del certificado: " . $e->getMessage());
            throw $e;
        }
    }
}
