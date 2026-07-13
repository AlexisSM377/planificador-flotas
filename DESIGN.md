---
name: Planificador de Flotas
description: Herramienta operativa para capturar viajes, rutas y horarios programados de flotilla
colors:
  primary-navy: "#051937"
  action-blue: "#006aa6"
  page-slate: "#e2e8f0"
  panel-white: "#ffffff"
  surface-soft: "#f8fafc"
  surface-muted: "#f1f5f9"
  border-muted: "#cbd5e1"
  text-main: "#051937"
  text-muted: "#4a5568"
  danger: "#e53e3e"
  success: "#047857"
  warning: "#d97706"
  info-soft: "#f0f9ff"
  info-border: "#bae6fd"
typography:
  display:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "26px"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "-0.04em"
  headline:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "20px"
    fontWeight: 800
    lineHeight: 1.25
  title:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 700
    lineHeight: 1.35
  body:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "14px"
    fontWeight: 500
    lineHeight: 1.5
  label:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "12px"
    fontWeight: 800
    lineHeight: 1.3
    letterSpacing: "0.03em"
rounded:
  sm: "8px"
  md: "10px"
  lg: "14px"
  xl: "18px"
  shell: "24px"
spacing:
  xs: "6px"
  sm: "10px"
  md: "14px"
  lg: "20px"
  xl: "30px"
components:
  button-primary:
    backgroundColor: "{colors.primary-navy}"
    textColor: "{colors.panel-white}"
    rounded: "{rounded.md}"
    padding: "10px 16px"
  button-accent:
    backgroundColor: "{colors.action-blue}"
    textColor: "{colors.panel-white}"
    rounded: "{rounded.md}"
    padding: "10px 16px"
  input:
    backgroundColor: "{colors.surface-soft}"
    textColor: "{colors.primary-navy}"
    rounded: "{rounded.md}"
    padding: "12px 14px"
  card:
    backgroundColor: "{colors.panel-white}"
    textColor: "{colors.text-main}"
    rounded: "{rounded.shell}"
    padding: "25px"
---

# Design System: Planificador de Flotas

## 1. Overview

**Creative North Star: "Mesa de despacho clara"**

El Planificador de Flotas es una herramienta de captura operativa. Su interfaz debe ayudar a llenar viajes completos con rapidez, separar datos de ruta de horarios programados y reducir errores antes de enviar informacion a la bitacora electronica.

La estetica actual usa una base clara, paneles blancos, azul marino para estructura y azul vivo para accion. El sistema debe sentirse sobrio y confiable, con densidad suficiente para formularios largos, pero sin parecer un dashboard promocional.

**Key Characteristics:**

- Interfaz de producto, no landing page.
- Formularios largos organizados en bloques legibles.
- Tabs, cards y botones familiares.
- Color usado para accion, estado o informacion.
- Jerarquia clara entre datos generales, ruta, carga y descarga.

## 2. Colors

La paleta combina azul marino estructural, azul de accion y superficies slate claras para capturas prolongadas.

### Primary

- **Azul Marino Operativo** (`#051937`): estructura principal, titulos, botones primarios y encabezados de unidad.
- **Azul de Accion** (`#006aa6`): tabs activos, foco de inputs, acciones secundarias y enlaces operativos. El tono se mantiene suficientemente oscuro para texto sobre blanco y botones con texto claro.

### Secondary

- **Info Azul Suave** (`#f0f9ff`): paneles de ayuda como historial de rutas y mensajes de contexto.
- **Borde Info** (`#bae6fd`): limites de paneles informativos sin elevar demasiado la superficie.

### Tertiary

- **Verde Confirmacion** (`#047857`): guardado exitoso y acciones positivas con contraste AA sobre texto blanco.
- **Rojo Riesgo** (`#e53e3e`): errores, eliminacion y acciones destructivas.
- **Ambar Atencion** (`#d97706`): prioridad o estados que requieren revision.

### Neutral

- **Fondo Slate** (`#e2e8f0`): fondo general de la aplicacion.
- **Superficie Blanca** (`#ffffff`): cards, formularios y paneles.
- **Superficie Suave** (`#f8fafc`): inputs, secciones internas y filas ligeras.
- **Borde Muted** (`#cbd5e1`): separacion entre cards, inputs y grupos.

### Named Rules

**The Planning vs Tracking Rule.** El color y las etiquetas deben reforzar que el cliente captura programacion, no seguimiento real.

**The Signal Color Rule.** Azul, verde, rojo y ambar se usan para accion o estado. No son decoracion.

## 3. Typography

**Display Font:** Plus Jakarta Sans, con fallback sans-serif  
**Body Font:** Plus Jakarta Sans, con fallback sans-serif  
**Label/Mono Font:** Plus Jakarta Sans para labels; usar monoespaciada solo cuando el dato sea tecnico o de fecha compacta.

**Character:** La tipografia es moderna pero funcional. Su peso alto en labels y titulos ayuda a escanear formularios densos.

### Hierarchy

- **Display** (800, 26px, 1.2): titulo principal de la aplicacion.
- **Headline** (800, 20px, 1.25): encabezados de seccion y tabs principales.
- **Title** (700, 14px, 1.35): titulos de tarjetas, unidades y tramos.
- **Body** (500, 14px, 1.5): inputs, datos de unidad, textos de apoyo y resultados.
- **Label** (800, 12px): etiquetas de formulario, metadatos y chips compactos. Evitar mayusculas forzadas en frases largas.

### Named Rules

**The Short Label Rule.** Labels de formulario deben ser cortos: Folio, Unidad, Ruta, Cita de Carga, Salida de Carga. No convertir frases largas a mayusculas.

**The Data First Rule.** No usar estilos de display en campos, botones ni tablas.

## 4. Elevation

La app usa cards blancas sobre fondo slate con sombras amplias para el shell principal. Dentro de formularios densos, debe preferirse borde y fondo tonal antes que nuevas sombras.

- **Shell Card:** sombra fuerte en `.card`, usada para separar la app del fondo.
- **Unit Card:** sombra media en tarjetas de unidades, con hover moderado.
- **Form Panels:** borde y fondo suave, sin sombras adicionales.

## 5. Components

### Buttons

Botones compactos, uppercase y de alta confianza. `btn-primary` usa azul marino, `btn-accent` usa azul de accion, `btn-success` usa verde y acciones destructivas usan rojo.

### Inputs

Inputs con fondo `#f8fafc`, borde muted y foco azul. Los campos de fecha deben usar `datetime-local` nativo para mantener fiabilidad operativa.

### Tabs

Tabs horizontales con activo en blanco y subrayado azul. En mobile pueden apilarse para no cortar texto.

### Cards

Cards de formulario y unidad usan radios amplios. Evitar cards dentro de cards cuando un bloque con borde y fondo tonal resuelve la jerarquia.

En listados dentro del shell principal, usar registros planos (`unit-record`) y filas con divisores (`tramo-item`, `trip-segment`) en lugar de nuevas cards con sombra. Las transiciones deben nombrar propiedades concretas (`background-color`, `border-color`, `color`, `box-shadow`, `transform`) y no usar `transition: all`.

### Schedule Sections

Los horarios de cada tramo se organizan en `Salida de Patio`, `Carga programada` y `Descarga programada`. `Carga programada` y `Descarga programada` pueden ser desplegables para reducir ruido en formularios largos.

### Route History Panel

Panel informativo azul suave para buscar rutas guardadas. Debe explicar su utilidad en una linea breve y no competir con los campos principales.

### Dynamic Template Utilities

Los templates generados por JavaScript deben reutilizar clases semanticas en vez de estilos inline cuando el patron se repite. Usar:

- `inline-note` con variantes `--empty`, `--warning` y `--danger` para mensajes compactos dentro de paneles.
- `unit-record` para listados de unidades dentro del shell principal, evitando cards anidadas.
- `trip-row`, `trip-segment`, `trip-state`, `trip-field` y `programmed-group` para viajes, tramos y horarios programados.
- `chip` con variantes `--info`, `--muted` y `--warning` para metadatos breves como uso, ultima fecha y multi-dia.
- `route-history-result` y sus subclases para resultados reutilizables del historial de rutas.

Los unicos estilos inline aceptables en estos templates son variables dinamicas inevitables, por ejemplo `--trip-state-color` cuando el color depende del estado recibido por API.

## 6. Do's and Don'ts

### Do:

- Separar claramente datos de ruta, programacion de carga y programacion de descarga.
- Mantener salida de patio como dato programado de arranque, no como seguimiento real.
- Usar labels cortas y consistentes.
- Mantener foco visible en inputs y botones.
- Usar paneles desplegables para reducir carga visual en tramos largos.

### Don't:

- No mezclar captura del cliente con seguimiento real del monitorista.
- No usar color como adorno.
- No convertir el formulario en dashboard o landing page.
- No ocultar campos criticos detras de interacciones poco familiares.
- No agregar nuevas sombras o gradientes decorativos en bloques internos.
