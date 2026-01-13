# Guía de Seguridad para Bitacora Tracker

## Configuración Previa al Despliegue

### 1. Crear archivo `.env`

Copia `.env.example` a `.env` y rellena los valores:

```bash
cp .env.example .env
```

Luego edita `.env` con tus valores reales:
- `SPREADSHEET_ID`: Tu ID de Google Sheets
- `API_KEY`: Una clave secreta segura (usa un generador)
- `ALLOWED_ORIGINS`: Los dominios permitidos (separados por comas)
- `ENVIRONMENT`: `production` en Hostinger, `development` local

### 2. Google Credentials

**NO SUBAS `credentials/google.json` al repositorio.**

En tu servidor Hostinger:
1. Crea la carpeta `credentials/` en la raíz del proyecto
2. Sube manualmente el archivo `google.json` vía FTP
3. Asegúrate de que solo PHP pueda leerlo:
   ```bash
   chmod 600 credentials/google.json
   ```

### 3. Carpeta de Logs

Crea una carpeta de logs fuera del acceso público:

```bash
mkdir logs
chmod 755 logs
```

### 4. Seguridad en Hostinger

#### Checklist de configuración:

- [ ] **SSL/HTTPS**: Activa SSL en Hostinger (incluido en plan)
- [ ] **PHP Version**: Usa PHP 8.0+
- [ ] **Composer**: Ejecuta `composer install` en el servidor
- [ ] **Permisos de archivos**:
  - `chmod 755` para carpetas
  - `chmod 644` para archivos PHP
  - `chmod 600` para `credentials/google.json` y `.env`

#### Archivos a proteger:

Crea o edita `.htaccess` en la raíz para bloquear acceso directo:

```apache
# Deny access to sensitive files
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

<Files "credentials">
    Order allow,deny
    Deny from all
</Files>

<Files "composer.json">
    Order allow,deny
    Deny from all
</Files>

<Files "composer.lock">
    Order allow,deny
    Deny from all
</Files>
```

### 5. Headers de Seguridad

Los headers se establecen automáticamente en `src/config.php`:

- **X-Frame-Options**: Previene clickjacking
- **X-Content-Type-Options**: Previene MIME sniffing
- **X-XSS-Protection**: Protección contra XSS
- **Content-Security-Policy**: Política restrictiva
- **HSTS**: Fuerza HTTPS (solo en producción)

### 6. Autenticación de API

Todos los requests deben incluir el header:
```
X-API-Key: tu_clave_secreta
```

Ejemplo con curl:
```bash
curl -H "X-API-Key: tu_clave_secreta" \
     "https://tudominio.com/src/api/sheets.php?action=read&tipo=logistica"
```

### 7. Validación de Entrada

- Se valida whitelist de `tipo` (solo 'logistica' y 'contactos')
- Se limita a máximo 1000 filas por request
- Se sanitizan todas las entradas
- Se valida origen CORS

## Mejores Prácticas

### Generador de API Key

Usa una clave fuerte (32+ caracteres alfanuméricos):

```bash
# Linux/Mac
openssl rand -hex 32

# Windows PowerShell
[Convert]::ToBase64String((1..32 | ForEach-Object {Get-Random -Maximum 256})) | cut -c1-32
```

### Monitoreo

Revisa regularmente `logs/error.log` para detectar intentos de ataque.

### Actualización de Dependencias

```bash
composer update
```

### Cambios de Configuración

Después de cambiar `.env`, reinicia PHP:
- En Hostinger, puede que necesites reiniciar mediante el panel de control
- O espera a que se recargue automáticamente

## Eliminación de Archivos del Repositorio

Si ya subiste `credentials/google.json` accidentalmente:

```bash
# Eliminar del repositorio pero no del disco local
git rm --cached credentials/google.json

# Hacer commit
git commit -m "Remove credentials from version control"

# Subir cambios
git push origin main
```

**IMPORTANTE**: Considera esa clave de Google como comprometida. Crea una nueva en Google Cloud Console.

## Testeo de Seguridad

Antes de ir a producción:

1. ✅ Verifica que `.env` no está en control de versión
2. ✅ Verifica que `credentials/google.json` no está en control de versión
3. ✅ Prueba que sin `X-API-Key` rechazo
4. ✅ Verifica que errores detallados no se muestran en producción
5. ✅ Prueba HTTPS funciona
6. ✅ Verifica que logs se crean correctamente

## Soporte

Para reportar vulnerabilidades de seguridad, NO abras issues públicas.
Contacta al administrador del proyecto directamente.
