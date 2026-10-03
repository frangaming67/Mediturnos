-- =============================================================
-- MediTurnos — base de demostración
-- =============================================================
-- GENERADO AUTOMÁTICAMENTE por publicacion/generar_base_demo.sh.
-- No editar a mano: se regenera y se pierden los cambios.
--
-- Contiene el esquema completo (28 tablas, 5 vistas, triggers y
-- procedimientos), los catálogos reales de la clínica y tres cuentas de
-- demostración con algo de actividad.
--
-- NO contiene ningún dato de los pacientes del entorno de desarrollo.
--
-- ── CÓMO SE IMPORTA ──────────────────────────────────────────
-- En el panel del hosting: crear la base, entrar a phpMyAdmin, elegir la
-- base recién creada, pestaña "Importar", subir este archivo.
--
-- Por línea de comandos, si el hosting da acceso:
--     mysql -u USUARIO -p NOMBRE_BASE < mediturnos_demo.sql
--
-- IMPORTANTE: el archivo NO lleva CREATE DATABASE ni USE. La base la
-- crea el panel del hosting, que además es quien decide cómo se llama
-- —en muchos casos le pone un prefijo, tipo `miusuario_mediturnos`—. Un
-- USE con el nombre equivocado haría fallar la importación entera.
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '-03:00';

-- ══════════════════════════════════════════════════════════
-- ESTRUCTURA
-- ══════════════════════════════════════════════════════════

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ausencia_medico` (
  `id_ausencia` int(11) NOT NULL AUTO_INCREMENT,
  `matricula` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `registrado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_ausencia`),
  UNIQUE KEY `uq_ausencia` (`matricula`,`fecha`),
  KEY `registrado_por` (`registrado_por`),
  KEY `idx_ausencia_med_fecha` (`matricula`,`fecha`),
  CONSTRAINT `ausencia_medico_ibfk_1` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`),
  CONSTRAINT `ausencia_medico_ibfk_2` FOREIGN KEY (`registrado_por`) REFERENCES `usuario` (`id_usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calificacion` (
  `id_calificacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_turno` int(11) NOT NULL,
  `matricula` int(11) NOT NULL,
  `id_paciente` int(11) NOT NULL,
  `puntaje` tinyint(4) NOT NULL,
  `comentario` varchar(400) DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_calificacion`),
  UNIQUE KEY `id_turno` (`id_turno`),
  KEY `fk_calificacion_paciente` (`id_paciente`),
  KEY `idx_calificacion_medico` (`matricula`,`puntaje`),
  CONSTRAINT `fk_calificacion_medico` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`),
  CONSTRAINT `fk_calificacion_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON DELETE CASCADE,
  CONSTRAINT `fk_calificacion_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`) ON DELETE CASCADE,
  CONSTRAINT `chk_calificacion_puntaje` CHECK (`puntaje` between 1 and 5)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `consulta` (
  `id_consulta` int(11) NOT NULL AUTO_INCREMENT,
  `id_turno` int(11) NOT NULL,
  `motivo_consulta` varchar(200) NOT NULL,
  `diagnostico` text DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `matricula` int(11) NOT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_consulta`),
  UNIQUE KEY `id_turno` (`id_turno`),
  KEY `fk_consulta_medico` (`matricula`),
  CONSTRAINT `fk_consulta_medico` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`),
  CONSTRAINT `fk_consulta_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `consultorio` (
  `id_consultorio` int(11) NOT NULL AUTO_INCREMENT,
  `numero` int(11) NOT NULL,
  `piso` int(11) NOT NULL,
  `descripcion_equipamiento` varchar(600) DEFAULT NULL,
  PRIMARY KEY (`id_consultorio`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `descuento_os_medico` (
  `id_descuento` int(11) NOT NULL AUTO_INCREMENT,
  `id_obra_social` int(11) NOT NULL,
  `matricula` int(11) NOT NULL,
  `porcentaje_descuento` decimal(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_descuento`),
  UNIQUE KEY `uq_desc_os_med` (`id_obra_social`,`matricula`),
  KEY `matricula` (`matricula`),
  CONSTRAINT `descuento_os_medico_ibfk_1` FOREIGN KEY (`id_obra_social`) REFERENCES `obra_social` (`id_obra_social`),
  CONSTRAINT `descuento_os_medico_ibfk_2` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `especialidad` (
  `id_especialidad` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `duracion_turno_min` int(11) DEFAULT NULL,
  `precio_consulta` decimal(10,2) NOT NULL DEFAULT 5000.00,
  PRIMARY KEY (`id_especialidad`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estado_turno` (
  `id_estado` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(30) NOT NULL,
  PRIMARY KEY (`id_estado`),
  UNIQUE KEY `descripcion` (`descripcion`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estudio` (
  `id_estudio` int(11) NOT NULL AUTO_INCREMENT,
  `id_paciente` int(11) NOT NULL,
  `id_turno` int(11) DEFAULT NULL,
  `matricula` int(11) NOT NULL,
  `tipo` varchar(60) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `archivo` varchar(255) DEFAULT NULL,
  `estado` enum('Pendiente','Disponible') NOT NULL DEFAULT 'Pendiente',
  `solicitado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `resultado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id_estudio`),
  KEY `fk_estudio_turno` (`id_turno`),
  KEY `fk_estudio_medico` (`matricula`),
  KEY `idx_estudio_paciente` (`id_paciente`,`solicitado_en`),
  CONSTRAINT `fk_estudio_medico` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`),
  CONSTRAINT `fk_estudio_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON DELETE CASCADE,
  CONSTRAINT `fk_estudio_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_turno` (
  `id_hist` int(11) NOT NULL AUTO_INCREMENT,
  `id_turno` int(11) NOT NULL,
  `estado_anterior` varchar(30) DEFAULT NULL,
  `estado_nuevo` varchar(30) DEFAULT NULL,
  `fecha_cambio` datetime DEFAULT current_timestamp(),
  `observacion` text DEFAULT NULL,
  PRIMARY KEY (`id_hist`),
  KEY `id_turno` (`id_turno`),
  CONSTRAINT `historial_turno_ibfk_1` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=207 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `horario_atencion` (
  `id_horario` int(11) NOT NULL AUTO_INCREMENT,
  `matricula` int(11) NOT NULL,
  `id_especialidad` int(11) NOT NULL,
  `dia_semana` varchar(15) NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `id_consultorio` int(11) NOT NULL,
  PRIMARY KEY (`id_horario`),
  KEY `matricula` (`matricula`),
  KEY `id_especialidad` (`id_especialidad`),
  KEY `id_consultorio` (`id_consultorio`),
  CONSTRAINT `horario_atencion_ibfk_1` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`) ON UPDATE CASCADE,
  CONSTRAINT `horario_atencion_ibfk_2` FOREIGN KEY (`id_especialidad`) REFERENCES `especialidad` (`id_especialidad`) ON UPDATE CASCADE,
  CONSTRAINT `horario_atencion_ibfk_3` FOREIGN KEY (`id_consultorio`) REFERENCES `consultorio` (`id_consultorio`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `intento_login` (
  `id_intento` int(11) NOT NULL AUTO_INCREMENT,
  `identificador` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `exito` tinyint(1) NOT NULL DEFAULT 0,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_intento`),
  KEY `idx_intento_busqueda` (`identificador`,`ip`,`fecha`),
  KEY `idx_intento_fecha` (`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `medico` (
  `matricula` int(11) NOT NULL,
  `nombre` varchar(30) NOT NULL,
  `apellido` varchar(30) NOT NULL,
  `telefono` varchar(30) NOT NULL,
  `email` varchar(120) NOT NULL,
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_baja` date DEFAULT NULL,
  PRIMARY KEY (`matricula`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `medico_especialidad` (
  `matricula` int(11) NOT NULL,
  `id_especialidad` int(11) NOT NULL,
  PRIMARY KEY (`matricula`,`id_especialidad`),
  KEY `id_especialidad` (`id_especialidad`),
  CONSTRAINT `medico_especialidad_ibfk_1` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `medico_especialidad_ibfk_2` FOREIGN KEY (`id_especialidad`) REFERENCES `especialidad` (`id_especialidad`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notificacion` (
  `id_notificacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `titulo` varchar(120) NOT NULL,
  `mensaje` varchar(400) NOT NULL,
  `url_accion` varchar(255) DEFAULT NULL,
  `id_referencia` int(11) DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  `leida_en` datetime DEFAULT NULL,
  `email_enviado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id_notificacion`),
  KEY `idx_notif_usuario` (`id_usuario`,`creada_en`),
  KEY `idx_notif_sin_leer` (`id_usuario`,`leida_en`),
  CONSTRAINT `fk_notificacion_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=183 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `obra_social` (
  `id_obra_social` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(30) NOT NULL,
  `cuit` varchar(20) NOT NULL,
  PRIMARY KEY (`id_obra_social`),
  UNIQUE KEY `cuit` (`cuit`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paciente` (
  `id_paciente` int(11) NOT NULL AUTO_INCREMENT,
  `dni` varchar(15) NOT NULL,
  `nombre` varchar(30) NOT NULL,
  `apellido` varchar(30) NOT NULL,
  `fecha_nac` date DEFAULT NULL,
  `sexo` enum('F','M','X','prefiero_no_decir') DEFAULT NULL,
  `telefono` varchar(30) NOT NULL,
  `direccion` varchar(150) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id_paciente`),
  UNIQUE KEY `dni` (`dni`),
  KEY `idx_paciente_dni` (`dni`)
) ENGINE=InnoDB AUTO_INCREMENT=1069 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paciente_plan` (
  `id_paciente` int(11) NOT NULL,
  `id_plan` int(11) NOT NULL,
  `nro_afiliado` varchar(50) DEFAULT NULL,
  `fecha_alta` date DEFAULT NULL,
  PRIMARY KEY (`id_paciente`,`id_plan`),
  KEY `id_plan` (`id_plan`),
  CONSTRAINT `paciente_plan_ibfk_1` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `paciente_plan_ibfk_2` FOREIGN KEY (`id_plan`) REFERENCES `plan_os` (`id_plan`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pago` (
  `id_pago` int(11) NOT NULL AUTO_INCREMENT,
  `id_turno` int(11) NOT NULL,
  `monto_base` decimal(10,2) NOT NULL,
  `porcentaje_descuento` decimal(5,2) NOT NULL DEFAULT 0.00,
  `monto_total` decimal(10,2) GENERATED ALWAYS AS (round(`monto_base` * (1 - `porcentaje_descuento` / 100),2)) STORED,
  `estado` varchar(20) NOT NULL DEFAULT 'Pendiente',
  `metodo` varchar(20) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_vencimiento` datetime NOT NULL,
  `fecha_pago` datetime DEFAULT NULL,
  `tarjeta_ult4` varchar(4) DEFAULT NULL,
  `tarjeta_titular` varchar(100) DEFAULT NULL,
  `referencia` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id_pago`),
  UNIQUE KEY `uq_pago_turno` (`id_turno`),
  KEY `idx_pago_estado` (`estado`),
  KEY `idx_pago_venc` (`fecha_vencimiento`),
  CONSTRAINT `pago_ibfk_1` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`)
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset` (
  `id_reset` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `expira_en` datetime NOT NULL,
  `usado_en` datetime DEFAULT NULL,
  `ip_solicito` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id_reset`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_usuario` (`id_usuario`),
  KEY `idx_reset_expira` (`expira_en`),
  CONSTRAINT `fk_reset_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permiso` (
  `id_permiso` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id_permiso`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plan_os` (
  `id_plan` int(11) NOT NULL AUTO_INCREMENT,
  `id_obra_social` int(11) NOT NULL,
  `nombre_plan` varchar(100) NOT NULL,
  `porcentaje_cobertura` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id_plan`),
  KEY `id_obra_social` (`id_obra_social`),
  CONSTRAINT `plan_os_ibfk_1` FOREIGN KEY (`id_obra_social`) REFERENCES `obra_social` (`id_obra_social`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receta` (
  `id_receta` int(11) NOT NULL AUTO_INCREMENT,
  `id_paciente` int(11) NOT NULL,
  `matricula` int(11) NOT NULL,
  `id_turno` int(11) DEFAULT NULL,
  `diagnostico` varchar(200) DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `emitida_el` date NOT NULL,
  `vence_el` date NOT NULL,
  `estado` enum('Vigente','Anulada') NOT NULL DEFAULT 'Vigente',
  `anulada_motivo` varchar(200) DEFAULT NULL,
  `anulada_el` datetime DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_receta`),
  KEY `fk_receta_turno` (`id_turno`),
  KEY `idx_receta_paciente` (`id_paciente`,`emitida_el`),
  KEY `idx_receta_medico` (`matricula`,`emitida_el`),
  CONSTRAINT `fk_receta_medico` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`),
  CONSTRAINT `fk_receta_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON DELETE CASCADE,
  CONSTRAINT `fk_receta_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`) ON DELETE SET NULL,
  CONSTRAINT `chk_receta_vigencia` CHECK (`vence_el` >= `emitida_el`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receta_medicamento` (
  `id_item` int(11) NOT NULL AUTO_INCREMENT,
  `id_receta` int(11) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `presentacion` varchar(100) DEFAULT NULL,
  `dosis` varchar(100) NOT NULL,
  `frecuencia` varchar(100) NOT NULL,
  `duracion` varchar(100) DEFAULT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_item`),
  KEY `idx_item_receta` (`id_receta`),
  CONSTRAINT `fk_item_receta` FOREIGN KEY (`id_receta`) REFERENCES `receta` (`id_receta`) ON DELETE CASCADE,
  CONSTRAINT `chk_item_cantidad` CHECK (`cantidad` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `renovacion_receta` (
  `id_renovacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_receta` int(11) NOT NULL,
  `id_paciente` int(11) NOT NULL,
  `motivo` varchar(300) DEFAULT NULL,
  `estado` enum('Pendiente','Aprobada','Rechazada') NOT NULL DEFAULT 'Pendiente',
  `respuesta` varchar(300) DEFAULT NULL,
  `matricula` int(11) DEFAULT NULL,
  `id_receta_nueva` int(11) DEFAULT NULL,
  `solicitada_en` datetime NOT NULL DEFAULT current_timestamp(),
  `resuelta_en` datetime DEFAULT NULL,
  `pendiente_unica` int(11) GENERATED ALWAYS AS (if(`estado` = 'Pendiente',`id_receta`,NULL)) STORED,
  PRIMARY KEY (`id_renovacion`),
  UNIQUE KEY `uq_renov_pendiente` (`pendiente_unica`),
  KEY `fk_renov_receta` (`id_receta`),
  KEY `fk_renov_medico` (`matricula`),
  KEY `fk_renov_nueva` (`id_receta_nueva`),
  KEY `idx_renov_estado` (`estado`,`solicitada_en`),
  KEY `idx_renov_paciente` (`id_paciente`,`solicitada_en`),
  CONSTRAINT `fk_renov_medico` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`),
  CONSTRAINT `fk_renov_nueva` FOREIGN KEY (`id_receta_nueva`) REFERENCES `receta` (`id_receta`) ON DELETE SET NULL,
  CONSTRAINT `fk_renov_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON DELETE CASCADE,
  CONSTRAINT `fk_renov_receta` FOREIGN KEY (`id_receta`) REFERENCES `receta` (`id_receta`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rol` (
  `id_rol` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id_rol`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rol_permiso` (
  `id_rol` int(11) NOT NULL,
  `id_permiso` int(11) NOT NULL,
  PRIMARY KEY (`id_rol`,`id_permiso`),
  KEY `id_permiso` (`id_permiso`),
  CONSTRAINT `fk_rolpermiso_permiso` FOREIGN KEY (`id_permiso`) REFERENCES `permiso` (`id_permiso`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rolpermiso_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `turno` (
  `id_turno` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `hora_inicio` time NOT NULL,
  `id_estado` int(11) NOT NULL DEFAULT 1,
  `observacion` text DEFAULT NULL,
  `id_paciente` int(11) NOT NULL,
  `matricula` int(11) NOT NULL,
  `id_especialidad` int(11) NOT NULL,
  `id_consultorio` int(11) NOT NULL,
  `id_plan` int(11) NOT NULL,
  `slot_unico` varchar(60) GENERATED ALWAYS AS (if(`id_estado` = 5,NULL,concat(`matricula`,'|',`fecha`,'|',`hora_inicio`))) STORED,
  `slot_consultorio` varchar(60) GENERATED ALWAYS AS (if(`id_estado` = 5,NULL,concat(`id_consultorio`,'|',`fecha`,'|',`hora_inicio`))) STORED,
  PRIMARY KEY (`id_turno`),
  UNIQUE KEY `uq_turno_slot` (`slot_unico`),
  UNIQUE KEY `uq_turno_consultorio` (`slot_consultorio`),
  KEY `id_paciente` (`id_paciente`),
  KEY `matricula` (`matricula`),
  KEY `id_especialidad` (`id_especialidad`),
  KEY `id_consultorio` (`id_consultorio`),
  KEY `id_plan` (`id_plan`),
  KEY `idx_turno_fecha` (`fecha`),
  KEY `idx_turno_medico` (`matricula`),
  KEY `idx_turno_paciente` (`id_paciente`),
  KEY `idx_turno_estado` (`id_estado`),
  CONSTRAINT `fk_turno_estado` FOREIGN KEY (`id_estado`) REFERENCES `estado_turno` (`id_estado`),
  CONSTRAINT `turno_ibfk_1` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON UPDATE CASCADE,
  CONSTRAINT `turno_ibfk_2` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`) ON UPDATE CASCADE,
  CONSTRAINT `turno_ibfk_3` FOREIGN KEY (`id_especialidad`) REFERENCES `especialidad` (`id_especialidad`) ON UPDATE CASCADE,
  CONSTRAINT `turno_ibfk_4` FOREIGN KEY (`id_consultorio`) REFERENCES `consultorio` (`id_consultorio`) ON UPDATE CASCADE,
  CONSTRAINT `turno_ibfk_5` FOREIGN KEY (`id_plan`) REFERENCES `plan_os` (`id_plan`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_turno_after_insert
AFTER INSERT ON turno
FOR EACH ROW
BEGIN
    INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
    VALUES (
        NEW.id_turno,
        NULL,
        (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
        'Turno creado'
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_turno_after_update
AFTER UPDATE ON turno
FOR EACH ROW
BEGIN
    
    IF OLD.id_estado <> NEW.id_estado THEN
        INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
        VALUES (
            NEW.id_turno,
            (SELECT descripcion FROM estado_turno WHERE id_estado = OLD.id_estado),
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            NEW.observacion
        );

    
    
    
    
    
    
    
    
    
    
    
    ELSEIF OLD.fecha <> NEW.fecha OR OLD.hora_inicio <> NEW.hora_inicio THEN
        INSERT INTO historial_turno (id_turno, estado_anterior, estado_nuevo, observacion)
        VALUES (
            NEW.id_turno,
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            (SELECT descripcion FROM estado_turno WHERE id_estado = NEW.id_estado),
            CONCAT('Reprogramado: ',
                   DATE_FORMAT(OLD.fecha, '%d/%m/%Y'), ' ', TIME_FORMAT(OLD.hora_inicio, '%H:%i'),
                   ' â†’ ',
                   DATE_FORMAT(NEW.fecha, '%d/%m/%Y'), ' ', TIME_FORMAT(NEW.hora_inicio, '%H:%i'),
                   IF(NEW.observacion IS NULL OR NEW.observacion = '',
                      '', CONCAT('. ', NEW.observacion)))
        );
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuario` (
  `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(30) NOT NULL,
  `apellido` varchar(30) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `email` varchar(120) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `contrasenia` varchar(255) NOT NULL COMMENT 'Hash bcrypt — nunca texto plano',
  `fecha_alta` date NOT NULL DEFAULT curdate(),
  `fecha_baja` date DEFAULT NULL,
  `estado` varchar(10) NOT NULL DEFAULT 'activo',
  `id_rol` int(11) NOT NULL,
  `id_paciente` int(11) DEFAULT NULL,
  `matricula` int(11) DEFAULT NULL,
  `ultimo_login` datetime DEFAULT NULL,
  `online` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `usuario` (`usuario`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `id_paciente` (`id_paciente`),
  UNIQUE KEY `matricula` (`matricula`),
  KEY `id_rol` (`id_rol`),
  KEY `idx_usuario_usuario` (`usuario`),
  KEY `idx_usuario_email` (`email`),
  CONSTRAINT `usuario_ibfk_medico` FOREIGN KEY (`matricula`) REFERENCES `medico` (`matricula`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `usuario_ibfk_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `usuario_ibfk_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1093 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_agenda_hoy` AS SELECT
 1 AS `id_turno`,
  1 AS `fecha`,
  1 AS `hora_inicio`,
  1 AS `estado`,
  1 AS `observacion`,
  1 AS `id_paciente`,
  1 AS `matricula`,
  1 AS `id_especialidad`,
  1 AS `id_estado`,
  1 AS `nro_afiliado`,
  1 AS `paciente`,
  1 AS `paciente_dni`,
  1 AS `medico`,
  1 AS `especialidad`,
  1 AS `consultorio`,
  1 AS `obra_social`,
  1 AS `plan`,
  1 AS `id_pago`,
  1 AS `estado_pago`,
  1 AS `monto_total`,
  1 AS `metodo_pago`,
  1 AS `pago_vence` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_pagos_detalle` AS SELECT
 1 AS `id_pago`,
  1 AS `id_turno`,
  1 AS `monto_base`,
  1 AS `porcentaje_descuento`,
  1 AS `monto_total`,
  1 AS `estado`,
  1 AS `metodo`,
  1 AS `fecha_vencimiento`,
  1 AS `fecha_pago`,
  1 AS `fecha_creacion`,
  1 AS `referencia`,
  1 AS `fecha`,
  1 AS `hora_inicio`,
  1 AS `id_paciente`,
  1 AS `matricula`,
  1 AS `paciente`,
  1 AS `paciente_dni`,
  1 AS `medico`,
  1 AS `especialidad`,
  1 AS `obra_social` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_pagos_pendientes` AS SELECT
 1 AS `id_pago`,
  1 AS `id_turno`,
  1 AS `fecha`,
  1 AS `hora_inicio`,
  1 AS `paciente`,
  1 AS `medico`,
  1 AS `monto_total`,
  1 AS `fecha_vencimiento` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_recaudacion_medico` AS SELECT
 1 AS `matricula`,
  1 AS `medico`,
  1 AS `turnos_pagados`,
  1 AS `total_recaudado` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_turnos_detalle` AS SELECT
 1 AS `id_turno`,
  1 AS `fecha`,
  1 AS `hora_inicio`,
  1 AS `estado`,
  1 AS `observacion`,
  1 AS `id_paciente`,
  1 AS `matricula`,
  1 AS `id_especialidad`,
  1 AS `id_estado`,
  1 AS `nro_afiliado`,
  1 AS `paciente`,
  1 AS `paciente_dni`,
  1 AS `medico`,
  1 AS `especialidad`,
  1 AS `consultorio`,
  1 AS `obra_social`,
  1 AS `plan`,
  1 AS `id_pago`,
  1 AS `estado_pago`,
  1 AS `monto_total`,
  1 AS `metodo_pago`,
  1 AS `pago_vence` */;
SET character_set_client = @saved_cs_client;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `CancelarTurno`(IN `p_id_turno` INT, IN `p_observacion` TEXT)
BEGIN
    DECLARE v_estado VARCHAR(30);

    
    SELECT e.descripcion INTO v_estado
    FROM   turno t
    JOIN   estado_turno e ON e.id_estado = t.id_estado
    WHERE  t.id_turno = p_id_turno;

    
    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Turno no encontrado.';
    ELSEIF v_estado IN ('Cancelado', 'Realizado') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se puede cancelar un turno ya finalizado.';
    ELSE
        
        UPDATE turno
        SET id_estado   = (SELECT id_estado FROM estado_turno WHERE descripcion = 'Cancelado'),
            observacion = p_observacion
        WHERE id_turno = p_id_turno;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `ReservarTurno`(
    IN  p_fecha           DATE,
    IN  p_hora_inicio     TIME,
    IN  p_id_paciente     INT,
    IN  p_matricula       INT,
    IN  p_id_especialidad INT,
    IN  p_id_consultorio  INT,
    IN  p_id_plan         INT,
    OUT p_id_turno        INT
)
BEGIN
    
    IF EXISTS (
        SELECT 1
        FROM   turno t
        JOIN   estado_turno e ON e.id_estado = t.id_estado
        WHERE  t.matricula   = p_matricula
          AND  t.fecha       = p_fecha
          AND  t.hora_inicio = p_hora_inicio
          AND  e.descripcion <> 'Cancelado'
    ) THEN
        SET p_id_turno = -1;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El medico ya tiene un turno asignado en ese horario.';

    
    ELSEIF EXISTS (
        SELECT 1
        FROM   turno t
        JOIN   estado_turno e ON e.id_estado = t.id_estado
        WHERE  t.id_consultorio = p_id_consultorio
          AND  t.fecha          = p_fecha
          AND  t.hora_inicio    = p_hora_inicio
          AND  e.descripcion <> 'Cancelado'
    ) THEN
        SET p_id_turno = -1;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El consultorio ya esta ocupado en ese horario.';

    ELSE
        INSERT INTO turno
            (fecha, hora_inicio, id_estado, id_paciente, matricula,
             id_especialidad, id_consultorio, id_plan)
        VALUES
            (p_fecha, p_hora_inicio,
             (SELECT id_estado FROM estado_turno WHERE descripcion = 'Reservado'),
             p_id_paciente, p_matricula,
             p_id_especialidad, p_id_consultorio, p_id_plan);
        SET p_id_turno = LAST_INSERT_ID();
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50001 DROP VIEW IF EXISTS `v_agenda_hoy`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_agenda_hoy` AS select `v_turnos_detalle`.`id_turno` AS `id_turno`,`v_turnos_detalle`.`fecha` AS `fecha`,`v_turnos_detalle`.`hora_inicio` AS `hora_inicio`,`v_turnos_detalle`.`estado` AS `estado`,`v_turnos_detalle`.`observacion` AS `observacion`,`v_turnos_detalle`.`id_paciente` AS `id_paciente`,`v_turnos_detalle`.`matricula` AS `matricula`,`v_turnos_detalle`.`id_especialidad` AS `id_especialidad`,`v_turnos_detalle`.`id_estado` AS `id_estado`,`v_turnos_detalle`.`nro_afiliado` AS `nro_afiliado`,`v_turnos_detalle`.`paciente` AS `paciente`,`v_turnos_detalle`.`paciente_dni` AS `paciente_dni`,`v_turnos_detalle`.`medico` AS `medico`,`v_turnos_detalle`.`especialidad` AS `especialidad`,`v_turnos_detalle`.`consultorio` AS `consultorio`,`v_turnos_detalle`.`obra_social` AS `obra_social`,`v_turnos_detalle`.`plan` AS `plan`,`v_turnos_detalle`.`id_pago` AS `id_pago`,`v_turnos_detalle`.`estado_pago` AS `estado_pago`,`v_turnos_detalle`.`monto_total` AS `monto_total`,`v_turnos_detalle`.`metodo_pago` AS `metodo_pago`,`v_turnos_detalle`.`pago_vence` AS `pago_vence` from `v_turnos_detalle` where `v_turnos_detalle`.`fecha` = curdate() and `v_turnos_detalle`.`estado` <> 'Cancelado' order by `v_turnos_detalle`.`medico`,`v_turnos_detalle`.`hora_inicio` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_pagos_detalle`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_pagos_detalle` AS select `pg`.`id_pago` AS `id_pago`,`pg`.`id_turno` AS `id_turno`,`pg`.`monto_base` AS `monto_base`,`pg`.`porcentaje_descuento` AS `porcentaje_descuento`,`pg`.`monto_total` AS `monto_total`,`pg`.`estado` AS `estado`,`pg`.`metodo` AS `metodo`,`pg`.`fecha_vencimiento` AS `fecha_vencimiento`,`pg`.`fecha_pago` AS `fecha_pago`,`pg`.`fecha_creacion` AS `fecha_creacion`,`pg`.`referencia` AS `referencia`,`t`.`fecha` AS `fecha`,`t`.`hora_inicio` AS `hora_inicio`,`t`.`id_paciente` AS `id_paciente`,`t`.`matricula` AS `matricula`,concat(`p`.`apellido`,', ',`p`.`nombre`) AS `paciente`,`p`.`dni` AS `paciente_dni`,concat(`m`.`apellido`,', ',`m`.`nombre`) AS `medico`,`e`.`nombre` AS `especialidad`,`os`.`nombre` AS `obra_social` from ((((((`pago` `pg` join `turno` `t` on(`t`.`id_turno` = `pg`.`id_turno`)) join `paciente` `p` on(`p`.`id_paciente` = `t`.`id_paciente`)) join `medico` `m` on(`m`.`matricula` = `t`.`matricula`)) join `especialidad` `e` on(`e`.`id_especialidad` = `t`.`id_especialidad`)) join `plan_os` `pl` on(`pl`.`id_plan` = `t`.`id_plan`)) join `obra_social` `os` on(`os`.`id_obra_social` = `pl`.`id_obra_social`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_pagos_pendientes`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_pagos_pendientes` AS select `v_pagos_detalle`.`id_pago` AS `id_pago`,`v_pagos_detalle`.`id_turno` AS `id_turno`,`v_pagos_detalle`.`fecha` AS `fecha`,`v_pagos_detalle`.`hora_inicio` AS `hora_inicio`,`v_pagos_detalle`.`paciente` AS `paciente`,`v_pagos_detalle`.`medico` AS `medico`,`v_pagos_detalle`.`monto_total` AS `monto_total`,`v_pagos_detalle`.`fecha_vencimiento` AS `fecha_vencimiento` from `v_pagos_detalle` where `v_pagos_detalle`.`estado` = 'Pendiente' order by `v_pagos_detalle`.`fecha_vencimiento` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_recaudacion_medico`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_recaudacion_medico` AS select `m`.`matricula` AS `matricula`,concat(`m`.`apellido`,', ',`m`.`nombre`) AS `medico`,count(`pg`.`id_pago`) AS `turnos_pagados`,sum(`pg`.`monto_total`) AS `total_recaudado` from ((`medico` `m` join `turno` `t` on(`t`.`matricula` = `m`.`matricula`)) join `pago` `pg` on(`pg`.`id_turno` = `t`.`id_turno`)) where `pg`.`estado` = 'Pagado' group by `m`.`matricula`,concat(`m`.`apellido`,', ',`m`.`nombre`) order by sum(`pg`.`monto_total`) desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_turnos_detalle`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_turnos_detalle` AS select `t`.`id_turno` AS `id_turno`,`t`.`fecha` AS `fecha`,`t`.`hora_inicio` AS `hora_inicio`,`et`.`descripcion` AS `estado`,`t`.`observacion` AS `observacion`,`t`.`id_paciente` AS `id_paciente`,`t`.`matricula` AS `matricula`,`t`.`id_especialidad` AS `id_especialidad`,`t`.`id_estado` AS `id_estado`,`pp`.`nro_afiliado` AS `nro_afiliado`,concat(`p`.`apellido`,', ',`p`.`nombre`) AS `paciente`,`p`.`dni` AS `paciente_dni`,concat(`m`.`apellido`,', ',`m`.`nombre`) AS `medico`,`e`.`nombre` AS `especialidad`,concat('Cons. ',`c`.`numero`,' - Piso ',`c`.`piso`) AS `consultorio`,`os`.`nombre` AS `obra_social`,`pl`.`nombre_plan` AS `plan`,`pg`.`id_pago` AS `id_pago`,`pg`.`estado` AS `estado_pago`,`pg`.`monto_total` AS `monto_total`,`pg`.`metodo` AS `metodo_pago`,`pg`.`fecha_vencimiento` AS `pago_vence` from (((((((((`turno` `t` join `estado_turno` `et` on(`et`.`id_estado` = `t`.`id_estado`)) join `paciente` `p` on(`p`.`id_paciente` = `t`.`id_paciente`)) join `medico` `m` on(`m`.`matricula` = `t`.`matricula`)) join `especialidad` `e` on(`e`.`id_especialidad` = `t`.`id_especialidad`)) join `consultorio` `c` on(`c`.`id_consultorio` = `t`.`id_consultorio`)) join `plan_os` `pl` on(`pl`.`id_plan` = `t`.`id_plan`)) join `obra_social` `os` on(`os`.`id_obra_social` = `pl`.`id_obra_social`)) left join `paciente_plan` `pp` on(`pp`.`id_paciente` = `t`.`id_paciente` and `pp`.`id_plan` = `t`.`id_plan`)) left join `pago` `pg` on(`pg`.`id_turno` = `t`.`id_turno`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- ══════════════════════════════════════════════════════════
-- CATÁLOGOS
-- ══════════════════════════════════════════════════════════
-- Configuración real de la clínica: especialidades, coberturas,
-- consultorios, profesionales y sus horarios. No son datos de
-- ninguna persona que se haya atendido.

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

LOCK TABLES `rol` WRITE;
/*!40000 ALTER TABLE `rol` DISABLE KEYS */;
INSERT INTO `rol` (`id_rol`, `nombre`, `descripcion`) VALUES (1,'admin','Administrador del sistema, acceso total'),(2,'recepcionista','Gestiona turnos y pacientes desde el panel'),(3,'paciente','Paciente con acceso al portal web de turnos'),(4,'medico','Médico con acceso a su agenda e historial de pacientes');
/*!40000 ALTER TABLE `rol` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `permiso` WRITE;
/*!40000 ALTER TABLE `permiso` DISABLE KEYS */;
INSERT INTO `permiso` (`id_permiso`, `nombre`, `descripcion`) VALUES (1,'turnos.ver_todos','Ver todos los turnos del sistema'),(2,'turnos.ver_propios','Ver solo los turnos propios'),(3,'turnos.crear','Crear y asignar turnos'),(4,'turnos.editar','Modificar datos de un turno existente'),(5,'turnos.cancelar_cualquiera','Cancelar cualquier turno'),(6,'turnos.cancelar_propio','Cancelar solo los propios turnos'),(7,'pacientes.ver','Ver listado y ficha de pacientes'),(8,'pacientes.crear','Dar de alta un nuevo paciente'),(9,'pacientes.editar','Editar datos de un paciente existente'),(10,'pacientes.eliminar','Dar de baja o eliminar un paciente'),(11,'medicos.ver','Ver listado y ficha de médicos'),(12,'medicos.crear','Dar de alta un nuevo médico'),(13,'medicos.editar','Editar datos de un médico existente'),(14,'historial.ver_paciente','Ver historial completo de consultas de un paciente'),(15,'usuarios.ver','Ver listado de usuarios del sistema'),(16,'usuarios.crear','Crear nuevos usuarios'),(17,'usuarios.editar','Editar usuarios existentes'),(18,'usuarios.eliminar','Dar de baja o eliminar usuarios'),(19,'agenda.ver_propia','Ver la agenda personal del médico'),(20,'reportes.ver','Ver reportes y estadísticas del sistema'),(21,'configuracion.editar','Modificar configuración general del sistema'),(22,'medicos.baja','Dar de baja / reactivar m├®dicos');
/*!40000 ALTER TABLE `permiso` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `rol_permiso` WRITE;
/*!40000 ALTER TABLE `rol_permiso` DISABLE KEYS */;
INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`) VALUES (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),(1,11),(1,12),(1,13),(1,14),(1,15),(1,16),(1,17),(1,18),(1,19),(1,20),(1,21),(1,22),(2,1),(2,3),(2,4),(2,5),(2,7),(2,8),(2,9),(2,11),(2,20),(3,2),(3,3),(3,6),(4,2),(4,7),(4,14),(4,19);
/*!40000 ALTER TABLE `rol_permiso` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `estado_turno` WRITE;
/*!40000 ALTER TABLE `estado_turno` DISABLE KEYS */;
INSERT INTO `estado_turno` (`id_estado`, `descripcion`) VALUES (4,'Ausente'),(5,'Cancelado'),(2,'Confirmado'),(3,'Realizado'),(1,'Reservado');
/*!40000 ALTER TABLE `estado_turno` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `especialidad` WRITE;
/*!40000 ALTER TABLE `especialidad` DISABLE KEYS */;
INSERT INTO `especialidad` (`id_especialidad`, `nombre`, `duracion_turno_min`, `precio_consulta`) VALUES (1,'Clinica Medica',20,5000.00),(2,'Cardiologia',30,9000.00),(3,'Pediatria',20,6000.00),(4,'Dermatologia',20,7000.00),(5,'Traumatologia',30,8500.00),(6,'Ginecologia',30,8000.00),(7,'Neurologia',30,9500.00),(8,'Oftalmologia',20,6500.00);
/*!40000 ALTER TABLE `especialidad` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `obra_social` WRITE;
/*!40000 ALTER TABLE `obra_social` DISABLE KEYS */;
INSERT INTO `obra_social` (`id_obra_social`, `nombre`, `cuit`) VALUES (1,'OSDE',''),(6,'Swiss Medical','30-71234567-8'),(7,'IOMA','30-71345678-9'),(8,'Galeno','30-71456789-0'),(9,'Particular','00-00000000-0');
/*!40000 ALTER TABLE `obra_social` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `plan_os` WRITE;
/*!40000 ALTER TABLE `plan_os` DISABLE KEYS */;
INSERT INTO `plan_os` (`id_plan`, `id_obra_social`, `nombre_plan`, `porcentaje_cobertura`) VALUES (1,1,'Plan 210',70.00),(2,1,'Plan 310',80.00),(3,1,'Plan 410',90.00),(13,8,'Galeno Azul',50.00),(14,8,'Galeno Plata',70.00),(15,8,'Galeno Oro',90.00),(16,7,'IOMA Basico',50.00),(17,7,'IOMA Integral',70.00),(18,7,'IOMA Plus',85.00),(19,6,'SMG02',60.00),(20,6,'SMG30',75.00),(21,6,'SMG70',90.00),(22,9,'Particular Contado',0.00),(23,9,'Particular Reintegro',30.00),(24,9,'Particular Premium',50.00);
/*!40000 ALTER TABLE `plan_os` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `consultorio` WRITE;
/*!40000 ALTER TABLE `consultorio` DISABLE KEYS */;
INSERT INTO `consultorio` (`id_consultorio`, `numero`, `piso`, `descripcion_equipamiento`) VALUES (1,1,1,'Consultorio de Clinica Medica y Oftalmologia. Atienden el Dr. Carlos Fernandez (clinica) y el Dr. Diego Rodriguez (oftalmologia). Equipado con camilla de examen, tensiometro, balanza, negatoscopio, optotipos y lampara de hendidura.'),(2,2,1,'Consultorio de Cardiologia del Dr. Carlos Fernandez. Cuenta con electrocardiografo (ECG), tensiometro, monitor de signos vitales y camilla.'),(3,3,1,'Consultorio de Pediatria de la Dra. Maria Gonzalez. Equipado con camilla pediatrica, balanza para lactantes, pediometro, otoscopio y ambientacion infantil.'),(4,4,2,'Consultorio de Ginecologia de la Dra. Maria Gonzalez. Cuenta con camilla ginecologica con estribos, ecografo, instrumental para PAP y lampara de examen.'),(5,5,2,'Consultorio de Dermatologia del Dr. Roberto Lopez. Equipado con dermatoscopio, lampara de Wood, lampara de examen y equipo de criocirugia.'),(6,6,2,'Consultorio de Traumatologia de la Dra. Valeria Martinez. Cuenta con negatoscopio para radiografias, set de yesos e inmovilizadores, goniometro y camilla.');
/*!40000 ALTER TABLE `consultorio` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `medico` WRITE;
/*!40000 ALTER TABLE `medico` DISABLE KEYS */;
INSERT INTO `medico` (`matricula`, `nombre`, `apellido`, `telefono`, `email`, `estado`, `fecha_baja`) VALUES (1006,'gonza','velasco','2615939921','velasco@gmail.com','inactivo','2026-06-30'),(10001,'Carlos','Fernandez','1155550001','cfernandez@mediturnos.com','activo',NULL),(10002,'Maria','Gonzalez','1155550002','mgonzalez@mediturnos.com','activo',NULL),(10003,'Roberto','Lopez','1155550003','rlopez@mediturnos.com','activo',NULL),(10004,'Valeria','Martinez','1155550004','vmartinez@mediturnos.com','activo',NULL),(10005,'Diego','Rodriguez','1155550005','drodriguez@mediturnos.com','activo',NULL);
/*!40000 ALTER TABLE `medico` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `medico_especialidad` WRITE;
/*!40000 ALTER TABLE `medico_especialidad` DISABLE KEYS */;
INSERT INTO `medico_especialidad` (`matricula`, `id_especialidad`) VALUES (1006,2),(10001,1),(10001,2),(10002,3),(10002,6),(10003,4),(10003,7),(10004,5),(10005,1),(10005,8);
/*!40000 ALTER TABLE `medico_especialidad` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `horario_atencion` WRITE;
/*!40000 ALTER TABLE `horario_atencion` DISABLE KEYS */;
INSERT INTO `horario_atencion` (`id_horario`, `matricula`, `id_especialidad`, `dia_semana`, `hora_inicio`, `hora_fin`, `id_consultorio`) VALUES (1,10001,1,'Lunes','08:00:00','12:00:00',1),(2,10001,1,'Miercoles','08:00:00','12:00:00',1),(3,10001,1,'Viernes','08:00:00','12:00:00',1),(4,10001,2,'Martes','14:00:00','18:00:00',2),(5,10001,2,'Jueves','14:00:00','18:00:00',2),(6,10002,3,'Lunes','08:00:00','13:00:00',3),(7,10002,3,'Martes','08:00:00','13:00:00',3),(8,10002,3,'Miercoles','08:00:00','13:00:00',3),(9,10002,3,'Jueves','08:00:00','13:00:00',3),(10,10002,3,'Viernes','08:00:00','13:00:00',3),(11,10002,6,'Lunes','14:00:00','18:00:00',4),(12,10002,6,'Miercoles','14:00:00','18:00:00',4),(13,10003,4,'Martes','09:00:00','13:00:00',5),(14,10003,4,'Jueves','09:00:00','13:00:00',5),(15,10003,4,'Sabado','09:00:00','12:00:00',5),(16,10004,5,'Lunes','14:00:00','19:00:00',6),(17,10004,5,'Miercoles','14:00:00','19:00:00',6),(18,10004,5,'Viernes','14:00:00','19:00:00',6),(19,10005,8,'Martes','14:00:00','18:00:00',1),(20,10005,8,'Jueves','14:00:00','18:00:00',1),(22,1006,2,'Miercoles','14:02:00','17:40:00',2),(23,10001,2,'Lunes','20:00:00','20:30:00',1),(24,10001,2,'Lunes','21:00:00','21:30:00',1),(25,10001,2,'Lunes','22:00:00','22:30:00',2);
/*!40000 ALTER TABLE `horario_atencion` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `descuento_os_medico` WRITE;
/*!40000 ALTER TABLE `descuento_os_medico` DISABLE KEYS */;
INSERT INTO `descuento_os_medico` (`id_descuento`, `id_obra_social`, `matricula`, `porcentaje_descuento`) VALUES (1,1,10001,80.00),(2,1,10002,50.00),(3,1,10004,60.00),(4,6,10001,70.00),(5,6,10003,90.00),(6,6,10005,40.00),(7,7,10002,100.00),(8,7,10004,50.00),(9,8,10003,60.00),(10,8,10005,75.00),(13,8,10002,30.00);
/*!40000 ALTER TABLE `descuento_os_medico` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- ══════════════════════════════════════════════════════════
-- CUENTAS DE DEMOSTRACIÓN
-- ══════════════════════════════════════════════════════════
-- Tres cuentas, una por rol, con la contraseña: Demo.2026
--
--   demo.admin      → administración: ABM, descuentos, usuarios
--   demo.medico     → la agenda y la ficha clínica del Dr/a. de la
--                     matrícula 10001 (que ya viene en el catálogo)
--   demo.paciente   → el Área del Paciente completa
--
-- ⚠️ La contraseña es pública A PROPÓSITO: es un sitio de demostración y
-- la gente tiene que poder entrar. Eso significa que cualquiera que
-- entre con demo.admin puede borrar y modificar todo. Para una demo
-- abierta es aceptable; si el sitio tiene que resistir, hay que cambiar
-- la contraseña del administrador y publicar sólo las otras dos.
--
-- Los hashes son de password_hash() con PASSWORD_DEFAULT, el mismo que
-- usa el registro del sistema.

-- El médico de la demo es uno que YA está en el catálogo: así tiene
-- horarios de atención, especialidades y descuentos cargados, y la
-- agenda no aparece vacía.
INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, matricula, estado)
SELECT m.nombre, m.apellido, 'demo.medico', 'demo.medico@ejemplo-mediturnos.ar',
       '$2y$10$kqS8eSTaLZpbiNifUlyC4OqiHhByNgyhlhGQfzBidU2fCap8MOBie', 4, m.matricula, 'activo'
FROM   medico m WHERE m.matricula = 10001;

INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, estado)
VALUES ('Demo', 'Administración', 'demo.admin',
        'demo.admin@ejemplo-mediturnos.ar', '$2y$10$kqS8eSTaLZpbiNifUlyC4OqiHhByNgyhlhGQfzBidU2fCap8MOBie', 1, 'activo');

-- El paciente necesita ficha en `paciente` ADEMÁS de cuenta en
-- `usuario`: son dos cosas distintas en este modelo (recepción carga
-- pacientes que nunca se registraron). Sin la ficha, el Área del
-- Paciente avisa que la cuenta no tiene una asociada.
INSERT INTO paciente (nombre, apellido, dni, fecha_nac, sexo, telefono, email, direccion)
VALUES ('Demo', 'Paciente', '30000000', '1990-05-14', 'prefiero_no_decir', '1122334455',
        'demo.paciente@ejemplo-mediturnos.ar', 'Av. Siempreviva 742');
SET @pac = LAST_INSERT_ID();

INSERT INTO usuario (nombre, apellido, usuario, email, contrasenia, id_rol, id_paciente, estado)
VALUES ('Demo', 'Paciente', 'demo.paciente',
        'demo.paciente@ejemplo-mediturnos.ar', '$2y$10$kqS8eSTaLZpbiNifUlyC4OqiHhByNgyhlhGQfzBidU2fCap8MOBie', 3, @pac, 'activo');

-- Cobertura: un plan de obra social de verdad, con su número de
-- afiliado. Los planes "Particular" los puede usar cualquiera sin estar
-- asignados (así está escrito `Turno::planesDePaciente()`), así que
-- asignar uno de esos no mostraría nada: con una cobertura real se ve el
-- descuento aplicado en el resumen de la reserva, que es la mitad de la
-- lógica de precios del sistema.
INSERT INTO paciente_plan (id_paciente, id_plan, nro_afiliado, fecha_alta)
VALUES (@pac, 1, '923017508', CURDATE());


-- ══════════════════════════════════════════════════════════
-- ACTIVIDAD DE LA DEMO
-- ══════════════════════════════════════════════════════════
-- Un turno atendido con su ficha y su receta, y uno próximo confirmado.
-- Sin esto, el paciente entra y ve cinco pantallas vacías: no se puede
-- mostrar el historial, ni las recetas, ni la renovación.
--
-- PARA QUITARLO, una línea (el resto cae por las claves foráneas):
--     DELETE FROM turno WHERE id_paciente =
--         (SELECT id_paciente FROM usuario WHERE usuario = 'demo.paciente');
--     DELETE FROM receta WHERE id_paciente =
--         (SELECT id_paciente FROM usuario WHERE usuario = 'demo.paciente');

-- ── Turno ya atendido, hace tres semanas ─────────────────────
-- La hora es 10:00 y la fecha se calcula desde hoy, así que el archivo
-- no envejece: importado en cualquier momento, el turno queda en el
-- pasado reciente y el próximo en el futuro.
INSERT INTO turno (fecha, hora_inicio, id_estado, id_paciente, matricula,
                   id_especialidad, id_consultorio, id_plan)
VALUES (DATE_SUB(CURDATE(), INTERVAL 21 DAY), '10:00:00', 3,
        @pac, 10001, 1, 1, 1);
SET @t1 = LAST_INSERT_ID();

-- El pago no lo crea ningún trigger: lo crea el flujo de reserva. Un
-- turno insertado a mano se queda sin pago, y entonces el sistema no
-- sabe si está abonado —  viene en NULL y
-- "Mis pagos" aparece vacío.
INSERT INTO pago (id_turno, monto_base, porcentaje_descuento, estado, metodo,
                  fecha_creacion, fecha_vencimiento, fecha_pago,
                  tarjeta_ult4, tarjeta_titular, referencia)
VALUES (@t1, 5000.00, 80.00, 'Pagado', 'Tarjeta',
        DATE_SUB(CURDATE(), INTERVAL 21 DAY),
        DATE_SUB(CURDATE(), INTERVAL 19 DAY),
        DATE_SUB(CURDATE(), INTERVAL 21 DAY),
        '4242', 'DEMO PACIENTE', 'DEMO-0001');

INSERT INTO consulta (id_turno, matricula, motivo_consulta, diagnostico, indicaciones)
VALUES (@t1, 10001, 'Control anual',
        'Paciente en buen estado general. Presión arterial 120/80.',
        'Continuar con actividad física moderada. Control en seis meses.');

INSERT INTO estudio (id_paciente, id_turno, matricula, tipo, nombre, estado)
VALUES (@pac, @t1, 10001, 'Laboratorio', 'Hemograma completo', 'Pendiente');

-- Una receta vigente, para que se pueda probar la renovación.
INSERT INTO receta (id_paciente, matricula, id_turno, diagnostico, indicaciones,
                    emitida_el, vence_el)
VALUES (@pac, 10001, @t1, 'Control anual',
        'Tomar con las comidas. Si aparece malestar, suspender y consultar.',
        DATE_SUB(CURDATE(), INTERVAL 21 DAY),
        DATE_ADD(CURDATE(), INTERVAL 9 DAY));
SET @r1 = LAST_INSERT_ID();

INSERT INTO receta_medicamento (id_receta, nombre, presentacion, dosis, frecuencia, duracion, cantidad)
VALUES (@r1, 'Enalapril', 'comprimidos 10 mg', '1 comprimido', 'cada 12 horas', 'por 30 días', 2),
       (@r1, 'Aspirina',  'comprimidos 100 mg', '1 comprimido', 'por la mañana',  'por 30 días', 1);

-- ── Turno próximo, confirmado ────────────────────────────────
-- A diez días: deja ver la cuenta regresiva, el detalle y la
-- reprogramación sin chocar con el mínimo de dos horas de antelación.
INSERT INTO turno (fecha, hora_inicio, id_estado, id_paciente, matricula,
                   id_especialidad, id_consultorio, id_plan)
VALUES (DATE_ADD(CURDATE(), INTERVAL 10 DAY), '11:00:00', 2,
        @pac, 10001, 1, 1, 1);
SET @t2 = LAST_INSERT_ID();

-- Pendiente y con el vencimiento por delante: así la pantalla de pago se
-- puede abrir y probar. Con el plazo ya vencido, la primera visita al
-- panel lo cancelaría solo ( corre en cada
-- carga), y la demo se quedaría sin turno próximo.
INSERT INTO pago (id_turno, monto_base, porcentaje_descuento, estado,
                  fecha_creacion, fecha_vencimiento)
VALUES (@t2, 5000.00, 80.00, 'Pendiente',
        NOW(), DATE_ADD(NOW(), INTERVAL 48 HOUR));

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- VERIFICACIÓN después de importar
-- =============================================================
--   SELECT COUNT(*) FROM especialidad;   -- 8
--   SELECT COUNT(*) FROM medico;         -- 6
--   SELECT COUNT(*) FROM horario_atencion; -- 24
--   SELECT usuario, estado FROM usuario; -- las 3 cuentas demo
--   SELECT COUNT(*) FROM paciente;       -- 1
--
-- Y probar entrar con demo.paciente: si el login falla, el hash no
-- coincide (archivo generado con otra contraseña) o falta la tabla de
-- permisos.
-- =============================================================
