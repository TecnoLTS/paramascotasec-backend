<?php

namespace App\Services;

use App\Core\TenantContext;

/**
 * Generador de XML para comprobantes electrónicos del SRI Ecuador
 * Genera XML según ficha técnica v2.21 del SRI
 */
class SriXmlGenerator {
    
    private $config;
    private $tenantId;
    
    public function __construct() {
        $this->tenantId = TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
        $sriConfig = require __DIR__ . '/../../config/sri.php';
        $this->config = $sriConfig[$this->tenantId] ?? $sriConfig['paramascotasec'];
    }
    
    /**
     * Genera la clave de acceso de 49 dígitos
     * Formato: DDMMYYYYTTCCCCCCCRRRRRRRRRRRRRRRN
     */
    public function generateAccessKey(array $data): string {
        $fecha = date('dmY', strtotime($data['fecha_emision']));
        $tipoComprobante = $data['tipo_comprobante'] ?? '01'; // 01=Factura
        $ruc = $this->config['emisor']['ruc'];
        $ambiente = $this->config['environment'] === 'production' ? '2' : '1';
        $serie = $this->config['emisor']['codigo_establecimiento'] . $this->config['emisor']['punto_emision'];
        $secuencial = str_pad($data['secuencial'], 9, '0', STR_PAD_LEFT);
        
        // Generar código numérico único pero menos predecible
        // Usamos timestamp + secuencial para garantizar unicidad
        $timestamp = time() % 100000; // Últimos 5 dígitos del timestamp
        $codigoBase = ($timestamp * 1000) + ($data['secuencial'] % 1000);
        $codigoNumerico = str_pad($codigoBase % 100000000, 8, '0', STR_PAD_LEFT);
        
        // Si el código queda muy pequeño, sumarle un offset
        if ($codigoNumerico < 10000000) {
            $codigoNumerico = str_pad(10000000 + intval($codigoNumerico), 8, '0', STR_PAD_LEFT);
        }
        
        $tipoEmision = '1'; // 1=Normal
        
        // Construir 48 dígitos
        $claveBase = $fecha . $tipoComprobante . $ruc . $ambiente . $serie . $secuencial . $codigoNumerico . $tipoEmision;
        
        // Calcular dígito verificador (módulo 11)
        $digito = $this->calcularModulo11($claveBase);
        
        return $claveBase . $digito;
    }
    
    /**
     * Calcula dígito verificador módulo 11 según especificación SRI
     * 
     * Aplica factores de derecha a izquierda: 2, 3, 4, 5, 6, 7 (repite)
     * Ejemplo SRI: cadena "41261533" -> dígito verificador "6"
     * 
     * @param string $cadena Cadena de 48 dígitos (clave de acceso sin dígito verificador)
     * @return string Dígito verificador (0-9)
     */
    private function calcularModulo11(string $cadena): string {
        $factor = 2;
        $suma = 0;
        
        // Calcular de derecha a izquierda
        for ($i = strlen($cadena) - 1; $i >= 0; $i--) {
            $suma += intval($cadena[$i]) * $factor;
            
            // Incrementar factor: 2, 3, 4, 5, 6, 7, 2, 3, ...
            $factor++;
            if ($factor > 7) {
                $factor = 2;
            }
        }
        
        $residuo = $suma % 11;
        $resultado = 11 - $residuo;
        
        // Casos especiales según ficha técnica SRI
        if ($resultado === 11) {
            return '0';
        } elseif ($resultado === 10) {
            return '1';
        }
        
        return (string)$resultado;
    }
    
    /**
     * Genera XML de factura electrónica
     */
    public function generateInvoiceXml(array $orderData): string {
        $emisor = $this->config['emisor'];
        $ambiente = $this->config['environment'] === 'production' ? '2' : '1';
        $tipoEmision = '1'; // 1=Normal
        
        // ✅ FIX: Usar la clave de acceso ya generada en prepareOrderDataForSri
        // No generar nuevamente porque rand() daría un código numérico diferente
        $accessKey = $orderData['access_key'] ?? $this->generateAccessKey([
            'fecha_emision' => $orderData['fecha_emision'],
            'tipo_comprobante' => '01',
            'secuencial' => $orderData['secuencial']
        ]);
        
        // ✅ FIX CRÍTICO: NO formatear el XML para firmas digitales
        // formatOutput=true agrega espacios/saltos que invalidan la firma
        // El XML debe mantenerse compacto para que la firma sea consistente
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;
        $xml->preserveWhiteSpace = false;
        
        // Nodo raíz con namespaces requeridos por el SRI
        $factura = $xml->createElement('factura');
        // Usar 'id' minúscula - el SRI rechaza 'Id' mayúscula pero puede aceptar 'id'
        $factura->setAttribute('id', 'comprobante');
        $factura->setAttribute('version', '1.1.0');  // Versión 1.1.0 como en ejemplos del SRI
        $xml->appendChild($factura);
        
        // Info Tributaria
        $infoTributaria = $xml->createElement('infoTributaria');
        $infoTributaria->appendChild($xml->createElement('ambiente', $ambiente));
        $infoTributaria->appendChild($xml->createElement('tipoEmision', $tipoEmision));
        $infoTributaria->appendChild($xml->createElement('razonSocial', $this->escapeXml($emisor['razon_social'])));
        $infoTributaria->appendChild($xml->createElement('nombreComercial', $this->escapeXml($emisor['nombre_comercial'])));
        $infoTributaria->appendChild($xml->createElement('ruc', $emisor['ruc']));
        $infoTributaria->appendChild($xml->createElement('claveAcceso', $accessKey));
        $infoTributaria->appendChild($xml->createElement('codDoc', '01')); // 01=Factura
        $infoTributaria->appendChild($xml->createElement('estab', $emisor['codigo_establecimiento']));
        $infoTributaria->appendChild($xml->createElement('ptoEmi', $emisor['punto_emision']));
        $infoTributaria->appendChild($xml->createElement('secuencial', str_pad($orderData['secuencial'], 9, '0', STR_PAD_LEFT)));
        $infoTributaria->appendChild($xml->createElement('dirMatriz', $this->escapeXml($emisor['direccion_matriz'])));
        $factura->appendChild($infoTributaria);
        
        // Info Factura
        $infoFactura = $xml->createElement('infoFactura');
        $infoFactura->appendChild($xml->createElement('fechaEmision', date('d/m/Y', strtotime($orderData['fecha_emision']))));
        $infoFactura->appendChild($xml->createElement('dirEstablecimiento', $this->escapeXml($emisor['direccion_matriz'])));
        
        // Contribuyente especial
        if (!empty($emisor['contribuyente_especial'])) {
            $infoFactura->appendChild($xml->createElement('contribuyenteEspecial', $emisor['contribuyente_especial']));
        }
        
        $infoFactura->appendChild($xml->createElement('obligadoContabilidad', $emisor['obligado_contabilidad']));
        
        // Datos del comprador
        $customer = $orderData['customer'];
        $tipoIdentificacion = $this->getTipoIdentificacion($customer['document_type']);
        $infoFactura->appendChild($xml->createElement('tipoIdentificacionComprador', $tipoIdentificacion));
        $infoFactura->appendChild($xml->createElement('razonSocialComprador', $this->escapeXml($customer['name'])));
        $infoFactura->appendChild($xml->createElement('identificacionComprador', $customer['document_number']));
        
        // ❌ CAMPOS OPCIONALES COMENTADOS - El XSD del SRI NO los acepta aquí
        // Según error del SRI: después de identificacionComprador debe ir totalSinImpuestos
        // if (!empty($customer['address'])) {
        //     $infoFactura->appendChild($xml->createElement('direccionComprador', $this->escapeXml($customer['address'])));
        // }
        // if (!empty($customer['phone'])) {
        //     $infoFactura->appendChild($xml->createElement('telefonoComprador', $customer['phone']));
        // }
        // if (!empty($customer['email'])) {
        //     $infoFactura->appendChild($xml->createElement('correoComprador', $customer['email']));
        // }
        
        // Totales
        $infoFactura->appendChild($xml->createElement('totalSinImpuestos', number_format($orderData['subtotal_sin_impuestos'], 2, '.', '')));
        $infoFactura->appendChild($xml->createElement('totalDescuento', number_format($orderData['total_descuento'], 2, '.', '')));
        
        // Total con impuestos
        $totalConImpuestos = $xml->createElement('totalConImpuestos');
        foreach ($orderData['impuestos'] as $impuesto) {
            $totalImpuesto = $xml->createElement('totalImpuesto');
            $totalImpuesto->appendChild($xml->createElement('codigo', $impuesto['codigo'])); // 2=IVA
            $totalImpuesto->appendChild($xml->createElement('codigoPorcentaje', $impuesto['codigo_porcentaje']));
            $totalImpuesto->appendChild($xml->createElement('baseImponible', number_format($impuesto['base_imponible'], 2, '.', '')));
            $totalImpuesto->appendChild($xml->createElement('valor', number_format($impuesto['valor'], 2, '.', '')));
            $totalConImpuestos->appendChild($totalImpuesto);
        }
        $infoFactura->appendChild($totalConImpuestos);
        
        $infoFactura->appendChild($xml->createElement('propina', '0.00'));
        $infoFactura->appendChild($xml->createElement('importeTotal', number_format($orderData['total'], 2, '.', '')));
        $infoFactura->appendChild($xml->createElement('moneda', 'DOLAR'));
        
        // Forma de pago
        $pagos = $xml->createElement('pagos');
        foreach ($orderData['formas_pago'] as $pago) {
            $pagoNode = $xml->createElement('pago');
            $pagoNode->appendChild($xml->createElement('formaPago', $pago['codigo']));
            $pagoNode->appendChild($xml->createElement('total', number_format($pago['total'], 2, '.', '')));
            if (!empty($pago['plazo'])) {
                $pagoNode->appendChild($xml->createElement('plazo', $pago['plazo']));
                $pagoNode->appendChild($xml->createElement('unidadTiempo', $pago['unidad_tiempo']));
            }
            $pagos->appendChild($pagoNode);
        }
        $infoFactura->appendChild($pagos);
        
        $factura->appendChild($infoFactura);
        
        // Detalles
        $detalles = $xml->createElement('detalles');
        foreach ($orderData['items'] as $item) {
            $detalle = $xml->createElement('detalle');
            $detalle->appendChild($xml->createElement('codigoPrincipal', $this->escapeXml($item['codigo'])));
            $detalle->appendChild($xml->createElement('descripcion', $this->escapeXml($item['descripcion'])));
            $detalle->appendChild($xml->createElement('cantidad', number_format($item['cantidad'], 2, '.', '')));
            $detalle->appendChild($xml->createElement('precioUnitario', number_format($item['precio_unitario'], 6, '.', '')));
            $detalle->appendChild($xml->createElement('descuento', number_format($item['descuento'], 2, '.', '')));
            $detalle->appendChild($xml->createElement('precioTotalSinImpuesto', number_format($item['precio_total_sin_impuesto'], 2, '.', '')));
            
            // Impuestos del item
            $impuestos = $xml->createElement('impuestos');
            foreach ($item['impuestos'] as $impuesto) {
                $impuestoNode = $xml->createElement('impuesto');
                $impuestoNode->appendChild($xml->createElement('codigo', $impuesto['codigo']));
                $impuestoNode->appendChild($xml->createElement('codigoPorcentaje', $impuesto['codigo_porcentaje']));
                $impuestoNode->appendChild($xml->createElement('tarifa', $impuesto['tarifa']));
                $impuestoNode->appendChild($xml->createElement('baseImponible', number_format($impuesto['base_imponible'], 2, '.', '')));
                $impuestoNode->appendChild($xml->createElement('valor', number_format($impuesto['valor'], 2, '.', '')));
                $impuestos->appendChild($impuestoNode);
            }
            $detalle->appendChild($impuestos);
            
            $detalles->appendChild($detalle);
        }
        $factura->appendChild($detalles);
        
        // Info Adicional (opcional)
        if (!empty($orderData['info_adicional'])) {
            $infoAdicional = $xml->createElement('infoAdicional');
            foreach ($orderData['info_adicional'] as $key => $value) {
                $campo = $xml->createElement('campoAdicional', $this->escapeXml($value));
                $campo->setAttribute('nombre', $this->escapeXml($key));
                $infoAdicional->appendChild($campo);
            }
            $factura->appendChild($infoAdicional);
        }
        
        // ✅ FIX CRÍTICO: NO modificar el XML después de generarlo
        // DOMDocument::saveXML() genera el formato natural con salto de línea
        // Este formato debe mantenerse consistente durante todo el proceso de firma
        // Cualquier modificación posterior invalida la firma digital
        $xmlString = $xml->saveXML();
        
        return $xmlString;
    }
    
    /**
     * Convierte tipo de documento a código SRI
     */
    private function getTipoIdentificacion(string $documentType): string {
        $map = [
            'ruc' => '04',
            'cedula' => '05',
            'pasaporte' => '06',
            'consumidor_final' => '07'
        ];
        
        return $map[strtolower($documentType)] ?? '07';
    }
    
    /**
     * Escapa caracteres especiales XML
     */
    private function escapeXml(string $text): string {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Obtiene el código de forma de pago según SRI
     */
    public function getFormaPagoCode(string $paymentMethod): string {
        $map = [
            'cash' => '01',              // Sin utilización del sistema financiero
            'card' => '19',              // Tarjeta de crédito
            'debit_card' => '16',        // Tarjeta de débito
            'transfer' => '17',          // Transferencia
            'other_electronic' => '20'   // Otros con utilización del sistema financiero
        ];
        
        return $map[strtolower($paymentMethod)] ?? '20';
    }
}
