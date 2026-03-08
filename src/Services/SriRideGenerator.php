<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Generador de RIDE (Representación Impresa del Documento Electrónico)
 * Genera el PDF oficial de la factura electrónica autorizada por el SRI
 */
class SriRideGenerator {
    
    private Dompdf $dompdf;
    private array $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../../config/sri.php';
        $this->config = $this->config['paramascotasec'];
        
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');
        
        $this->dompdf = new Dompdf($options);
    }
    
    /**
     * Genera el PDF (RIDE) a partir del XML autorizado por el SRI
     * 
     * @param string $xmlAutorizado XML con respuesta de autorización del SRI
     * @param string $logoPath Ruta al logo de la empresa (opcional)
     * @return string Contenido del PDF generado
     */
    public function generarRide(string $xmlAutorizado, ?string $logoPath = null): string {
        // Parsear el XML autorizado
        $xml = simplexml_load_string($xmlAutorizado);
        
        if (!$xml) {
            throw new \Exception('XML autorizado inválido');
        }
        
        // Extraer datos de autorización y comprobante
        $autorizacion = $this->extraerDatosAutorizacion($xml);
        $comprobante = $this->extraerDatosComprobante($xml);
        
        // Generar HTML del RIDE
        $html = $this->generarHtmlRide($autorizacion, $comprobante, $logoPath);
        
        // Configurar DOMPDF
        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper('A4', 'portrait');
        $this->dompdf->render();
        
        return $this->dompdf->output();
    }
    
    /**
     * Extrae los datos de autorización del XML del SRI
     */
    private function extraerDatosAutorizacion(\SimpleXMLElement $xml): array {
        // El SRI devuelve el XML con estructura:
        // <autorizacion>
        //   <estado>AUTORIZADO</estado>
        //   <numeroAutorizacion>49 dígitos</numeroAutorizacion>
        //   <fechaAutorizacion>2026-03-08T10:30:00</fechaAutorizacion>
        //   <ambiente>PRUEBAS|PRODUCCION</ambiente>
        //   <comprobante>XML de la factura</comprobante>
        // </autorizacion>
        
        return [
            'estado' => (string) $xml->estado,
            'numeroAutorizacion' => (string) $xml->numeroAutorizacion,
            'fechaAutorizacion' => (string) $xml->fechaAutorizacion,
            'ambiente' => (string) $xml->ambiente,
        ];
    }
    
    /**
     * Extrae los datos del comprobante (factura) del XML
     */
    private function extraerDatosComprobante(\SimpleXMLElement $xml): array {
        // Extraer el XML de la factura que viene dentro de <comprobante>
        $comprobante = $xml->comprobante->factura ?? $xml;
        
        if (!$comprobante) {
            throw new \Exception('No se encontró el comprobante en el XML');
        }
        
        $infoTributaria = $comprobante->infoTributaria;
        $infoFactura = $comprobante->infoFactura;
        $detalles = $comprobante->detalles->detalle;
        
        return [
            'infoTributaria' => [
                'ambiente' => (string) $infoTributaria->ambiente,
                'razonSocial' => (string) $infoTributaria->razonSocial,
                'nombreComercial' => (string) $infoTributaria->nombreComercial,
                'ruc' => (string) $infoTributaria->ruc,
                'claveAcceso' => (string) $infoTributaria->claveAcceso,
                'codDoc' => (string) $infoTributaria->codDoc,
                'estab' => (string) $infoTributaria->estab,
                'ptoEmi' => (string) $infoTributaria->ptoEmi,
                'secuencial' => (string) $infoTributaria->secuencial,
                'dirMatriz' => (string) $infoTributaria->dirMatriz,
            ],
            'infoFactura' => [
                'fechaEmision' => (string) $infoFactura->fechaEmision,
                'dirEstablecimiento' => (string) $infoFactura->dirEstablecimiento,
                'obligadoContabilidad' => (string) $infoFactura->obligadoContabilidad,
                'tipoIdentificacionComprador' => (string) $infoFactura->tipoIdentificacionComprador,
                'razonSocialComprador' => (string) $infoFactura->razonSocialComprador,
                'identificacionComprador' => (string) $infoFactura->identificacionComprador,
                'correoComprador' => (string) ($infoFactura->correoComprador ?? ''),
                'direccionComprador' => (string) ($infoFactura->direccionComprador ?? ''),
                'telefonoComprador' => (string) ($infoFactura->telefonoComprador ?? ''),
                'totalSinImpuestos' => (string) $infoFactura->totalSinImpuestos,
                'totalDescuento' => (string) $infoFactura->totalDescuento,
                'importeTotal' => (string) $infoFactura->importeTotal,
                'moneda' => (string) $infoFactura->moneda,
                'totalConImpuestos' => $this->extraerImpuestos($infoFactura->totalConImpuestos),
                'pagos' => $this->extraerPagos($infoFactura->pagos),
            ],
            'detalles' => $this->extraerDetalles($detalles),
            'infoAdicional' => $this->extraerInfoAdicional($comprobante->infoAdicional ?? null),
        ];
    }
    
    private function extraerImpuestos($totalConImpuestos): array {
        $impuestos = [];
        foreach ($totalConImpuestos->totalImpuesto as $impuesto) {
            $impuestos[] = [
                'codigo' => (string) $impuesto->codigo,
                'codigoPorcentaje' => (string) $impuesto->codigoPorcentaje,
                'baseImponible' => (string) $impuesto->baseImponible,
                'valor' => (string) $impuesto->valor,
            ];
        }
        return $impuestos;
    }
    
    private function extraerPagos($pagos): array {
        $formasPago = [];
        foreach ($pagos->pago as $pago) {
            $formasPago[] = [
                'formaPago' => (string) $pago->formaPago,
                'total' => (string) $pago->total,
            ];
        }
        return $formasPago;
    }
    
    private function extraerDetalles($detalles): array {
        $items = [];
        foreach ($detalles as $detalle) {
            $items[] = [
                'codigoPrincipal' => (string) $detalle->codigoPrincipal,
                'descripcion' => (string) $detalle->descripcion,
                'cantidad' => (string) $detalle->cantidad,
                'precioUnitario' => (string) $detalle->precioUnitario,
                'descuento' => (string) $detalle->descuento,
                'precioTotalSinImpuesto' => (string) $detalle->precioTotalSinImpuesto,
            ];
        }
        return $items;
    }
    
    private function extraerInfoAdicional($infoAdicional): array {
        if (!$infoAdicional) {
            return [];
        }
        
        $adicional = [];
        foreach ($infoAdicional->campoAdicional as $campo) {
            $nombre = (string) $campo['nombre'];
            $valor = (string) $campo;
            $adicional[$nombre] = $valor;
        }
        return $adicional;
    }
    
    /**
     * Genera el HTML del RIDE siguiendo el formato oficial del SRI
     */
    private function generarHtmlRide(array $autorizacion, array $comprobante, ?string $logoPath): string {
        $infoTrib = $comprobante['infoTributaria'];
        $infoFact = $comprobante['infoFactura'];
        $detalles = $comprobante['detalles'];
        $infoAdicional = $comprobante['infoAdicional'];
        
        // Formatear fecha de autorización
        $fechaAutorizacion = date('d/m/Y H:i:s', strtotime($autorizacion['fechaAutorizacion']));
        
        // Logo (base64 o ruta)
        $logoHtml = '';
        if ($logoPath && file_exists($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" style="max-width: 150px; max-height: 80px;">';
        }
        
        // Generar código de barras de la clave de acceso (simulado con texto por ahora)
        $claveAcceso = $infoTrib['claveAcceso'];
        
        // Mapeo de formas de pago
        $formasPagoMap = [
            '01' => 'SIN UTILIZACION DEL SISTEMA FINANCIERO',
            '15' => 'COMPENSACIÓN DE DEUDAS',
            '16' => 'TARJETA DE DÉBITO',
            '17' => 'DINERO ELECTRÓNICO',
            '18' => 'TARJETA PREPAGO',
            '19' => 'TARJETA DE CRÉDITO',
            '20' => 'OTROS CON UTILIZACIÓN DEL SISTEMA FINANCIERO',
            '21' => 'ENDOSO DE TÍTULOS',
        ];
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Factura <?= $infoTrib['estab'] . '-' . $infoTrib['ptoEmi'] . '-' . $infoTrib['secuencial'] ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: Arial, sans-serif;
                    font-size: 9pt;
                    line-height: 1.3;
                    color: #000;
                }
                
                .container {
                    width: 100%;
                    padding: 10px;
                }
                
                .header {
                    border: 1px solid #000;
                    margin-bottom: 5px;
                }
                
                .header-row {
                    display: table;
                    width: 100%;
                    border-collapse: collapse;
                }
                
                .header-left, .header-right {
                    display: table-cell;
                    vertical-align: top;
                    padding: 8px;
                }
                
                .header-left {
                    width: 50%;
                    border-right: 1px solid #000;
                }
                
                .header-right {
                    width: 50%;
                    text-align: center;
                }
                
                .logo {
                    margin-bottom: 10px;
                }
                
                .empresa-info {
                    font-size: 8pt;
                    line-height: 1.2;
                }
                
                .empresa-info strong {
                    font-size: 10pt;
                }
                
                .factura-info {
                    font-size: 8pt;
                }
                
                .factura-numero {
                    font-size: 11pt;
                    font-weight: bold;
                    margin: 5px 0;
                }
                
                .barcode {
                    margin: 10px 0;
                    padding: 5px;
                    border: 1px solid #ddd;
                    background: #fff;
                    font-family: 'Courier New', monospace;
                    font-size: 7pt;
                    word-break: break-all;
                    text-align: center;
                }
                
                .cliente-box {
                    border: 1px solid #000;
                    padding: 8px;
                    margin-bottom: 5px;
                }
                
                .cliente-row {
                    margin: 3px 0;
                    font-size: 8pt;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 5px 0;
                    font-size: 8pt;
                }
                
                table.detalles th {
                    background-color: #f0f0f0;
                    border: 1px solid #000;
                    padding: 5px;
                    text-align: center;
                    font-weight: bold;
                }
                
                table.detalles td {
                    border: 1px solid #000;
                    padding: 4px;
                }
                
                table.detalles td:nth-child(1) { width: 12%; text-align: center; }
                table.detalles td:nth-child(2) { width: 8%; text-align: center; }
                table.detalles td:nth-child(3) { width: 50%; }
                table.detalles td:nth-child(4) { width: 10%; text-align: right; }
                table.detalles td:nth-child(5) { width: 10%; text-align: right; }
                table.detalles td:nth-child(6) { width: 10%; text-align: right; }
                
                .footer-section {
                    display: table;
                    width: 100%;
                    margin-top: 10px;
                }
                
                .footer-left, .footer-right {
                    display: table-cell;
                    vertical-align: top;
                    width: 50%;
                    padding: 5px;
                }
                
                .info-adicional {
                    border: 1px solid #000;
                    padding: 8px;
                    margin-right: 5px;
                }
                
                .info-adicional-titulo {
                    font-weight: bold;
                    margin-bottom: 5px;
                    text-align: center;
                }
                
                .totales-table {
                    border: 1px solid #000;
                    width: 100%;
                }
                
                .totales-table td {
                    padding: 4px 8px;
                    border-bottom: 1px solid #ddd;
                }
                
                .totales-table td:first-child {
                    text-align: left;
                    font-weight: bold;
                }
                
                .totales-table td:last-child {
                    text-align: right;
                    width: 100px;
                }
                
                .total-final {
                    background-color: #f0f0f0;
                    font-weight: bold;
                    font-size: 10pt;
                }
                
                .descuento-highlight {
                    background-color: #ff0000;
                    color: #fff;
                    font-weight: bold;
                }
                
                .forma-pago-table {
                    border: 1px solid #000;
                    margin-top: 10px;
                }
                
                .forma-pago-table th,
                .forma-pago-table td {
                    border: 1px solid #000;
                    padding: 5px;
                    text-align: center;
                }
                
                .autorizacion-footer {
                    margin-top: 15px;
                    text-align: center;
                    font-size: 8pt;
                    border: 1px solid #000;
                    padding: 8px;
                    background-color: #f9f9f9;
                }
                
                .autorizacion-footer strong {
                    font-size: 10pt;
                    display: block;
                    margin-bottom: 5px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <!-- ENCABEZADO -->
                <div class="header">
                    <div class="header-row">
                        <div class="header-left">
                            <div class="logo"><?= $logoHtml ?></div>
                            <div class="empresa-info">
                                <strong><?= htmlspecialchars($infoTrib['razonSocial']) ?></strong><br>
                                <strong><?= htmlspecialchars($infoTrib['nombreComercial']) ?></strong><br>
                                <strong>DIR. MATRIZ:</strong> <?= htmlspecialchars($infoTrib['dirMatriz']) ?><br>
                                <strong>DIR. SUCURSAL:</strong> <?= htmlspecialchars($infoFact['dirEstablecimiento']) ?><br>
                                <?php if (isset($this->config['emisor']['contribuyente_especial']) && $this->config['emisor']['contribuyente_especial']): ?>
                                <strong>CONTRIBUYENTE ESPECIAL NRO:</strong> <?= $this->config['emisor']['contribuyente_especial'] ?><br>
                                <?php endif; ?>
                                <strong>OBLIGADO A LLEVAR CONTABILIDAD:</strong> <?= $infoFact['obligadoContabilidad'] ?>
                            </div>
                        </div>
                        <div class="header-right">
                            <div class="factura-info">
                                <strong>RUC:</strong> <?= $infoTrib['ruc'] ?><br>
                                <div class="factura-numero">FACTURA N°: <?= $infoTrib['estab'] . '-' . $infoTrib['ptoEmi'] . '-' . $infoTrib['secuencial'] ?></div>
                                <strong>NO. DE AUTORIZACIÓN:</strong><br>
                                <?= $autorizacion['numeroAutorizacion'] ?><br><br>
                                <strong>FECHA Y HORA DE AUTORIZACIÓN:</strong><br>
                                <?= $fechaAutorizacion ?><br>
                                <strong>AMBIENTE:</strong> <?= $autorizacion['ambiente'] === 'PRUEBAS' ? 'PRUEBAS' : 'PRODUCCIÓN' ?><br>
                                <strong>EMISIÓN:</strong> NORMAL<br>
                                <strong>CLAVE DE ACCESO:</strong>
                                <div class="barcode">|||||||||||||||||||||||||||||||||||||||||||||||<br><?= $claveAcceso ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- DATOS DEL CLIENTE -->
                <div class="cliente-box">
                    <div class="cliente-row">
                        <strong>RAZÓN SOCIAL / NOMBRES Y APELLIDOS:</strong> <?= htmlspecialchars($infoFact['razonSocialComprador']) ?>
                    </div>
                    <div class="cliente-row">
                        <strong>RUC/CI:</strong> <?= $infoFact['identificacionComprador'] ?>
                        &nbsp;&nbsp;&nbsp;
                        <strong>FECHA DE EMISIÓN:</strong> <?= $infoFact['fechaEmision'] ?>
                    </div>
                    <?php if (!empty($infoFact['direccionComprador'])): ?>
                    <div class="cliente-row">
                        <strong>DIRECCIÓN:</strong> <?= htmlspecialchars($infoFact['direccionComprador']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- DETALLE DE PRODUCTOS -->
                <table class="detalles">
                    <thead>
                        <tr>
                            <th>Cód. Principal</th>
                            <th>Cant.</th>
                            <th>Descripción</th>
                            <th>Precio unitario</th>
                            <th>Descuento</th>
                            <th>Precio total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalles as $detalle): ?>
                        <tr>
                            <td><?= htmlspecialchars($detalle['codigoPrincipal']) ?></td>
                            <td><?= number_format($detalle['cantidad'], 2) ?></td>
                            <td><?= htmlspecialchars($detalle['descripcion']) ?></td>
                            <td><?= number_format($detalle['precioUnitario'], 2) ?></td>
                            <td><?= number_format($detalle['descuento'], 2) ?></td>
                            <td><?= number_format($detalle['precioTotalSinImpuesto'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- FOOTER CON INFO ADICIONAL Y TOTALES -->
                <div class="footer-section">
                    <div class="footer-left">
                        <?php if (!empty($infoAdicional)): ?>
                        <div class="info-adicional">
                            <div class="info-adicional-titulo">INFORMACIÓN ADICIONAL</div>
                            <?php foreach ($infoAdicional as $nombre => $valor): ?>
                            <div><strong><?= htmlspecialchars($nombre) ?>:</strong> <?= htmlspecialchars($valor) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- FORMA DE PAGO -->
                        <table class="forma-pago-table">
                            <thead>
                                <tr>
                                    <th>Forma de Pago</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($infoFact['pagos'] as $pago): ?>
                                <tr>
                                    <td><?= $formasPagoMap[$pago['formaPago']] ?? $pago['formaPago'] ?></td>
                                    <td><?= number_format($pago['total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="footer-right">
                        <table class="totales-table">
                            <tr>
                                <td>SUBTOTAL SIN IMPUESTOS:</td>
                                <td><?= number_format($infoFact['totalSinImpuestos'], 2) ?></td>
                            </tr>
                            <?php foreach ($infoFact['totalConImpuestos'] as $impuesto): ?>
                                <?php if ($impuesto['codigo'] == '2'): // IVA ?>
                                    <?php 
                                    $porcentaje = $impuesto['codigoPorcentaje'] == '0' ? '0%' : 
                                                 ($impuesto['codigoPorcentaje'] == '2' ? '12%' : 
                                                 ($impuesto['codigoPorcentaje'] == '3' ? '14%' : 
                                                 ($impuesto['codigoPorcentaje'] == '4' ? '15%' : $impuesto['codigoPorcentaje'] . '%')));
                                    ?>
                                    <tr>
                                        <td>SUBTOTAL <?= $porcentaje ?>:</td>
                                        <td><?= number_format($impuesto['baseImponible'], 2) ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (floatval($infoFact['totalDescuento']) > 0): ?>
                            <tr class="descuento-highlight">
                                <td>DESCUENTO:</td>
                                <td><?= number_format($infoFact['totalDescuento'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($infoFact['totalConImpuestos'] as $impuesto): ?>
                                <?php if ($impuesto['codigo'] == '2' && floatval($impuesto['valor']) > 0): ?>
                                    <?php 
                                    $porcentaje = $impuesto['codigoPorcentaje'] == '2' ? '12%' : 
                                                 ($impuesto['codigoPorcentaje'] == '3' ? '14%' : 
                                                 ($impuesto['codigoPorcentaje'] == '4' ? '15%' : $impuesto['codigoPorcentaje'] . '%'));
                                    ?>
                                    <tr>
                                        <td>IVA <?= $porcentaje ?>:</td>
                                        <td><?= number_format($impuesto['valor'], 2) ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <tr class="total-final">
                                <td>VALOR TOTAL:</td>
                                <td><?= number_format($infoFact['importeTotal'], 2) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- PIE DE PÁGINA CON AUTORIZACIÓN -->
                <div class="autorizacion-footer">
                    <strong>DOCUMENTO AUTORIZADO SRI</strong>
                    Factura autorizada el <?= $fechaAutorizacion ?><br>
                    Número de autorización: <?= $autorizacion['numeroAutorizacion'] ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Guarda el PDF en el disco
     */
    public function guardarRide(string $pdfContent, string $outputPath): bool {
        return file_put_contents($outputPath, $pdfContent) !== false;
    }
}
