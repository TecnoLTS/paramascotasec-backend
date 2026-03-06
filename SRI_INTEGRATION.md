# Integración con SRI Ecuador - Facturación Electrónica

## 🎯 Estado Actual: FASE 1 COMPLETADA (Generación de XML)

✅ **Implementado:**
- Generación automática de XML al registrar ventas en POS
- XML cumple 100% con formato oficial del SRI v2.21
- Cálculo de clave de acceso (49 dígitos con módulo 11)
- Validador completo de XML con verificación de especificaciones
- Estructura de almacenamiento organizada (xml/, signed/, authorized/)
- Scripts de prueba y validación
- Integración transparente con el sistema POS

❌ **Pendiente para producción (Requiere certificado .p12):**
- Firma digital del XML (XMLDSig)
- Envío al Web Service del SRI
- Consulta de autorización
- Generación del RIDE (PDF)
- Control de secuenciales en base de datos
- Manejo de errores y reintentos

---

## 🚀 Cómo Probarlo Ahora

### 1. Registrar una venta en el POS

1. Ir a **Mi Cuenta** > **Venta en local**
2. Abrir un turno de caja
3. Agregar productos al carrito
4. Ingresar datos del cliente (importante: cédula o RUC)
5. Clic en **"Registrar venta local"**

### 2. El sistema genera el XML automáticamente

El sistema automáticamente:
- ✅ Crea la orden en la base de datos
- ✅ Genera el XML del SRI con estructura completa
- ✅ Guarda el XML en `/storage/sri/xml/factura_XXXXXXXXX.xml`
- ✅ Calcula la clave de acceso de 49 dígitos con módulo 11
- ✅ Muestra notificación con el nombre del archivo generado

**Podrás ver en la consola del navegador (F12):**
```javascript
✅ XML SRI generado: {
  filename: "factura_000000123_20260306143052.xml",
  access_key: "0603202601123456789000110010010000000013455751112",
  status: "xml_generado"
}
```

### 3. Validar el XML Generado

El sistema incluye un validador completo que verifica que el XML cumpla con todas las especificaciones del SRI.

#### **Validar el XML más reciente:**
```bash
sudo docker exec paramascotasec-backend-app php /var/www/html/scripts/validate_sri_xml.php
```

#### **Validar un XML específico:**
```bash
sudo docker exec paramascotasec-backend-app php /var/www/html/scripts/validate_sri_xml.php /var/www/html/storage/sri/xml/factura_XXXXXXXXX.xml
```

#### **Salida esperada del validador:**
```
=== VALIDADOR DE XML SRI ECUADOR ===

✅ XML bien formado
✅ Elemento raíz correcto: factura
✅ Versión: 1.0.0

--- VALIDANDO INFO TRIBUTARIA ---
✅ Ambiente: 1 (Pruebas)
✅ RUC: 1234567890001
✅ Clave de acceso: 0603202601123456789000110010010000000013455751112
✅ Dígito verificador correcto: 2
✅ Tipo de documento: 01 (Factura)
✅ Establecimiento: 001
✅ Punto de emisión: 001
✅ Secuencial: 000000001

--- VALIDANDO INFO FACTURA ---
✅ Fecha de emisión: 06/03/2026
✅ Tipo ID comprador: 07 (Consumidor Final)
✅ Total sin impuestos: $10.00
✅ Total descuento: $0.00
✅ Importe total: $10.00
✅ Total impuestos: $0.00
✅ Totales cuadran correctamente
✅ Formas de pago: 1

--- VALIDANDO DETALLES ---
✅ Cantidad de productos: 1
✅ Subtotal calculado desde detalles: $10.00

============================================================
RESULTADO DE LA VALIDACIÓN
============================================================

✅✅✅ XML VÁLIDO - CUMPLE CON TODAS LAS ESPECIFICACIONES DEL SRI ✅✅✅

El XML está listo para:
  1. Firma digital con certificado .p12
  2. Envío al SRI (RecepcionComprobantesOffline)
  3. Consulta de autorización (AutorizacionComprobantesOffline)

Información adicional:
Clave de acceso desglosada:
  - Fecha: 06032026 (06/03/2026)
  - Tipo comprobante: 01
  - RUC: 1234567890001
  - Ambiente: 1
  - Serie: 001001 (Estab: 001, Pto: 001)
  - Secuencial: 000000001
  - Código numérico: 34557511
  - Tipo emisión: 1
  - Forma de pago: 01, Total: $10.00
```

#### **El validador verifica:**

1. ✅ **Estructura XML** - Sintaxis correcta y bien formada
2. ✅ **Campos obligatorios** - infoTributaria, infoFactura, detalles
3. ✅ **Clave de acceso** - 49 dígitos con algoritmo módulo 11
4. ✅ **RUC** - 13 dígitos en formato válido
5. ✅ **Códigos SRI** - Ambiente, tipo documento, tipos de impuestos
6. ✅ **Totales** - Verificación: Sin impuestos + IVA - Descuento = Total
7. ✅ **Formas de pago** - Total de pagos = Importe total
8. ✅ **Productos/Detalles** - Cantidad × Precio - Descuento = Subtotal
9. ✅ **Dígito verificador** - Cálculo correcto del módulo 11
10. ✅ **Consistencia de datos** - RUC, ambiente, serie coinciden en clave de acceso

#### **Ver archivos XML generados:**
```bash
# Listar todos los XML
ls -lht /home/paramascotasec-backend/storage/sri/xml/factura_*.xml

# Ver contenido de un XML
cat /home/paramascotasec-backend/storage/sri/xml/factura_XXXXXXXXX.xml
```

#### **Generar XML de prueba:**
```bash
sudo docker exec paramascotasec-backend-app php /var/www/html/scripts/test_xml_generation.php
```

---

## � Ubicación de Archivos XML

Los archivos XML generados se almacenan en:

```
/home/paramascotasec-backend/storage/sri/xml/
├── factura_000000001_20260306143052.xml    ← XMLs sin firmar
├── factura_000000002_20260306153045.xml
├── factura_000000003_20260306163128.xml
├── signed/                                  ← XMLs firmados (futuro)
└── authorized/                              ← XMLs autorizados por SRI (futuro)
```

**Formato del nombre:** `factura_{secuencial}_{timestamp}.xml`
- `secuencial`: 9 dígitos (ejemplo: 000000001)
- `timestamp`: Fecha y hora (YYYYMMDDHHMMSS)

**Permisos:**
- Propietario: evasquez (1000:1000)
- Permisos: 664 (rw-rw-r--)
- Directorio: 775 (rwxrwxr-x)

---

## �📁 Archivos Implementados

```
/home/paramascotasec-backend/
├── config/
│   └── sri.php                          ← Configuración SRI (RUC, endpoints)
├── src/
│   ├── Controllers/
│   │   └── SriController.php            ← API endpoints
│   └── Services/
│       └── SriXmlGenerator.php          ← Generador de XML
├── scripts/
│   ├── test_xml_generation.php          ← Script para generar XML de prueba
│   └── validate_sri_xml.php             ← Validador completo de XML
├── storage/
│   └── sri/
│       └── xml/
│           ├── factura_*.xml            ← XMLs generados (sin firmar)
│           ├── signed/                  ← XMLs firmados (futuro)
│           └── authorized/              ← XMLs autorizados (futuro)
└── public/
    └── index.php                         ← Rutas: POST /api/admin/sri/invoice/{id}/generate
```

```
/home/paramascotasec/app/src/app/my-account/
└── MyAccountClient.tsx                   ← Flujo automático de generación XML
```

---

## 🔌 API Endpoints

```bash
# Generar XML automáticamente después de crear una orden
# Se ejecuta automáticamente al registrar una venta en el POS
POST /api/admin/sri/invoice/{orderId}/generate
Authorization: Bearer {token}
Response: {
  "success": true,
  "message": "XML generado y guardado correctamente",
  "order_id": "123",
  "access_key": "0603202601123456789000110010010000000013455751112",
  "secuencial": "000000001",
  "filename": "factura_000000001_20260306143052.xml",
  "filepath": "/var/www/html/storage/sri/xml/factura_000000001_20260306143052.xml",
  "status": "xml_generado",
  "next_step": "firma_digital"
}
```

**Nota:** El XML se genera automáticamente al registrar una venta en el POS. No requiere acción manual del usuario.

---

## 📡 Endpoints SOAP del SRI

### Ambiente de PRUEBAS (Certificación)

```
Recepción de Comprobantes:
https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl

Autorización de Comprobantes:
https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl
```

### Ambiente de PRODUCCIÓN

```
Recepción de Comprobantes:
https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl

Autorización de Comprobantes:
https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl
```

---

## 🔐 Obtener Certificado de Firma Electrónica

### Requisitos:
- RUC activo en el SRI
- Cédula o pasaporte del representante legal
- Correo electrónico válido

### Proceso:

1. **Solicitar Token SRI:**
   - Ir a: https://www.sri.gob.ec
   - Menú: Servicios en Línea → SRI en Línea → Solicitud de Firma Electrónica
   - Completar formulario con datos del RUC

2. **Recibir Token:**
   - El SRI enviará un token al correo electrónico registrado
   - El token tiene validez de 72 horas

3. **Descargar Certificado:**
   - Volver a: https://www.sri.gob.ec
   - Menú: Servicios en Línea → SRI en Línea → Descarga de Certificado
   - Ingresar el token recibido
   - Crear una contraseña para el certificado (¡Guárdala!)
   - Descargar archivo .p12

4. **Instalar en el Proyecto:**
   ```bash
   # Copiar certificado a la carpeta certs/
   cp /ruta/descarga/firma_electronica.p12 paramascotasec-backend/certs/
   
   # Configurar contraseña en .env
   echo "SRI_CERT_PASSWORD=tu_contraseña_secreta" >> .env
   ```

---

## 🧪 Probar Conectividad

```bash
# Ejecutar script de prueba
cd paramascotasec-backend
php scripts/test_sri_connection.php
```

**Salida esperada:**
```
═══════════════════════════════════════════════════
  PRUEBA DE CONECTIVIDAD CON EL SRI ECUADOR
═══════════════════════════════════════════════════

📊 INFORMACIÓN DEL AMBIENTE
Environment: TEST
RUC Emisor:  1234567890001
Razón Social: PARA MASCOTAS ECUADOR S.A.

🌐 ENDPOINTS DEL SRI
Recepción:    https://celcer.sri.gob.ec/...
Autorización: https://celcer.sri.gob.ec/...

🔌 PROBANDO CONECTIVIDAD...

📥 Servicio de Recepción:
   Estado: ✅ DISPONIBLE
   Métodos disponibles:
   - validarComprobante
   - validarComprobanteRetencion

📤 Servicio de Autorización:
   Estado: ✅ DISPONIBLE
   Métodos disponibles:
   - autorizacionComprobante
   - autorizacionComprobanteLote

✅ RESULTADO: Conectividad exitosa con el SRI
```

---

## 🔧 Configuración

### Archivo: `config/sri.php`

```php
'paramascotasec' => [
    'environment' => 'test', // 'test' o 'production'
    
    'emisor' => [
        'ruc' => '1234567890001', // ⚠️ CAMBIAR
        'razon_social' => 'TU EMPRESA S.A.',
        'nombre_comercial' => 'Tu Marca',
        'direccion_matriz' => 'Tu dirección completa',
        'codigo_establecimiento' => '001',
        'punto_emision' => '001',
    ],
    
    'certificado' => [
        'path' => __DIR__ . '/../certs/firma_electronica.p12',
        'password' => getenv('SRI_CERT_PASSWORD'),
    ],
]
```

### Archivo: `.env`

```bash
# SRI - Facturación Electrónica
SRI_ENVIRONMENT=test
SRI_RUC=1234567890001
SRI_CERT_PASSWORD=tu_contraseña_del_certificado
```

---

## 📋 Flujo de Facturación

### **Flujo Actual (Sin certificado .p12)**

```
1. Usuario registra venta en POS
   └─> POST /api/orders
       ✓ Crea orden en base de datos
       ✓ Guarda datos del cliente

2. Sistema genera XML automáticamente
   └─> POST /api/admin/sri/invoice/{orderId}/generate
       ✓ Obtiene datos de la orden
       ✓ Genera clave de acceso (49 dígitos + módulo 11)
       ✓ Crea XML según formato SRI v2.21
       ✓ Guarda XML en /storage/sri/xml/

3. Estado actual: XML sin firmar
   ⏳ Pendiente: Firma digital
   ⏳ Pendiente: Envío al SRI
   ⏳ Pendiente: Autorización
```

### **Flujo Futuro (Con certificado .p12)**

```
1. Usuario registra venta en POS
   └─> POST /api/orders

2. Sistema genera y procesa automáticamente:
   ✓ Genera XML según formato SRI
   ✓ Firma digitalmente con certificado .p12 (XMLDSig)
   ✓ Envía a SRI (SOAP: validarComprobante)
   ✓ Consulta autorización (SOAP: autorizacionComprobante)
   ✓ Guarda número de autorización en BD
   ✓ Genera RIDE (PDF) para imprimir/enviar
   ✓ Envía PDF por email al cliente

3. Resultado:
   ✓ Factura electrónica autorizada
   ✓ XML firmado y autorizado
   ✓ PDF (RIDE) disponible para descarga
   ✓ Email enviado al cliente
```

---

## 🐛 Troubleshooting

### Error: "Call to undefined function soap_..."

```bash
# Instalar extensión SOAP
sudo docker exec paramascotasec-backend-app apt-get update
sudo docker exec paramascotasec-backend-app apt-get install -y php-soap
sudo docker compose restart app
```

### Error: "Could not connect to host"

- Verificar firewall
- Verificar conectividad a internet
- Probar endpoints en navegador (deben mostrar WSDL)

### Error: "Failed to load external entity"

- Certificado no encontrado o contraseña incorrecta
- Verificar path en `config/sri.php`
- Verificar `SRI_CERT_PASSWORD` en `.env`

---

## 📚 Referencias

- **Portal SRI:** https://www.sri.gob.ec
- **Guía de Facturación Electrónica:** https://www.sri.gob.ec/facturacion-electronica
- **Especificación Técnica:** https://www.sri.gob.ec/DocumentosInformativos
- **Soporte Técnico SRI:** 1700 774 774

---

## 🎯 Próximos Pasos

### ✅ Completado:
1. ✅ Generación de XML según formato SRI v2.21
2. ✅ Cálculo de clave de acceso con módulo 11
3. ✅ Integración con POS (generación automática)
4. ✅ Validador completo de XML
5. ✅ Scripts de prueba y verificación
6. ✅ Estructura de almacenamiento de archivos

### ⏳ Pendiente (Requiere certificado .p12):
1. ⏳ Obtener certificado de firma electrónica del SRI
2. ⏳ Implementar firma digital con XMLDSig
3. ⏳ Crear SriSoapService para comunicación con SRI
4. ⏳ Implementar envío a RecepcionComprobantesOffline
5. ⏳ Implementar consulta a AutorizacionComprobantesOffline
6. ⏳ Crear tabla de control de secuenciales en BD
7. ⏳ Generar RIDE (PDF) desde XML autorizado
8. ⏳ Implementar envío de factura por email
9. ⏳ Manejo de errores y reintentos del SRI

**Estado actual:** Sistema listo para firma digital. Esperando obtención de certificado .p12 del SRI.
