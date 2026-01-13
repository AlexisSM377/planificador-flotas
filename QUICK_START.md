# 🚀 Guía Rápida - De Aquí a Hostinger en 10 Minutos

## Paso 1: Prepara tu Repositorio (LOCAL)

```bash
# Verifica que .env existe y tiene tus valores
# NO debe estar en git (verifica que .gitignore tiene /\.env/)
git status
# Confirma que .env NO aparece en untracked files
```

## Paso 2: Genera API Key Segura

Elige UNA de estas opciones según tu SO:

**Windows (PowerShell como admin):**
```powershell
$bytes = 1..32 | ForEach-Object { [byte](Get-Random -Maximum 256) }
[Convert]::ToBase64String($bytes) -replace '=', ''
```
Copia el resultado (32+ caracteres)

**Linux/Mac:**
```bash
openssl rand -hex 32
```

## Paso 3: Actualiza tu `.env` Local

Edita `C:\xampp\htdocs\bitacora_tracker\.env`:
```
SPREADSHEET_ID=1XwjnIxq98oStetgaD5XDWpfgUhMCR1dgCzY8eVa3tiE
GOOGLE_CREDENTIALS_PATH=./credentials/google.json
API_KEY=PEGAPAQUI_TU_CLAVE_NUEVA
ENVIRONMENT=development
ALLOWED_ORIGINS=http://localhost,http://localhost:3000,http://127.0.0.1
LOG_LEVEL=debug
```

## Paso 4: Haz Commit de los Cambios de Seguridad

```bash
cd C:\xampp\htdocs\bitacora_tracker

# Agrega todos los archivos de seguridad (EXCEPTO .env)
git add .gitignore .htaccess .env.example SECURITY.md DEPLOYMENT_HOSTINGER.md RESUMEN_SEGURIDAD.md
git add src/config.php src/RequestValidator.php src/api/sheets.php

# Commitea
git commit -m "Security: Add authentication, validation, and environment configuration

- Implement X-API-Key authentication for API endpoints
- Add input validation and sanitization (XSS/CSRF protection)
- Create environment configuration system (.env)
- Add security headers (HSTS, CSP, X-Frame-Options)
- Protect sensitive files via .htaccess
- Add comprehensive security and deployment documentation
- Separate credentials from source code"

# Sube a GitHub
git push origin main
```

## Paso 5: En Hostinger Panel

1. **Ve a File Manager**
2. **Sube tu proyecto:** Git > Clonar Repositorio
   - URL: `https://github.com/tu-usuario/bitacora_tracker.git`
   - Branch: `main`

O usa FTP si prefieres

## Paso 6: Conectarse vía SSH en Hostinger

Hostinger te da las credenciales SSH en el panel:

```bash
ssh usuario@tu-servidor.com
cd public_html/bitacora_tracker
```

## Paso 7: Crear `.env` en Servidor

En SSH, ejecuta:
```bash
nano .env
```

Pega esto (REEMPLAZA tu_api_key_aqui):
```
SPREADSHEET_ID=1XwjnIxq98oStetgaD5XDWpfgUhMCR1dgCzY8eVa3tiE
GOOGLE_CREDENTIALS_PATH=./credentials/google.json
API_KEY=tu_api_key_aqui
ENVIRONMENT=production
ALLOWED_ORIGINS=https://tu-dominio.com
LOG_LEVEL=error
```

Presiona: `Ctrl+X` → `Y` → `Enter`

## Paso 8: Crear Carpetas Necesarias

```bash
mkdir -p logs credentials
chmod 755 logs credentials
```

## Paso 9: Subir Credenciales de Google

**VÍA FTP (FileZilla):**
1. Abre FileZilla
2. Conecta a tu servidor Hostinger
3. Navega a: `public_html/bitacora_tracker/credentials/`
4. Sube: `google.json` desde tu PC
5. Haz clic derecho → Cambiar permisos → `600`

**VÍA SSH:**
```bash
# En tu PC local, copia el contenido:
cat credentials/google.json

# En el servidor SSH, crea el archivo:
nano credentials/google.json

# Pega el contenido completo de google.json
# Ctrl+X → Y → Enter

# Protege:
chmod 600 credentials/google.json
```

## Paso 10: Instalar Dependencias

En SSH:
```bash
cd /home/tu-usuario/public_html/bitacora_tracker
composer install --no-dev --optimize-autoloader
```

## Paso 11: Configurar Permisos

En SSH:
```bash
# Permisos correctos para directorios
find . -type d -exec chmod 755 {} \;

# Permisos correctos para archivos
find . -type f -name "*.php" -exec chmod 644 {} \;

# Archivos especiales
chmod 644 .htaccess
chmod 600 .env
chmod 600 credentials/google.json
```

## Paso 12: Activar HTTPS

En panel Hostinger:
1. Ve a **SSL/TLS**
2. Activa **Auto SSL** (incluido con Hostinger)
3. Espera 5 minutos
4. Ve a **Redirecciones**
5. Crea: `http://tu-dominio.com` → `https://tu-dominio.com`

## Paso 13: Prueba tu API

### Test 1: Verifica que .env está protegido
```bash
curl https://tu-dominio.com/.env
# Debe mostrar: 403 Forbidden
```

### Test 2: Verifica que API requiere Key
```bash
curl https://tu-dominio.com/src/api/sheets.php?action=read&tipo=logistica
# Debe mostrar error 401 o mensaje de error
```

### Test 3: Verifica que API funciona CON Key
```bash
curl -H "X-API-Key: tu_api_key_aqui" \
     https://tu-dominio.com/src/api/sheets.php?action=read&tipo=logistica

# Debe retornar JSON con tus datos de Google Sheets
```

## Listo! 🎉

Tu aplicación está segura en producción.

### Qué Significa Cada Layer de Seguridad

| Layer | Qué Hace |
|-------|----------|
| **HTTPS/SSL** | Encripta comunicación (candado 🔒) |
| **.htaccess** | Bloquea acceso a `.env` y `credentials/` |
| **Permisos 600** | Solo tú puedes leer `.env` |
| **X-API-Key** | Valida que cliente es autorizado |
| **Sanitización** | Previene XSS/SQL Injection |
| **Headers** | Previene clickjacking, MIME sniffing, etc |
| **ENVIRONMENT=production** | Oculta errores detallados |

---

**¿Preguntas?** Lee `DEPLOYMENT_HOSTINGER.md` para más detalles.
