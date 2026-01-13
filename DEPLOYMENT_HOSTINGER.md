# Instrucciones de Despliegue en Hostinger

## Paso 1: Preparar el Repositorio

Antes de subir a Hostinger, asegúrate de que los archivos sensibles NO estén en git:

```bash
# Verifica que .env no está incluido
git status

# Si fue subido accidentalmente, elimínalo del repositorio
git rm --cached .env
git rm --cached credentials/google.json
git commit -m "Remove sensitive files from version control"
git push
```

## Paso 2: Cargar Proyecto a Hostinger

### Opción A: Usando Git (Recomendado)

1. En el panel de Hostinger:
   - Ve a **Manage → Git**
   - Clona tu repositorio en el directorio público

```bash
git clone https://github.com/tu-usuario/bitacora_tracker.git
```

### Opción B: Usando FTP

1. Descarga FileZilla o similar
2. Sube todos los archivos EXCEPTO:
   - `.env` (la crearás en el servidor)
   - `credentials/google.json` (la subirás después)

## Paso 3: Configurar Variables de Entorno

1. **Conéctate vía SSH** (Hostinger lo proporciona):
   ```bash
   ssh user@tu-servidor.com
   cd public_html/bitacora_tracker
   ```

2. **Crea el archivo `.env`**:
   ```bash
   nano .env
   ```

3. **Pega tu configuración**:
   ```
   SPREADSHEET_ID=tu_id_aqui
   GOOGLE_CREDENTIALS_PATH=./credentials/google.json
   API_KEY=tu_api_key_super_segura_aqui
   ENVIRONMENT=production
   ALLOWED_ORIGINS=https://tu-dominio.com
   LOG_LEVEL=error
   ```

4. **Guarda**: Presiona `Ctrl+X`, luego `Y`, luego `Enter`

5. **Protege el archivo**:
   ```bash
   chmod 600 .env
   ```

## Paso 4: Crear Carpeta de Credenciales

```bash
mkdir -p credentials
chmod 755 credentials
```

## Paso 5: Subir Google Credentials

### Vía FTP:
1. Abre FileZilla
2. Navega a `public_html/bitacora_tracker/credentials/`
3. Sube tu `google.json`
4. Haz clic derecho → Cambiar permisos de archivo → `600`

### Vía SSH:
1. Copia el contenido de tu `google.json`
2. En el servidor:
   ```bash
   cat > credentials/google.json << 'EOF'
   {pega el contenido aqui}
   EOF
   ```
3. Protege:
   ```bash
   chmod 600 credentials/google.json
   ```

## Paso 6: Instalar Dependencias con Composer

```bash
# En SSH, en el directorio del proyecto
composer install --no-dev --optimize-autoloader
```

## Paso 7: Crear Carpeta de Logs

```bash
mkdir logs
chmod 755 logs
```

## Paso 8: Configurar Permisos de Archivos

```bash
# Desde SSH en el directorio del proyecto:

# Dar permisos correctos a directorios
find . -type d -exec chmod 755 {} \;

# Dar permisos correctos a archivos PHP
find . -type f -name "*.php" -exec chmod 644 {} \;

# Archivos especiales
chmod 644 .htaccess
chmod 600 .env
chmod 600 credentials/google.json
```

## Paso 9: Verificar HTTPS

1. Ve al panel de Hostinger
2. Busca **SSL/TLS**
3. Asegúrate de que está habilitado (gratuito con Hostinger)
4. Configura redirección automática de HTTP a HTTPS

## Paso 10: Prueba

### Test 1: Verificar que archivo .env está protegido
```bash
curl https://tu-dominio.com/.env
# Debe mostrar: 403 Forbidden
```

### Test 2: Verificar que credentials/ está protegido
```bash
curl https://tu-dominio.com/credentials/
# Debe mostrar: 403 Forbidden
```

### Test 3: Probar API sin API Key
```bash
curl https://tu-dominio.com/src/api/sheets.php?action=read&tipo=logistica
# Debe mostrar error 401
```

### Test 4: Probar API con API Key
```bash
curl -H "X-API-Key: tu_api_key" \
     https://tu-dominio.com/src/api/sheets.php?action=read&tipo=logistica
# Debe retornar JSON con datos
```

## Troubleshooting

### Error: "CORS policy violation"
- Verifica que `ALLOWED_ORIGINS` en `.env` incluye tu dominio
- Ej: `ALLOWED_ORIGINS=https://tu-dominio.com`

### Error: "Credentials file not found"
- Verifica que `credentials/google.json` existe
- En SSH: `ls -la credentials/google.json`

### Error: "Invalid or missing API key"
- Verifica que estás enviando el header `X-API-Key` correcto
- Verifica el valor en `.env`

### Los logs no se crean
- Verifica que la carpeta `logs/` existe y es escribible
- En SSH: `ls -ld logs` debe mostrar `drwxr-xr-x`

### Error 500 genérico
- Revisa `logs/error.log` en SSH:
  ```bash
  tail -f logs/error.log
  ```

## Mantenimiento

### Revisar logs regularmente
```bash
ssh user@tu-servidor.com
tail -100 /home/user/public_html/bitacora_tracker/logs/error.log
```

### Actualizar dependencias
```bash
composer update
```

### Cambiar API Key (recomendado cada 3-6 meses)
1. Genera una nueva clave
2. Actualiza `.env` en el servidor
3. Reinicia PHP (puede tomar unos minutos)

## Seguridad: Checklist Final

- [ ] ✅ `.env` NO está en git (verificar con `git log`)
- [ ] ✅ `credentials/google.json` NO está en git
- [ ] ✅ HTTPS está activado y redirige HTTP
- [ ] ✅ Archivo `.htaccess` protege archivos sensibles
- [ ] ✅ Carpeta `logs/` es escribible
- [ ] ✅ Pruebas de seguridad pasadas (ver Paso 10)
- [ ] ✅ API Key es segura (32+ caracteres aleatorios)
- [ ] ✅ `ALLOWED_ORIGINS` tiene solo tus dominios
- [ ] ✅ Permisos: 600 para `.env` y credenciales, 755 para directorios

¡Listo! Tu aplicación está segura en Hostinger.
