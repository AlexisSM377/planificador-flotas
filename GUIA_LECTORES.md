# Guía de Funcionalidad: Lectores

## Descripción General

Esta funcionalidad permite gestionar lectores asociados a clientes específicos. Los lectores son usuarios con permisos de **solo lectura** que pueden visualizar información de las unidades que se les asignen específicamente.

## Flujo de Trabajo

### 1. Estructura de Datos

#### Hoja "Usuarios" en Google Sheets
La hoja de Usuarios gestiona los lectores con la siguiente estructura:

| Columna | Descripción |
|---------|-------------|
| A - usuario | Email del lector |
| B - password | Contraseña de acceso |
| C - role | Rol del usuario: **lector** (antes "subcliente") |
| D - clientes | Nombre del cliente al que pertenece |
| E - tabs_permitidos | Pestañas/tabs a los que tiene acceso (ej: 4,5,6,7) |
| F - activo | Estado del usuario (TRUE/FALSE) |

**IMPORTANTE**: La columna G (unidades_asignadas) **ha sido eliminada**.

#### Hoja "Datos" (Logística) en Google Sheets
La hoja de Datos ahora incluye la columna O para asignar el lector responsable:

| Columna | Descripción |
|---------|-------------|
| A - Fecha | Fecha de registro |
| B - Económico | ID de la unidad |
| C - Placas | Placas del vehículo |
| D - Equipos | Equipos instalados |
| E - Operador | Nombre del operador |
| F - Teléfonos | Teléfonos de contacto |
| G-M - Tramos | Información de rutas |
| N - Cliente | Nombre del cliente |
| **O - Lector Responsable** | **NUEVA** - Email del lector asignado a esta unidad |

### 2. Modelo de Asignación

**Relación**: Una unidad tiene UN lector responsable. Un lector puede ser responsable de MÚLTIPLES unidades.

```
Unidad (hoja Datos)     Lector (hoja Usuarios)
  └─ Cliente: Walmart   ┌─ Cliente: Walmart
  └─ Lector: juan@...   └─ Email: juan@walmart.com
```

**Regla**: Un lector SOLO puede ver las unidades donde su email aparece en la columna O (Lector Responsable).

### 3. Cómo Usar la Funcionalidad

#### Paso 1: Registrar una Unidad
1. Ve a la pestaña "Logística de Unidades"
2. Completa el campo **"Cliente"** (ej: "Walmart")
3. Automáticamente (después de 500ms) se cargarán los lectores existentes de ese cliente

#### Paso 2: Ver Lectores Existentes
1. Al escribir el nombre del Cliente, el sistema:
   - Espera 500ms (debounce)
   - Carga automáticamente los lectores de ese cliente desde Google Sheets
   - Muestra los lectores con badge **"Activo"** (verde)
   - Muestra el contador: "X activos | Y nuevos"
   - **Habilita el selector** "Lector Responsable de esta Unidad"

2. Si cambias el nombre del Cliente:
   - La lista de lectores se limpia automáticamente
   - Se cargan los lectores del nuevo cliente
   - El selector se actualiza

#### Paso 3: Seleccionar Lector Responsable
1. Una vez que se carguen los lectores, aparecerá un **selector morado**
2. Selecciona el lector que será responsable de esta unidad
3. **OBLIGATORIO**: Debes seleccionar un lector antes de guardar (si hay lectores disponibles)

#### Paso 4: Agregar Nuevos Lectores (Opcional)
1. Si el lector que necesitas no existe, haz clic en **"+ Agregar Lector"**
2. Completa el formulario:
   - **Email del Lector**: Correo electrónico del usuario
   - **Contraseña**: Contraseña para acceder al sistema
   - **Nombre Completo**: Nombre del lector
3. Haz clic en **"Agregar"**
4. El nuevo lector aparecerá:
   - En la lista con badge **"Nuevo"** (azul)
   - En el selector de "Lector Responsable"
5. Selecciona el nuevo lector como responsable si lo deseas

#### Paso 5: Guardar la Unidad
1. Completa el resto de la información de la unidad
2. **Asegúrate de seleccionar un Lector Responsable**
3. Haz clic en **"Guardar"**
4. El sistema automáticamente:
   - Guarda la unidad en la hoja "Datos" con el email del lector en columna O
   - **Solo crea** los lectores marcados como "Nuevo" en la hoja "Usuarios"
   - Convierte los lectores nuevos a "Activo" en la interfaz

### 4. Comportamiento del Sistema

#### Carga Automática
- Al escribir en el campo "Cliente", espera 500ms
- Busca en cache primero para evitar llamadas repetidas a la API
- Si no está en cache, consulta la hoja "Usuarios"
- Filtra solo lectores con `role = "lector"` y `clientes = [nombre del cliente]`
- Pobla el selector "Lector Responsable" automáticamente

#### Nuevo Lector
Cuando agregas un lector que será creado:
- Se muestra con badge **"Nuevo"** (azul)
- Aparece en el selector "Lector Responsable"
- Aparece el botón "×" para eliminarlo
- Al guardar la unidad, se crea en Google Sheets con:
  - Email y contraseña proporcionados
  - Role: **"lector"** (ya no "subcliente")
  - Clientes: El nombre del cliente de la unidad
  - Tabs permitidos: "4,5,6,7" (solo lectura)
  - Activo: TRUE

#### Lector Existente
Los lectores que ya existen en Google Sheets:
- Se muestran con badge **"Activo"** (verde)
- **NO** tienen botón "×" (no se pueden eliminar desde aquí)
- **NO** se sincronizan nuevamente al guardar

#### Badges del Sistema
- **Activo** (verde): Lector que ya existe en Google Sheets
- **Nuevo** (azul): Lector que será creado al guardar

### 5. Modelo de Asignación de Unidades

**NUEVO MODELO** (con columna O - Lector Responsable):
```
Hoja Usuarios:
  juan@walmart.com | pass | lector | Walmart | 4,5,6,7 | TRUE
  pedro@walmart.com | pass | lector | Walmart | 4,5,6,7 | TRUE

Hoja Datos:
  ECO-001 | ... | Walmart | juan@walmart.com    ← Juan PUEDE ver esta
  ECO-002 | ... | Walmart | pedro@walmart.com   ← Juan NO puede ver esta
  ECO-003 | ... | Walmart | juan@walmart.com    ← Juan PUEDE ver esta

Resultado:
  - Juan ve: ECO-001, ECO-003
  - Pedro ve: ECO-002
```

**Ventaja**: Control granular. Cada unidad tiene un lector responsable específico. Evita que todos los lectores de un cliente vean todas las unidades.

### 6. Características de Seguridad

- Los lectores tienen permisos de **solo lectura**
- Cada lector SOLO ve las unidades donde su email aparece en columna O
- No pueden editar ni eliminar información
- Los datos se validan en el servidor (RequestValidator.php)
- Solo se pueden agregar nuevos lectores, no eliminar los existentes

### 7. Manejo de la Interfaz

#### Selector de Lector Responsable
- Aparece automáticamente cuando se cargan lectores
- Color morado para alta visibilidad
- Muestra: "Nombre (email)"
- Es **OBLIGATORIO** seleccionar uno antes de guardar

#### Ver el Estado de Lectores
El contador muestra:
- **X activos**: Lectores que ya existen en Google Sheets
- **Y nuevos**: Lectores agregados pendientes de crear

#### Agregar Múltiples Lectores
Puedes agregar varios lectores al mismo cliente:
- Los existentes se muestran con badge verde
- Los nuevos se muestran con badge azul

#### Eliminar un Lector Nuevo
- Solo se pueden eliminar lectores con badge **"Nuevo"**
- Haz clic en el botón "×" al lado del lector
- El lector se eliminará de la lista local
- Los lectores **"Activo"** no se pueden eliminar desde la interfaz

#### Limpiar el Formulario
- Al hacer clic en "Limpiar Formulario", se limpian los lectores, el selector, y el cache

### 8. Ejemplo de Uso

**Escenario**: La empresa "Walmart" tiene dos supervisores: "Juan Pérez" y "Pedro López". Cada uno debe ver solo sus unidades asignadas.

#### Paso a Paso:

**1. Crear los lectores:**
- Registra primera unidad con Cliente = "Walmart"
- El sistema no encuentra lectores, así que agregas:
  - Juan Pérez (juan.perez@walmart.com)
  - Pedro López (pedro.lopez@walmart.com)
- Selecciona "Juan Pérez" como Lector Responsable
- Guarda la unidad ECO-001

**2. Registrar segunda unidad:**
- Nueva unidad con Cliente = "Walmart"
- El sistema carga automáticamente:
  - Juan Pérez (Activo - verde)
  - Pedro López (Activo - verde)
- Selecciona "Pedro López" como Lector Responsable
- Guarda la unidad ECO-002

**3. Registrar tercera unidad:**
- Nueva unidad con Cliente = "Walmart"
- Los lectores se cargan automáticamente
- Selecciona "Juan Pérez" como Lector Responsable
- Guarda la unidad ECO-003

**4. Resultado en Google Sheets:**

*Hoja Usuarios:*
```
juan.perez@walmart.com | pass123 | lector | Walmart | 4,5,6,7 | TRUE
pedro.lopez@walmart.com | pass456 | lector | Walmart | 4,5,6,7 | TRUE
```

*Hoja Datos (columnas relevantes):*
```
Fecha | Económico | ... | Cliente  | Lector Responsable
------|-----------|-----|----------|-------------------
2025  | ECO-001   | ... | Walmart  | juan.perez@walmart.com
2025  | ECO-002   | ... | Walmart  | pedro.lopez@walmart.com
2025  | ECO-003   | ... | Walmart  | juan.perez@walmart.com
```

**5. ¿Qué ve cada lector al iniciar sesión?**
- **Juan Pérez** ve: ECO-001, ECO-003 (sus unidades asignadas)
- **Pedro López** ve: ECO-002 (su unidad asignada)

**6. Ventaja:**
Si registras ECO-004 y asignas a Pedro López, automáticamente él podrá verla sin necesidad de actualizar su perfil.

## Archivos Modificados

1. **src/index.html**
   - Estilos CSS renombrados (subcliente → lector)
   - Nuevos badges: lector-badge-existing (verde), lector-badge-new (azul)
   - Sección HTML actualizada ("ASIGNAR LECTORES")
   - **Nuevo selector**: "Lector Responsable de esta Unidad" (morado)
   - Nueva función `loadLectoresByCliente()` con cache
   - Event listener con debounce (500ms) en campo Cliente
   - Función `renderLectores()` actualizada para poblar selector
   - Función `syncLectoresToSheets()` simplificada (solo APPEND)
   - **Validación**: Obliga a seleccionar lector antes de guardar

2. **src/api/sheets.php**
   - Rango actualizado de `A2:G100` a `A2:F100` para usuarios
   - **Rango extendido**: `A7:O1000` para logística (incluye columna O)
   - Eliminado el bloque `action === 'update'` (solo APPEND)

3. **src/RequestValidator.php**
   - Ya incluye "usuarios" en tipos válidos

## Cambios Respecto a la Versión Anterior

### Eliminaciones
- ❌ Columna G (unidades_asignadas) removida de hoja Usuarios
- ❌ Función `loadUsuarios()` eliminada
- ❌ Lógica UPDATE en sheets.php removida
- ❌ Capacidad de eliminar lectores existentes desde UI

### Mejoras
- ✅ Carga automática al escribir nombre de Cliente (debounce 500ms)
- ✅ Cache de lectores para evitar llamadas repetidas
- ✅ Badges de estado (Activo/Nuevo)
- ✅ Contador de lectores activos vs nuevos
- ✅ **Selector de Lector Responsable** con validación obligatoria
- ✅ **Columna O en hoja Datos** para asignación granular
- ✅ Control individual por unidad (no todos ven todo)
- ✅ Solo se crean nuevos lectores (no se actualizan existentes)

## Notas Importantes

- **DEBE agregarse manualmente** la columna O (Lector Responsable) en Google Sheets hoja "Datos"
- La columna O debe estar en la fila 6 con el encabezado "Lector Responsable"
- **NO es necesario** tener la columna G en Google Sheets hoja "Usuarios"
- Los lectores se cargan automáticamente al escribir el nombre del Cliente
- **Es OBLIGATORIO** seleccionar un Lector Responsable si hay lectores disponibles
- Solo se sincronizan lectores **nuevos** al guardar
- Los lectores existentes **no se pueden eliminar** desde la interfaz
- El cache se limpia al cambiar el nombre del Cliente
- La sincronización es **solo APPEND** (agregar), nunca UPDATE

## Troubleshooting

### Los lectores no se cargan automáticamente
- Verifica que el campo "Cliente" tenga un valor
- Espera 500ms después de escribir
- Revisa la consola del navegador para errores
- Verifica que existan lectores con ese cliente en Google Sheets

### El lector no aparece en Google Sheets
- Verifica que el lector tenga badge **"Nuevo"** (azul)
- Asegúrate de hacer clic en "Guardar"
- Revisa la consola para errores de API
- Verifica permisos de escritura en Google Sheets

### No puedo eliminar un lector
- Solo se pueden eliminar lectores con badge **"Nuevo"**
- Los lectores **"Activo"** (existentes) no se pueden eliminar desde la UI
- Para eliminar un lector existente, edita directamente Google Sheets

### El contador no se actualiza
- Verifica que `lectoresStatus` y `lectoresCount` existan en el HTML
- Revisa la función `renderLectores()` en la consola

### Error: "Debes seleccionar un Lector Responsable para esta unidad"
- Aparece cuando hay lectores disponibles pero no seleccionaste ninguno
- Abre el selector morado y elige un lector
- Si no quieres asignar lectores, no agregues ninguno a la lista

### Error: "Debes ingresar el nombre del Cliente primero"
- El campo "Cliente" debe tener un valor antes de agregar lectores
- Completa el campo Cliente y espera que carguen los lectores existentes

### La columna O no aparece en Google Sheets
- Debes agregarla manualmente
- Ve a la hoja "Datos" fila 6, columna O
- Escribe "Lector Responsable" como encabezado
- Los datos se guardarán automáticamente en esa columna

### El lector no ve sus unidades asignadas
- Verifica que el email del lector esté exactamente igual en:
  - Hoja "Usuarios" columna A (usuario)
  - Hoja "Datos" columna O (Lector Responsable)
- Revisa que el rol sea "lector" en la hoja "Usuarios"
- Verifica que "activo" sea TRUE en la hoja "Usuarios"
