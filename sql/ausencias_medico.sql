-- =============================================================
-- MediTurnos — Ausencias de médicos (médico que no asiste un día)
-- Ejecutar sobre la base "mediturnos" DESPUÉS de seed_datos.sql
-- (phpMyAdmin o consola MySQL). Requiere motor InnoDB.
--
-- OBJETIVO: que el admin o la recepcionista puedan marcar que un
-- médico NO va a atender un día puntual. Al registrar la ausencia:
--   1) se cancelan automáticamente los turnos activos de ese día, y
--   2) ese día deja de ofrecerse como disponible al paciente.
--
-- Por qué esto es un archivo .sql aparte y no una edición de seed_datos.sql:
-- esta funcionalidad (igual que pagos_y_descuentos.sql y baja_medico.sql)
-- se agregó DESPUÉS de tener la base ya en uso con datos cargados;
-- reescribir seed_datos.sql habría significado que cualquiera que ya
-- tuviera la base corriendo debía borrar todo y recrearla desde cero
-- para tener la tabla nueva. Como migración incremental (CREATE TABLE
-- IF NOT EXISTS + ALTER), se puede aplicar sobre una base ya existente
-- sin perder los datos que ya tenía cargados. Por esto mismo, el código
-- PHP que depende de estas tablas (Turno::hayAusencia, Pago::pendientes,
-- etc.) siempre las consulta con try/catch: si la migración todavía no
-- se corrió en una base dada, el sistema sigue funcionando en vez de
-- romperse por completo.
-- =============================================================

USE mediturnos;

-- -------------------------------------------------------------
-- Tabla de ausencias.
--   matricula      -> médico que falta (FK a medico)
--   fecha          -> día puntual de la ausencia (un solo día)
--   motivo         -> texto libre (enfermedad, licencia, etc.)
--   registrado_por -> usuario (admin/recepcionista) que la cargó
--   fecha_registro -> cuándo se cargó (auditoría)
--
-- La clave única (matricula, fecha) evita marcar dos veces al mismo
-- médico el mismo día: el segundo intento recibe el error 1062 y el
-- PHP lo traduce a un mensaje claro.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ausencia_medico (
    id_ausencia    INT AUTO_INCREMENT PRIMARY KEY,
    matricula      INT  NOT NULL,
    fecha          DATE NOT NULL,
    motivo         VARCHAR(255),
    registrado_por INT,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ausencia (matricula, fecha),
    FOREIGN KEY (matricula)      REFERENCES medico(matricula),
    FOREIGN KEY (registrado_por) REFERENCES usuario(id_usuario)
) ENGINE = InnoDB;

-- Índice para resolver rápido "¿este médico falta este día?"
-- (la consulta que se hace al generar los horarios disponibles).
CREATE INDEX IF NOT EXISTS idx_ausencia_med_fecha
    ON ausencia_medico (matricula, fecha);
