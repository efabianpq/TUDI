-- MySQL dump 10.13  Distrib 8.0.30, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: tudeficit
-- ------------------------------------------------------
-- Server version	8.0.30

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `actividades_fisicas`
--

DROP TABLE IF EXISTS `actividades_fisicas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `actividades_fisicas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `registro_diario_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duracion_min` int unsigned NOT NULL,
  `pasos` int unsigned DEFAULT NULL,
  `calorias_dispositivo` decimal(6,2) DEFAULT NULL,
  `factor_correccion` decimal(3,2) NOT NULL DEFAULT '0.85',
  `calorias_ajustadas` decimal(6,2) DEFAULT NULL,
  `fuente` enum('manual','dispositivo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `actividades_fisicas_registro_diario_id_foreign` (`registro_diario_id`),
  CONSTRAINT `actividades_fisicas_registro_diario_id_foreign` FOREIGN KEY (`registro_diario_id`) REFERENCES `registros_diarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `actividades_fisicas`
--

LOCK TABLES `actividades_fisicas` WRITE;
/*!40000 ALTER TABLE `actividades_fisicas` DISABLE KEYS */;
/*!40000 ALTER TABLE `actividades_fisicas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comidas_reales`
--

DROP TABLE IF EXISTS `comidas_reales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comidas_reales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plan_comida_id` bigint unsigned NOT NULL,
  `calorias_reales` decimal(7,2) NOT NULL,
  `proteina_g` decimal(6,2) NOT NULL,
  `grasa_g` decimal(6,2) NOT NULL,
  `carbohidratos_g` decimal(6,2) NOT NULL,
  `consumido_en` datetime DEFAULT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `imagen_evidencia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comidas_reales_plan_comida_id_unique` (`plan_comida_id`),
  CONSTRAINT `comidas_reales_plan_comida_id_foreign` FOREIGN KEY (`plan_comida_id`) REFERENCES `planes_comida` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comidas_reales`
--

LOCK TABLES `comidas_reales` WRITE;
/*!40000 ALTER TABLE `comidas_reales` DISABLE KEYS */;
/*!40000 ALTER TABLE `comidas_reales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredientes_disponibles`
--

DROP TABLE IF EXISTS `ingredientes_disponibles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingredientes_disponibles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `registro_diario_id` bigint unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cantidad_g` decimal(7,2) NOT NULL,
  `calorias_por_100g` decimal(6,2) NOT NULL,
  `proteina_por_100g` decimal(5,2) NOT NULL,
  `grasa_por_100g` decimal(5,2) NOT NULL,
  `carbohidratos_por_100g` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ingredientes_disponibles_registro_diario_id_foreign` (`registro_diario_id`),
  CONSTRAINT `ingredientes_disponibles_registro_diario_id_foreign` FOREIGN KEY (`registro_diario_id`) REFERENCES `registros_diarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredientes_disponibles`
--

LOCK TABLES `ingredientes_disponibles` WRITE;
/*!40000 ALTER TABLE `ingredientes_disponibles` DISABLE KEYS */;
/*!40000 ALTER TABLE `ingredientes_disponibles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `metricas_tendencia`
--

DROP TABLE IF EXISTS `metricas_tendencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `metricas_tendencia` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint unsigned NOT NULL,
  `fecha` date NOT NULL,
  `promedio_movil_peso_kg` decimal(5,2) DEFAULT NULL,
  `promedio_movil_calorias` decimal(7,2) DEFAULT NULL,
  `promedio_movil_deficit_kcal` decimal(7,2) DEFAULT NULL,
  `indice_consistencia_pct` decimal(5,2) DEFAULT NULL,
  `dias_con_datos` tinyint unsigned NOT NULL DEFAULT '0',
  `porcentaje_perdida_semanal` decimal(5,2) DEFAULT NULL,
  `tendencia` enum('perdida_lenta','perdida_adecuada','perdida_rapida','estable','ganancia') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `metricas_tendencia_usuario_id_fecha_unique` (`usuario_id`,`fecha`),
  CONSTRAINT `metricas_tendencia_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `metricas_tendencia`
--

LOCK TABLES `metricas_tendencia` WRITE;
/*!40000 ALTER TABLE `metricas_tendencia` DISABLE KEYS */;
INSERT INTO `metricas_tendencia` VALUES (1,1,'2026-09-06',NULL,0.00,2376.00,0.00,1,NULL,NULL,'2026-09-06 05:02:46','2026-09-06 06:42:35'),(2,1,'2026-09-07',NULL,0.00,2376.00,0.00,1,NULL,NULL,'2026-09-07 20:10:01','2026-09-07 20:10:01');
/*!40000 ALTER TABLE `metricas_tendencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_09_04_214013_add_perfil_nutricional_a_users_table',1),(5,'2026_09_04_214014_create_registros_diarios_table',1),(6,'2026_09_04_214015_create_ingredientes_disponibles_table',1),(7,'2026_09_04_214016_create_planes_comida_table',1),(8,'2026_09_04_214017_create_comidas_reales_table',1),(9,'2026_09_04_214018_create_actividades_fisicas_table',1),(10,'2026_09_04_214019_create_metricas_tendencia_table',1),(11,'2026_09_04_214020_create_recomendaciones_sistema_table',1),(12,'2026_09_04_220046_add_ingredientes_detalle_a_planes_comida_table',2),(13,'2026_09_05_014623_add_imagen_evidencia_a_comidas_reales_table',2),(14,'2026_09_05_015055_add_pasos_y_fuente_a_actividades_fisicas_table',2),(15,'2026_09_05_022000_add_cierre_a_registros_diarios_table',2),(16,'2026_09_05_030000_add_peso_kg_a_registros_diarios_table',2),(17,'2026_09_05_030100_add_analitica_a_metricas_tendencia_table',2),(18,'2026_09_05_120000_add_ingredientes_texto_a_registros_diarios_table',3),(19,'2026_09_05_120100_add_preparacion_y_notas_a_planes_comida_table',3);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `planes_comida`
--

DROP TABLE IF EXISTS `planes_comida`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planes_comida` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `registro_diario_id` bigint unsigned NOT NULL,
  `tipo_comida` enum('desayuno','almuerzo','cena','snack') COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `preparacion` text COLLATE utf8mb4_unicode_ci,
  `notas_ia` text COLLATE utf8mb4_unicode_ci,
  `ingredientes_detalle` json DEFAULT NULL,
  `calorias_estimadas` decimal(7,2) NOT NULL,
  `proteina_g` decimal(6,2) NOT NULL,
  `grasa_g` decimal(6,2) NOT NULL,
  `carbohidratos_g` decimal(6,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `planes_comida_registro_diario_id_foreign` (`registro_diario_id`),
  CONSTRAINT `planes_comida_registro_diario_id_foreign` FOREIGN KEY (`registro_diario_id`) REFERENCES `registros_diarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `planes_comida`
--

LOCK TABLES `planes_comida` WRITE;
/*!40000 ALTER TABLE `planes_comida` DISABLE KEYS */;
/*!40000 ALTER TABLE `planes_comida` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recomendaciones_sistema`
--

DROP TABLE IF EXISTS `recomendaciones_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recomendaciones_sistema` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `registro_diario_id` bigint unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ajuste_calorico',
  `calorias_objetivo_sugeridas` decimal(7,2) DEFAULT NULL,
  `justificacion` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('pendiente','confirmada','rechazada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `confirmada_en` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recomendaciones_sistema_registro_diario_id_foreign` (`registro_diario_id`),
  CONSTRAINT `recomendaciones_sistema_registro_diario_id_foreign` FOREIGN KEY (`registro_diario_id`) REFERENCES `registros_diarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recomendaciones_sistema`
--

LOCK TABLES `recomendaciones_sistema` WRITE;
/*!40000 ALTER TABLE `recomendaciones_sistema` DISABLE KEYS */;
/*!40000 ALTER TABLE `recomendaciones_sistema` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `registros_diarios`
--

DROP TABLE IF EXISTS `registros_diarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registros_diarios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint unsigned NOT NULL,
  `fecha` date NOT NULL,
  `peso_kg` decimal(5,2) DEFAULT NULL,
  `ingredientes_desayuno` text COLLATE utf8mb4_unicode_ci,
  `ingredientes_almuerzo` text COLLATE utf8mb4_unicode_ci,
  `ingredientes_cena` text COLLATE utf8mb4_unicode_ci,
  `calorias_objetivo_dia` decimal(7,2) DEFAULT NULL,
  `calorias_consumidas` decimal(7,2) DEFAULT NULL,
  `calorias_actividad_ajustada` decimal(7,2) DEFAULT NULL,
  `deficit_diario` decimal(7,2) DEFAULT NULL,
  `proteina_objetivo_g` decimal(6,2) DEFAULT NULL,
  `proteina_consumida_g` decimal(6,2) DEFAULT NULL,
  `cerrado` tinyint(1) NOT NULL DEFAULT '0',
  `cerrado_en` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registros_diarios_usuario_id_fecha_unique` (`usuario_id`,`fecha`),
  CONSTRAINT `registros_diarios_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `registros_diarios`
--

LOCK TABLES `registros_diarios` WRITE;
/*!40000 ALTER TABLE `registros_diarios` DISABLE KEYS */;
INSERT INTO `registros_diarios` VALUES (1,1,'2026-09-06',NULL,'para el desayuno tengo tres huevos tinto y unas tostadas con mantequilla natural',NULL,NULL,2376.00,0.00,0.00,2376.00,180.00,0.00,0,NULL,'2026-09-06 06:01:40','2026-09-06 06:42:19');
/*!40000 ALTER TABLE `registros_diarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('2qjkKt5DynSJ8dMuGT0gnDDdfYq9KYLpwJIUyuqj',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJjU1VZNzROUkk1cmJ2VnRaRnVTWHdVY0V5MWxPYXhlRWEzRklUOTZRIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788650278),('2RrimDPOPE2OP9YovYSqosNv9rptNPhzF1Yk2hFS',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJDTUNQY3IyampqbVFKUmszbHkxVHZtTmQ4cVBVZHlycUF4TTJMNlZ2IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9yZWdpc3RlciIsInJvdXRlIjoicmVnaXN0ZXIifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788650299),('3l2IBCc5EB704qT5py9DaJ91TPborrU7iF0sEmU3',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJxV2V2d1o5akZWUVpVTU1BcDRjbWh2c1NZUktVWjIyamJNQ1RIQm55IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788700936),('5lcEQGZmztGT0xaH05Yg3GTTUT1RQuwaCa8A1oys',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiI0eENPcEs2WnlZSVk5RWRRU1pjM2h5VmFER0tQSXBoMjJ1UER2MGJpIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788650278),('7y6bblg8Gzq1De5XYJ5FMqeu2UxrTC9ySjeWZvOt',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJWaHVDVk5tSXlvakhlVzFncXd4b3NUZ3AxdjFZYkdiVThmN2JSUktBIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJfcHJldmlvdXMiOnsidXJsIjoiaHR0cDpcL1wvMTI3LjAuMC4xOjgwMDBcL3BsYW5lc1wvMSIsInJvdXRlIjoicGxhbmVzLnNob3cifSwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjF9',1788658993),('aCqMfe4cLEeDUOwRKNqmKnYq8B5FjK7T11daXJ1U',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJtT3pPVVlFQ1B3Y2xBdzhabXVubkF1VjRFMnJLQjRwbnR6N29IQWtVIiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvMTI3LjAuMC4xOjgxMjNcL3BsYW5lcyJ9LCJfcHJldmlvdXMiOnsidXJsIjoiaHR0cDpcL1wvMTI3LjAuMC4xOjgxMjNcL3BsYW5lcyIsInJvdXRlIjoicGxhbmVzLmluZGV4In0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1788696668),('avGW9cm8FxKSoIGvuPp0eHxgZlpxI0Wkmcbz7UV8',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJBRldVZzIwb0FuNU1sMkpGSHNKN2tNUjF0QjQ0MUZhdHl2Unh5OTc1IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MTIzXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788696668),('b5z6IVntcMdOF54wjqy8b5vG1bEzYxvTVHChgt8e',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJSY3NDNUJ2bWFNUzBMSzdNZVE5aFByM05NRGVtamhxeUtFRERDS0RJIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788650144),('ekl0DJAejGAMXOrrcjLsa5ydomXg4jAt4zdkQLaO',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJ5SHdNcndRakh0anVMb3J0dXA1R2ltc3J1N1kyeUplbFpZYlNiSU1OIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788650481),('GcnqpVdOrue4rxXvBQ2XdUeVO7Pg970Ia2kDCIO8',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJtUEhXSndXUno3enhGYUJKZkg2OFVRQ1B2Z0NoVmt6TkkwWjc0YjJpIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788650514),('nbuCextsyi9Bm2RJdZRsA9tbgrj9f7DbtN9pSRaw',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJTdzV4bVJRalRoWURhbmRtZTVpd3FITlRnbXF3NExtNm16Wm9hT3ZDIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788793788),('rG7mSAKpQBhT2XOksO4ukcASC0SPwWioysqPc4Ww',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJpdm8wU2JNQlRmTm1EeHk3ZHhIeXYxTmJQRVpSb1ZKUXhlbU1MVzl1IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788652787),('rgKSxfCau5nrRBG19mMucdzkZCCUAEUNl7EKKsfO',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJoWmF1bzlqVGNDVm9oeFRkc1RlZVdMQm5MdExwUU5BeXNRYmthaktOIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9yZWdpc3RlciIsInJvdXRlIjoicmVnaXN0ZXIifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788650164),('ryIugOJOwfB8IAxFkTqWBmcsLjbRS4ck7hdIgPyn',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJIVVVaT0tYUTNkbUV1aG9vNERpN0VzcUxFU2ZTb0prNGFJRlBwcERFIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788650165),('Tp8u1FKpf7f6tk1rYCmmwInBEnbx4o7ooD8Zd1gp',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJUNUlxQzJJRmxVVDhHTFFtMTVIYmx5dmNMTmFXTVIzOWlkVUVpcEFCIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788714366),('tWnBVVO5QI7NHO9OjXe65YoleCIRbqiEYVgZlSIi',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJTU0FIRzE5Y1ZkcFo1aWhMNjh1UHZzYlVXUGtuUFRFc0M1a2FUdUJ1IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788652907),('v7irlPAbQf7tkzRFH3TdCFNSN4wJDwZVPeMQG9sW',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiJwZ0h6TTMxVFQ1bWk4OVhqOTc1YmlaSFZkTURFb1FuZmtDcWlOQlBGIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MTIzXC9sb2dpbiIsInJvdXRlIjoibG9naW4ifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788696798),('vmiC1IZtRnllNo21S0SEUV6sqn3RZqXIcHvIQj41',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJ0ZlRkRnJxRHk0eGlZbU42elJWZXJxcHY3ZVlJdjVNallMYTAySFpRIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9kYXNoYm9hcmQiLCJyb3V0ZSI6ImRhc2hib2FyZCJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX0sImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjoxfQ==',1788793815),('wPJclt3geD6fpAB8f6QelQMOheBT1ekQsNmV4edT',NULL,'127.0.0.1','curl/8.19.0','eyJfdG9rZW4iOiI5QUVEMjBDSW5CeGhPeVJ1TjBSNjRaZjBuemhoejZtQ0VRbmN1UVhyIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MTIzIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1788696667);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peso_kg` decimal(5,2) DEFAULT NULL,
  `estatura_m` decimal(3,2) DEFAULT NULL,
  `edad` tinyint unsigned DEFAULT NULL,
  `sexo` enum('masculino','femenino') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nivel_actividad` decimal(4,3) DEFAULT NULL,
  `tipo_deficit` enum('porcentaje','fijo') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor_deficit` decimal(6,2) DEFAULT NULL,
  `proteina_factor` decimal(3,2) DEFAULT NULL,
  `grasa_factor` decimal(3,2) DEFAULT NULL,
  `calorias_objetivo` decimal(7,2) DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Ana Torres','ana.torres@tudi-qa.test','2026-09-06 21:59:10','$2y$12$jsnhktg8u8xOpEaMvxa8tu3BRD/8x7HIp9VFiTzOuc.Lf7rES/Fzi',90.00,1.72,33,'masculino',1.500,'porcentaje',0.20,2.00,0.80,2376.00,'5H0dH3PKYU50pBVWoKVqVSGQSagqjBcSR40c11QDSY6KzqUZrzsfi9iEBWRo','2026-09-06 04:22:34','2026-09-06 22:08:26');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'tudeficit'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-07 10:29:17
