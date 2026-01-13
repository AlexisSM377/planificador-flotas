# 🔍 Solución: Error 404 en Hostinger

## ¿Qué significa 404?
El servidor no encuentra el archivo/ruta que solicitaste.

---

## 🎯 Pasos de Diagnóstico

### Paso 1: Verifica la Estructura de Carpetas en Hostinger

Conéctate vía SSH a Hostinger:

```bash
ssh tu_usuario@tu-servidor.com
cd public_html
ls -la
```

Deberías ver:
```
bitacora_tracker/  (si clonaste como carpeta)
O
```

### Paso 2: Verifica que los Archivos Están

```bash
# Entrar a la carpeta del proyecto
cd bitacora_tracker

# Listar contenido
ls -la

# Deberías ver:
# .env
# .env.example
# .gitignore
# .htaccess
# index.html
# src/
# credentials/
# vendor/
# logs/
```

### Paso 3: Verifica que src/index.html Existe

```bash
# Verificar que existe
ls -la src/index.html

# Deberías ver algo como:
# -rw-r--r-- 1 user group 59948 Jan 13 17:38 src/index.html
```

### Paso 4: Ejecuta el Script de Diagnóstico

```bash
# Descarga el script (si no lo tienes)
# Colócalo en la raíz de bitacora_tracker

# Hazlo ejecutable
chmod +x diagnostic.sh

# Ejecútalo
./diagnostic.sh
```

---

## 🚨 Posibles Causas del 404

### Causa 1: Clonaste en la Carpeta Equivocada

**Problema:**
```
public_html/
├── bitacora_tracker/
│   └── bitacora_tracker/  ← ¡CARPETA DUPLICADA!
│       └── src/
```

**Solución:**
```bash
# Elimina la carpeta duplicada
rm -rf public_html/bitacora_tracker/bitacora_tracker

# O mueve los archivos correctamente
cd public_html/bitacora_tracker/bitacora_tracker
mv * ../
cd ..
rm -rf bitacora_tracker
```

### Causa 2: URL Incorrecta

**Problema:** Estás usando una URL que no existe

**Incorrecto:**
```
https://tu-dominio.com/bitacora_tracker/index.html
(busca index.html en raíz, no existe)
```

**Correcto:**
```
https://tu-dominio.com/bitacora_tracker/src/index.html
(busca index.html en src/, EXISTE)
```

O usa la raíz que redirige:
```
https://tu-dominio.com/bitacora_tracker/
(redirige a src/index.html)
```

### Causa 3: Repo no Clonado Correctamente

**Solución:**
```bash
cd public_html

# Elimina si existe
rm -rf bitacora_tracker

# Clona de nuevo
git clone https://github.com/AlexisSM377/planificador-flotas.git bitacora_tracker

# Verifica
ls -la bitacora_tracker/src/index.html
```

### Causa 4: Permisos Incorrectos

**Solución:**
```bash
cd bitacora_tracker

# Dar permisos correctos
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# Hacer .htaccess legible
chmod 644 .htaccess
chmod 644 index.html
```

### Causa 5: .htaccess Bloqueando

**Solución:** Verifica que tu `.htaccess` está correcto:

```bash
cat .htaccess
```

Debe mostrar:
```
# Disable directory listing
Options -Indexes

# Security headers
<IfModule mod_headers.c>
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-Content-Type-Options "nosniff"
</IfModule>
```

Si hay más contenido, simplifica a esto.

---

## ✅ Verificación Final

### Desde el Navegador

**Test 1: Raíz del proyecto**
```
https://tu-dominio.com/bitacora_tracker/
```
Debe mostrar: La aplicación (redirección automática)

**Test 2: Página principal directo**
```
https://tu-dominio.com/bitacora_tracker/src/index.html
```
Debe mostrar: La aplicación completa

**Test 3: API de prueba**
```
https://tu-dominio.com/bitacora_tracker/src/api/test.php
```
Debe mostrar: `{"ok":true,"message":"✅ API is working correctly!",...}`

---

## 🐛 Debug: Ver Logs de Apache

En Hostinger, usa SSH:

```bash
# Ver últimos 50 líneas del error log
tail -50 /var/log/apache2/error.log

# O si estás en la carpeta del proyecto
tail -50 logs/error.log
```

Si ves un 404 ahí, Hostinger te dirá exactamente qué archivo no encuentra.

---

## 📞 Si Nada Funciona

**Usa este comando para verificar TODO:**

```bash
cd public_html/bitacora_tracker

echo "=== ESTRUCTURA ===" && ls -la src/ && \
echo "" && \
echo "=== .ENV ===" && ls -la .env && \
echo "" && \
echo "=== PERMISOS ===" && ls -l src/index.html && \
echo "" && \
echo "=== PRUEBA ===" && curl http://localhost/bitacora_tracker/src/index.html -I
```

Copia la salida y comparte conmigo si necesitas ayuda.

---

## 🎯 Estructura Correcta en Hostinger

Debería verse así:

```
public_html/
└── bitacora_tracker/          ← Carpeta del proyecto
    ├── .env                   ← Tu configuración
    ├── .env.example
    ├── .gitignore
    ├── .htaccess
    ├── index.html             ← Redirector
    ├── src/
    │   ├── config.php
    │   ├── RequestValidator.php
    │   ├── index.html         ← APP PRINCIPAL
    │   └── api/
    │       ├── sheets.php
    │       └── test.php
    ├── credentials/
    │   └── google.json
    ├── logs/
    └── vendor/
```

---

## 🚀 URLs Válidas en Hostinger

| URL | Destino |
|-----|---------|
| `https://tu-dominio.com/bitacora_tracker/` | src/index.html |
| `https://tu-dominio.com/bitacora_tracker/src/index.html` | src/index.html |
| `https://tu-dominio.com/bitacora_tracker/src/api/test.php` | API test |

Cualquier otra URL probablemente dé 404.

---

**Cuéntame:**
1. ¿Qué URL exacta estás usando?
2. ¿Dónde clonaste el repo?
3. ¿Qué muestra `ls -la public_html/`?
