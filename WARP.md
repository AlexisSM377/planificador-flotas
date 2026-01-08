# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

This is a standalone HTML application for **fleet logistics planning and contact directory management** for Tracker México GPS. The entire application is contained in a single self-contained HTML file with embedded CSS and JavaScript.

**Core Functionality:**
- **Logística de Unidades**: Fleet planning with vehicle information, operator contacts, and multi-segment trip planning (tramos logísticos)
- **Datos de Contacto**: Contact directory for monitoring centers, security personnel, emergency contacts, and maintenance teams with priority classification
- **Excel Export**: Client-side Excel generation using SheetJS (XLSX library) for both logistics and contact data

## Architecture

### Technology Stack
- Pure HTML5, CSS3, and vanilla JavaScript
- No build tools, transpilers, or bundlers required
- External dependencies loaded via CDN:
  - SheetJS (xlsx.full.min.js) for Excel generation
  - Google Fonts (Plus Jakarta Sans)
  - **Google Sheets API** (planned) for data persistence

### Application Structure

**Single-page application with tab-based navigation:**

1. **Tab 1 - Logística de Unidades**
   - Form for vehicle and operator data
   - Dynamic phone and equipment ID fields
   - Multi-segment dispatch planning (tramos)
   - Queue system to hold multiple units before export
   - Export all queued units to Excel

2. **Tab 2 - Datos de Contacto**
   - Contact form with priority classification (Crítica/Relevante)
   - Area categorization (Monitoreo, Seguridad, Emergencias, Mantenimiento)
   - Multi-action protocol selection (Llamada, Correo, Informe)
   - Dynamic phone and email fields
   - Contact table with inline removal
   - Export contacts to Excel

### Key Design Patterns

**State Management:**
- Three global arrays: `unitsQueue`, `contactsList`, `dispatchCount`
- **Current**: Data stored in memory only; lost on page reload
- **Planned**: Data persisted to Google Sheets for permanent storage
- UI updates via direct DOM manipulation

**Dynamic Form Generation:**
- Dispatch segments (tramos) are dynamically added/removed
- Phone and email fields support add/remove functionality
- Contact action checkboxes with visual chip styling

**Excel Generation:**
- Uses `XLSX.utils.json_to_sheet()` to convert data arrays
- Two separate export functions: `exportFinalExcel()` for logistics, `exportContactsExcel()` for contacts
- Data normalization happens during export (e.g., arrays joined with " / ")

## Development

### Running the Application

Simply open the HTML file in any modern web browser:

```pwsh
Start-Process "Planificación de Desapachos 2026 .html"
```

Or use the default browser:

```pwsh
Invoke-Item "Planificación de Desapachos 2026 .html"
```

**No server required** - the application runs entirely client-side.

### Testing Changes

There are no automated tests. To test:

1. Open the file in browser
2. Manually test each tab's functionality
3. Test form validation (required fields: Económico, Operador for logistics; Nombre + at least one contact method for contacts)
4. Test dynamic field addition/removal
5. Test Excel exports with sample data
6. Check responsive behavior (media query at 600px)

### Code Style

**Existing conventions in the codebase:**
- Spanish language for all UI text, form labels, and variable names related to business logic
- Inline JavaScript within `<script>` tags at end of body
- CSS custom properties (CSS variables) for theming in `:root`
- ES6+ features used (arrow functions, template literals, `const`/`let`)
- camelCase for JavaScript functions and variables
- kebab-case for CSS classes

**When making changes:**
- Keep all functionality in the single HTML file
- Maintain the existing visual design system (colors, spacing, typography)
- Use the existing toast notification system (`showToast()`) for user feedback
- Follow the established naming patterns for form fields (e.g., `d-*` for dispatch fields, `c-*` for contact fields, `u-*` for unit fields)

## Common Modifications

### Adding New Form Fields

Add fields in three places:
1. HTML form markup in the appropriate tab section
2. Data collection in the respective save function (`saveUnitToQueue()` or `addContact()`)
3. Export mapping in the Excel generation function

### Modifying Validation

Update the validation logic in:
- `saveUnitToQueue()` for logistics data (lines 947-953)
- `addContact()` for contact data (lines 847-850)

### Changing Export Format

Modify the data transformation in:
- `exportFinalExcel()` for logistics (lines 1006-1030)
- `exportContactsExcel()` for contacts (lines 1032-1047)

### Adding New Tabs

Follow the existing pattern:
1. Add new `<button class="tab-btn">` in the tabs nav (lines 454-464)
2. Add new `<div id="newTab" class="tab-content">` section
3. Ensure `onclick="openTab(event, 'newTab')"` is set

## Google Sheets Integration (Planned)

### Implementation Approach

To persist form data to Google Sheets, implement using **Google Apps Script Web App** as a backend proxy:

**Option 1: Google Apps Script (Recommended)**

1. Create a Google Apps Script project bound to the target spreadsheet
2. Deploy as Web App with appropriate permissions
3. Use `doPost(e)` to receive data from the HTML form
4. Write to specific sheets: one for logistics data, one for contacts

**Option 2: Direct Google Sheets API v4**

1. Enable Google Sheets API in Google Cloud Console
2. Create OAuth 2.0 credentials or API key
3. Use `gapi.client.sheets` to interact with spreadsheet
4. Handle authentication flow in the HTML app

### Recommended Implementation Pattern

**Apps Script Deployment (Server-side):**

```javascript
function doPost(e) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const data = JSON.parse(e.postData.contents);
  
  if (data.type === 'logistics') {
    const sheet = ss.getSheetByName('Logística');
    // Append rows for each tramo
    data.units.forEach(unit => {
      unit.tramos.forEach((tramo, idx) => {
        sheet.appendRow([
          unit.fecha, unit.id, unit.placas, unit.operador,
          unit.telefonos.join(' / '), unit.equipos.join(' / '),
          idx + 1, tramo.ruta, tramo.patio, tramo.cita,
          tramo.salida, tramo.descarga
        ]);
      });
    });
  } else if (data.type === 'contact') {
    const sheet = ss.getSheetByName('Contactos');
    sheet.appendRow([
      data.prioridad, data.area, data.nombre, data.cargo,
      data.telefonos, data.correos, data.acciones, data.observaciones
    ]);
  }
  
  return ContentService.createTextOutput(JSON.stringify({success: true}));
}
```

**HTML Client-side Integration:**

```javascript
const SCRIPT_URL = 'https://script.google.com/macros/s/YOUR_DEPLOYMENT_ID/exec';

async function saveToGoogleSheets(data) {
  try {
    const response = await fetch(SCRIPT_URL, {
      method: 'POST',
      mode: 'no-cors',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(data)
    });
    showToast('✓ Datos guardados en Google Sheets');
  } catch (error) {
    showToast('⚠️ Error al guardar: ' + error.message);
  }
}
```

### Integration Points

Modify these functions to add Google Sheets persistence:

1. **`exportFinalExcel()` (lines 1006-1030)**
   - Before or after Excel export, call `saveToGoogleSheets({type: 'logistics', units: unitsQueue})`
   - Or create separate "Guardar a **Sheets**" button

2. **`addContact()` (lines 828-888)**
   - After adding to `contactsList`, call `saveToGoogleSheets({type: 'contact', ...contactObj})`
   - Provides immediate persistence per contact

3. **`saveUnitToQueue()` (lines 947-979)**
   - Optionally save each unit immediately instead of queuing
   - Or maintain queue and batch-save on export

### Google Sheets Structure

**Sheet 1: "Logística"**
- Columns: Fecha Registro | Económico | Placas | Operador | Tels Operador | Equipos Aliados | No. Tramo | Ruta | Salida Patio | Cita Carga | Salida Carga | Descarga

**Sheet 2: "Contactos"**
- Columns: Prioridad | Área | Nombre | Cargo | Teléfonos | Correos | Protocolo Acción | Observaciones

### Setup Steps

1. Create target Google Spreadsheet with two sheets: "Logística" and "Contactos"
2. Add header rows to each sheet
3. Go to Extensions > Apps Script
4. Paste the `doPost(e)` function
5. Deploy > New deployment > Web app
6. Set "Execute as: Me" and "Who has access: Anyone"
7. Copy the deployment URL
8. Update `SCRIPT_URL` constant in HTML file
9. Test with sample data

### Security Considerations

- Apps Script Web App runs with your permissions (be cautious with "Anyone" access)
- Consider adding a simple API key check in `doPost()` to prevent abuse
- For production, use OAuth 2.0 flow with proper authentication
- Validate and sanitize all incoming data in Apps Script

## Important Notes

- **Data persistence**: Currently all data is lost on page reload; Google Sheets integration will solve this
- **No backend**: Currently everything runs client-side; Google Apps Script will serve as lightweight backend
- **Browser compatibility**: Requires modern browser with ES6 support and fetch API
- **File naming**: The HTML file has a space at the end of the filename ("Planificación de Desapachos 2026 .html") - this is intentional per the current naming
- **Logo**: Uses external image hosting (postimg.cc) for the company logo
