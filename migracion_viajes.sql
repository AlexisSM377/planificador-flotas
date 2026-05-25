-- =============================================================
-- MIGRACIÓN: Viajes multi-tramo / multi-día
-- Proyecto: bitacora_ + bitacora_tracker
-- Fecha: 2026-05-25
--
-- Crea dos tablas nuevas:
--   viajes       → el despacho completo (puede abarcar varios días)
--   viaje_tramos → cada segmento/tramo del viaje
--
-- Las tablas existentes (despachos, unidades, clientes, usuarios)
-- NO se modifican — retrocompatibilidad total.
-- =============================================================

-- -------------------------------------------------------------
-- 1. VIAJES
--    Agrupa todos los tramos de un mismo despacho completo.
--    Una unidad puede tener N viajes en distintos rangos de fecha.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS viajes (
  id                    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id            INT(10) UNSIGNED NOT NULL,
  unidad_id             INT(10) UNSIGNED NOT NULL,
  folio                 VARCHAR(100)     NOT NULL DEFAULT '',
  fecha_inicio          DATE             NOT NULL,
  fecha_fin             DATE             NULL DEFAULT NULL,   -- NULL = mismo día que fecha_inicio
  estado                ENUM(
                          'planificado',
                          'en_curso',
                          'completado',
                          'cancelado'
                        ) NOT NULL DEFAULT 'planificado',
  notas                 TEXT             NULL DEFAULT NULL,
  created_by_usuario_id INT(10) UNSIGNED NULL DEFAULT NULL,
  created_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Búsqueda por tenant + rango de fechas (caso más frecuente)
  INDEX idx_viajes_cliente_fecha     (cliente_id, fecha_inicio, fecha_fin),
  -- Búsqueda por unidad (para detectar conflictos y timeline)
  INDEX idx_viajes_unidad_fecha      (unidad_id, fecha_inicio, fecha_fin),
  -- Búsqueda por folio dentro de un cliente
  INDEX idx_viajes_cliente_folio     (cliente_id, folio),
  -- Filtro por estado
  INDEX idx_viajes_estado            (estado),

  CONSTRAINT fk_viajes_cliente
    FOREIGN KEY (cliente_id)
    REFERENCES clientes (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_viajes_unidad
    FOREIGN KEY (unidad_id)
    REFERENCES unidades (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_viajes_created_by
    FOREIGN KEY (created_by_usuario_id)
    REFERENCES usuarios (id)
    ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 2. VIAJE_TRAMOS
--    Cada segmento del viaje. Un viaje tiene 1..N tramos.
--    Al borrar el viaje padre se eliminan en cascada sus tramos.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS viaje_tramos (
  id                    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  viaje_id              INT(10) UNSIGNED NOT NULL,
  tramo_numero          TINYINT UNSIGNED NOT NULL DEFAULT 1,

  -- Puntos geográficos
  origen                VARCHAR(255)     NULL DEFAULT NULL,
  lugar_carga           VARCHAR(255)     NULL DEFAULT NULL,
  destino               VARCHAR(255)     NULL DEFAULT NULL,
  ruta                  VARCHAR(500)     NULL DEFAULT NULL,   -- identificador o nombre de ruta

  -- Detalles operativos
  instrucciones         TEXT             NULL DEFAULT NULL,

  -- Programación horaria (todos opcionales para flexibilidad)
  salida_patio          DATETIME         NULL DEFAULT NULL,
  cita_carga            DATETIME         NULL DEFAULT NULL,
  salida_carga          DATETIME         NULL DEFAULT NULL,
  descarga_programada   DATETIME         NULL DEFAULT NULL,

  -- Estado individual del tramo
  estado                ENUM(
                          'pendiente',
                          'en_curso',
                          'completado',
                          'cancelado'
                        ) NOT NULL DEFAULT 'pendiente',

  created_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Garantiza que el número de tramo sea único dentro del viaje
  UNIQUE KEY uq_viaje_tramo (viaje_id, tramo_numero),

  -- Búsqueda de todos los tramos de un viaje (uso más frecuente)
  INDEX idx_viaje_tramos_viaje       (viaje_id),
  -- Filtros por estado de tramo
  INDEX idx_viaje_tramos_estado      (estado),

  CONSTRAINT fk_viaje_tramos_viaje
    FOREIGN KEY (viaje_id)
    REFERENCES viajes (id)
    ON DELETE CASCADE ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 3. VERIFICACIÓN POST-MIGRACIÓN
--    Ejecuta esto después para confirmar que todo quedó bien.
-- -------------------------------------------------------------
-- SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
--   FROM information_schema.TABLES
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME IN ('viajes', 'viaje_tramos');
--
-- SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME IN ('viajes', 'viaje_tramos')
--    AND REFERENCED_TABLE_NAME IS NOT NULL;
