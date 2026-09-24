/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attribute_changes` json DEFAULT NULL,
  `properties` json DEFAULT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`),
  KEY `activity_log_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brands` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brands_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `breezy_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `breezy_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `authenticatable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `authenticatable_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `panel_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guard` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `expires_at` timestamp NULL DEFAULT NULL,
  `two_factor_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `breezy_sessions_authenticatable_type_authenticatable_id_index` (`authenticatable_type`,`authenticatable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `categories_tenant_id_index` (`tenant_id`),
  KEY `categories_parent_id_foreign` (`parent_id`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `categories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `billing_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `emails` json DEFAULT NULL,
  `phones` json DEFAULT NULL,
  `tax_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sdi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pec` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_checked_at` timestamp NULL DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'app',
  `consent_privacy_at` timestamp NULL DEFAULT NULL,
  `consent_marketing_at` timestamp NULL DEFAULT NULL,
  `consent_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestionale_code` int unsigned DEFAULT NULL,
  `eureka_note` text COLLATE utf8mb4_unicode_ci,
  `approved_for_gestionale_at` timestamp NULL DEFAULT NULL,
  `sent_to_gestionale_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `gestionale_review_flagged_at` timestamp NULL DEFAULT NULL,
  `gestionale_review_note` text COLLATE utf8mb4_unicode_ci,
  `gestionale_suggested_code` int unsigned DEFAULT NULL,
  `gestionale_suggested_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_gestionale_code_unique` (`gestionale_code`),
  KEY `customers_tenant_id_index` (`tenant_id`),
  KEY `customers_source_index` (`source`),
  KEY `customers_billing_customer_id_foreign` (`billing_customer_id`),
  CONSTRAINT `customers_billing_customer_id_foreign` FOREIGN KEY (`billing_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deadlines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deadlines` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deadlinable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deadlinable_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('assicurazione','bollo','revisione','polizza_rct','manutenzione_ordinaria','licenza','contratto','altro') COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `due_date` date NOT NULL,
  `reminder_days_before` int unsigned NOT NULL DEFAULT '30',
  `status` enum('attiva','scaduta','rinnovata') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'attiva',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deadlines_deadlinable_type_deadlinable_id_index` (`deadlinable_type`,`deadlinable_id`),
  KEY `deadlines_tenant_id_due_date_index` (`tenant_id`,`due_date`),
  KEY `deadlines_type_index` (`type`),
  CONSTRAINT `deadlines_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `esecuzioni_eureka`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `esecuzioni_eureka` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `comando` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avviata_il` timestamp NOT NULL,
  `finita_il` timestamp NULL DEFAULT NULL,
  `esito` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_corso',
  `riepilogo` json DEFAULT NULL,
  `errore` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `esecuzioni_eureka_comando_avviata_il_index` (`comando`,`avviata_il`),
  KEY `esecuzioni_eureka_comando_index` (`comando`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eureka_cashflow_mesi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eureka_cashflow_mesi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno` smallint unsigned NOT NULL,
  `mese` tinyint unsigned NOT NULL,
  `entrate` decimal(14,2) NOT NULL DEFAULT '0.00',
  `uscite` decimal(14,2) NOT NULL DEFAULT '0.00',
  `entrate_ftc` decimal(14,2) NOT NULL DEFAULT '0.00',
  `entrate_oc` decimal(14,2) NOT NULL DEFAULT '0.00',
  `entrate_bc` decimal(14,2) NOT NULL DEFAULT '0.00',
  `uscite_ftf` decimal(14,2) NOT NULL DEFAULT '0.00',
  `uscite_of` decimal(14,2) NOT NULL DEFAULT '0.00',
  `uscite_bf` decimal(14,2) NOT NULL DEFAULT '0.00',
  `saldo_mese` decimal(14,2) NOT NULL DEFAULT '0.00',
  `saldo_progressivo` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eureka_cashflow_mesi_tenant_id_anno_mese_unique` (`tenant_id`,`anno`,`mese`),
  CONSTRAINT `eureka_cashflow_mesi_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eureka_cashflow_voci`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eureka_cashflow_voci` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno` smallint unsigned NOT NULL,
  `mese` tinyint unsigned NOT NULL,
  `data_documento` date DEFAULT NULL,
  `data_scadenza` date DEFAULT NULL,
  `numero` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descrizione` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `importo_totale` decimal(14,2) NOT NULL DEFAULT '0.00',
  `importo` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `eureka_cashflow_voci_tenant_id_anno_mese_index` (`tenant_id`,`anno`,`mese`),
  CONSTRAINT `eureka_cashflow_voci_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eureka_fatturato_mesi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eureka_fatturato_mesi` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anno` smallint unsigned NOT NULL,
  `mese` tinyint unsigned NOT NULL,
  `dare` decimal(14,2) NOT NULL DEFAULT '0.00',
  `avere` decimal(14,2) NOT NULL DEFAULT '0.00',
  `netto` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eureka_fatturato_mesi_tenant_id_tipo_anno_mese_unique` (`tenant_id`,`tipo`,`anno`,`mese`),
  CONSTRAINT `eureka_fatturato_mesi_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eureka_fatture`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eureka_fatture` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_eureka` int unsigned NOT NULL COMMENT 'id del documento in contabilita',
  `gestionale_code` int unsigned DEFAULT NULL,
  `customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ragione_sociale` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `partita_iva` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_doc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_doc` date DEFAULT NULL,
  `totale_doc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `imponibile` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pagamento` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `e_acconto` tinyint(1) NOT NULL DEFAULT '0',
  `detrae_acconto_numero` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detrazione_ambigua` tinyint(1) NOT NULL DEFAULT '0',
  `id_b10_origine` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eureka_fatture_tenant_id_tipo_id_eureka_unique` (`tenant_id`,`tipo`,`id_eureka`),
  KEY `eureka_fatture_tenant_id_tipo_data_doc_index` (`tenant_id`,`tipo`,`data_doc`),
  KEY `eureka_fatture_customer_id_index` (`customer_id`),
  CONSTRAINT `eureka_fatture_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `eureka_fatture_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eureka_partite_aperte`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eureka_partite_aperte` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gestionale_code` int unsigned NOT NULL,
  `customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ragione_sociale` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anno` smallint unsigned NOT NULL,
  `numero_fattura` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_fattura` date DEFAULT NULL,
  `data_scadenza` date DEFAULT NULL,
  `tipo_pagamento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saldo` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `eureka_partite_aperte_tenant_id_tipo_index` (`tenant_id`,`tipo`),
  KEY `eureka_partite_aperte_tenant_id_data_scadenza_index` (`tenant_id`,`data_scadenza`),
  KEY `eureka_partite_aperte_customer_id_index` (`customer_id`),
  CONSTRAINT `eureka_partite_aperte_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `eureka_partite_aperte_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eureka_saldi_anagrafiche`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eureka_saldi_anagrafiche` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gestionale_code` int unsigned NOT NULL,
  `ragione_sociale` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saldo` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eureka_saldi_anagrafiche_tenant_id_tipo_gestionale_code_unique` (`tenant_id`,`tipo`,`gestionale_code`),
  CONSTRAINT `eureka_saldi_anagrafiche_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `information_request_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `information_request_notes` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `information_request_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logged_at` date NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `information_request_notes_tenant_id_foreign` (`tenant_id`),
  KEY `information_request_notes_information_request_id_index` (`information_request_id`),
  CONSTRAINT `information_request_notes_information_request_id_foreign` FOREIGN KEY (`information_request_id`) REFERENCES `information_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `information_request_notes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `information_request_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `information_request_product` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `information_request_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `info_request_product_unique` (`information_request_id`,`product_id`),
  KEY `information_request_product_product_id_foreign` (`product_id`),
  CONSTRAINT `information_request_product_information_request_id_foreign` FOREIGN KEY (`information_request_id`) REFERENCES `information_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `information_request_product_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `information_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `information_requests` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'nuova',
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'crm',
  `origin_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `appointment_at` datetime DEFAULT NULL,
  `appointment_notes` text COLLATE utf8mb4_unicode_ci,
  `handled_by_user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `information_requests_tenant_id_number_unique` (`tenant_id`,`number`),
  UNIQUE KEY `information_requests_tenant_id_external_id_unique` (`tenant_id`,`external_id`),
  KEY `information_requests_handled_by_user_id_foreign` (`handled_by_user_id`),
  KEY `information_requests_customer_id_index` (`customer_id`),
  CONSTRAINT `information_requests_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `information_requests_handled_by_user_id_foreign` FOREIGN KEY (`handled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `information_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lavaggi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lavaggi` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `machine_unit_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_report_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` date NOT NULL,
  `descrizione` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lines_washed` smallint unsigned DEFAULT NULL,
  `filtro_sostituito` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `maintenance_schedule_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lavaggi_service_report_id_maintenance_schedule_id_unique` (`service_report_id`,`maintenance_schedule_id`),
  KEY `lavaggi_customer_id_foreign` (`customer_id`),
  KEY `lavaggi_machine_unit_id_foreign` (`machine_unit_id`),
  KEY `lavaggi_tenant_id_customer_id_index` (`tenant_id`,`customer_id`),
  KEY `lavaggi_data_index` (`data`),
  KEY `lavaggi_maintenance_schedule_id_foreign` (`maintenance_schedule_id`),
  CONSTRAINT `lavaggi_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lavaggi_machine_unit_id_foreign` FOREIGN KEY (`machine_unit_id`) REFERENCES `machine_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lavaggi_maintenance_schedule_id_foreign` FOREIGN KEY (`maintenance_schedule_id`) REFERENCES `maintenance_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lavaggi_service_report_id_foreign` FOREIGN KEY (`service_report_id`) REFERENCES `service_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lavaggi_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_requests` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('ferie','permesso','malattia') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `time_from` time DEFAULT NULL,
  `time_to` time DEFAULT NULL,
  `hours` decimal(5,2) DEFAULT NULL,
  `status` enum('richiesto','approvato','rifiutato') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'richiesto',
  `requested_at` datetime DEFAULT NULL,
  `approved_by_user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_requests_user_id_foreign` (`user_id`),
  KEY `leave_requests_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `leave_requests_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  KEY `leave_requests_status_index` (`status`),
  CONSTRAINT `leave_requests_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_requests_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `machine_unit_placements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `machine_unit_placements` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `machine_unit_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eureka_billing_customer_code` int unsigned DEFAULT NULL,
  `placed_at` datetime NOT NULL,
  `removed_at` datetime DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `machine_unit_placements_tenant_id_foreign` (`tenant_id`),
  KEY `machine_unit_placements_customer_id_foreign` (`customer_id`),
  KEY `machine_unit_placements_machine_unit_id_placed_at_index` (`machine_unit_id`,`placed_at`),
  KEY `machine_unit_placements_billing_customer_id_foreign` (`billing_customer_id`),
  CONSTRAINT `machine_unit_placements_billing_customer_id_foreign` FOREIGN KEY (`billing_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_unit_placements_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_unit_placements_machine_unit_id_foreign` FOREIGN KEY (`machine_unit_id`) REFERENCES `machine_units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `machine_unit_placements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `machine_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `machine_units` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuale',
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fusione_suggerita_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fusione_suggerita_motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fusa_in_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spostamento_suggerito_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spostamento_suggerito_il` date DEFAULT NULL,
  `spostamento_suggerito_motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spostamento_suggerito_pagante_code` int unsigned DEFAULT NULL,
  `spostamento_scartato` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintenance_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('in_magazzino','installata','rimossa') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_magazzino',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `gestionale_code` int unsigned DEFAULT NULL COMMENT 'id m14 (matricola) su Eureka',
  `gestionale_suggested_code` int unsigned DEFAULT NULL,
  `gestionale_suggested_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eureka_billing_customer_code` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `machine_units_tenant_id_serial_number_unique` (`tenant_id`,`serial_number`),
  KEY `machine_units_product_id_foreign` (`product_id`),
  KEY `machine_units_current_customer_id_index` (`current_customer_id`),
  KEY `machine_units_billing_customer_id_index` (`billing_customer_id`),
  KEY `machine_units_material_id_foreign` (`material_id`),
  KEY `machine_units_fusione_suggerita_id_foreign` (`fusione_suggerita_id`),
  KEY `machine_units_spostamento_suggerito_customer_id_foreign` (`spostamento_suggerito_customer_id`),
  KEY `machine_units_fusa_in_id_foreign` (`fusa_in_id`),
  CONSTRAINT `machine_units_billing_customer_id_foreign` FOREIGN KEY (`billing_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_current_customer_id_foreign` FOREIGN KEY (`current_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_fusa_in_id_foreign` FOREIGN KEY (`fusa_in_id`) REFERENCES `machine_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_fusione_suggerita_id_foreign` FOREIGN KEY (`fusione_suggerita_id`) REFERENCES `machine_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_spostamento_suggerito_customer_id_foreign` FOREIGN KEY (`spostamento_suggerito_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `machine_units_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_schedules` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manutenzione',
  `beverage_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lines_count` smallint unsigned DEFAULT NULL,
  `status` enum('attivo','chiuso') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'attivo',
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `machine_unit_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frequency` enum('mensile','trimestrale','semestrale','annuale') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frequency_days` smallint unsigned DEFAULT NULL,
  `filter_validity_days` smallint unsigned DEFAULT NULL,
  `last_service_report_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_lavaggio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_filter_change_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_schedules_customer_id_foreign` (`customer_id`),
  KEY `maintenance_schedules_last_service_report_id_foreign` (`last_service_report_id`),
  KEY `maintenance_schedules_tenant_id_next_due_date_index` (`tenant_id`,`next_due_date`),
  KEY `maintenance_schedules_last_lavaggio_id_foreign` (`last_lavaggio_id`),
  KEY `maintenance_schedules_tenant_id_type_next_due_date_index` (`tenant_id`,`type`,`next_due_date`),
  KEY `maintenance_schedules_last_filter_change_id_foreign` (`last_filter_change_id`),
  KEY `maintenance_schedules_machine_unit_id_foreign` (`machine_unit_id`),
  CONSTRAINT `maintenance_schedules_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_schedules_last_filter_change_id_foreign` FOREIGN KEY (`last_filter_change_id`) REFERENCES `lavaggi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_schedules_last_lavaggio_id_foreign` FOREIGN KEY (`last_lavaggio_id`) REFERENCES `lavaggi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_schedules_last_service_report_id_foreign` FOREIGN KEY (`last_service_report_id`) REFERENCES `service_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_schedules_machine_unit_id_foreign` FOREIGN KEY (`machine_unit_id`) REFERENCES `machine_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_schedules_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_order_items` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_order_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_order_items_material_order_id_material_id_unique` (`material_order_id`,`material_id`),
  KEY `material_order_items_material_id_foreign` (`material_id`),
  CONSTRAINT `material_order_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_order_items_material_order_id_foreign` FOREIGN KEY (`material_order_id`) REFERENCES `material_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_orders` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_orders_tenant_id_number_unique` (`tenant_id`,`number`),
  KEY `material_orders_tenant_id_index` (`tenant_id`),
  KEY `material_orders_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `material_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_orders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materials` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuale',
  `supplier_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `gestionale_code` int unsigned DEFAULT NULL,
  `list_price` decimal(10,2) DEFAULT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `maintenance_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variant` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tube_diameter` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tube_diameter_2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thread_size` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thread_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barb_diameter` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `materials_code_unique` (`code`),
  KEY `materials_tenant_id_index` (`tenant_id`),
  KEY `materials_category_index` (`category`),
  KEY `materials_supplier_id_foreign` (`supplier_id`),
  KEY `materials_gestionale_code_index` (`gestionale_code`),
  CONSTRAINT `materials_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `materials_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`tenant_id`,`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_permissions_permission_id_foreign` (`permission_id`),
  KEY `model_has_permissions_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`tenant_id`,`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_roles_role_id_foreign` (`role_id`),
  KEY `model_has_roles_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `municipality_postal_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `municipality_postal_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `municipality_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `province_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `province_code` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `postal_code` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `municipality_postal_codes_municipality_name_index` (`municipality_name`),
  KEY `municipality_postal_codes_postal_code_index` (`postal_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offerta_caffe_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offerta_caffe_emails` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `offerta_caffe_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inviata_con` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` longtext COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offerta_caffe_emails_offerta_caffe_id_foreign` (`offerta_caffe_id`),
  KEY `offerta_caffe_emails_user_id_foreign` (`user_id`),
  CONSTRAINT `offerta_caffe_emails_offerta_caffe_id_foreign` FOREIGN KEY (`offerta_caffe_id`) REFERENCES `offerte_caffe` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offerta_caffe_emails_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offerte_caffe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offerte_caffe` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `valida_fino` date DEFAULT NULL,
  `righe` json NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bozza',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offerte_caffe_customer_id_foreign` (`customer_id`),
  KEY `offerte_caffe_user_id_foreign` (`user_id`),
  KEY `offerte_caffe_tenant_id_number_index` (`tenant_id`,`number`),
  CONSTRAINT `offerte_caffe_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offerte_caffe_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offerte_caffe_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_methods_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_lists` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'listino',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_lists_supplier_id_foreign` (`supplier_id`),
  KEY `price_lists_tenant_id_index` (`tenant_id`),
  KEY `price_lists_valid_from_valid_to_index` (`valid_from`,`valid_to`),
  KEY `price_lists_category_index` (`category`),
  CONSTRAINT `price_lists_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_lists_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prodotti_caffe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prodotti_caffe` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gruppo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `formato` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prezzo` decimal(10,2) NOT NULL,
  `ordinamento` int unsigned NOT NULL DEFAULT '0',
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_exclusions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_exclusions` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `excludes_product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_exclusions_unique` (`product_id`,`excludes_product_id`),
  KEY `product_exclusions_excludes_product_id_foreign` (`excludes_product_id`),
  CONSTRAINT `product_exclusions_excludes_product_id_foreign` FOREIGN KEY (`excludes_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_exclusions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_families`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_families` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_families_tenant_id_index` (`tenant_id`),
  CONSTRAINT `product_families_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_option_slot_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_option_slot_items` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slot_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_option_slot_items_slot_id_component_product_id_unique` (`slot_id`,`component_product_id`),
  KEY `product_option_slot_items_component_product_id_foreign` (`component_product_id`),
  CONSTRAINT `product_option_slot_items_component_product_id_foreign` FOREIGN KEY (`component_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_option_slot_items_slot_id_foreign` FOREIGN KEY (`slot_id`) REFERENCES `product_option_slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_option_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_option_slots` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slot_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_qty` int unsigned NOT NULL DEFAULT '0',
  `max_qty` int unsigned DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_option_slots_product_id_slot_name_unique` (`product_id`,`slot_name`),
  CONSTRAINT `product_option_slots_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_prices` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_prices_product_id_valid_from_valid_to_index` (`product_id`,`valid_from`,`valid_to`),
  CONSTRAINT `product_prices_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_requirements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_requirements` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_requirements_unique` (`product_id`,`requires_product_id`),
  KEY `product_requirements_requires_product_id_foreign` (`requires_product_id`),
  CONSTRAINT `product_requirements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_requirements_requires_product_id_foreign` FOREIGN KEY (`requires_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `eureka_article_id` bigint unsigned DEFAULT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_family_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('machine','auxiliary_unit','option','accessory','service') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('franke_ufficiale','terzo') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `gestionale_code` int unsigned DEFAULT NULL COMMENT 'id_eureka dell''articolo (sl_articolo), per l''invio delle schede lavoro',
  `gestionale_suggested_code` int unsigned DEFAULT NULL,
  `gestionale_suggested_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_sku_unique` (`sku`),
  KEY `products_category_id_foreign` (`category_id`),
  KEY `products_product_family_id_foreign` (`product_family_id`),
  KEY `products_tenant_id_index` (`tenant_id`),
  KEY `products_type_index` (`type`),
  KEY `products_brand_id_foreign` (`brand_id`),
  KEY `products_eureka_article_id_index` (`eureka_article_id`),
  CONSTRAINT `products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_product_family_id_foreign` FOREIGN KEY (`product_family_id`) REFERENCES `product_families` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_emails` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quote_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_emails_user_id_foreign` (`user_id`),
  KEY `quote_emails_quote_id_index` (`quote_id`),
  CONSTRAINT `quote_emails_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_emails_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_group_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_group_emails` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quote_group_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_group_emails_user_id_foreign` (`user_id`),
  KEY `quote_group_emails_quote_group_id_index` (`quote_group_id`),
  CONSTRAINT `quote_group_emails_quote_group_id_foreign` FOREIGN KEY (`quote_group_id`) REFERENCES `quote_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_group_emails_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_groups` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('bozza','inviato','scelto','scaduto') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bozza',
  `sent_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `public_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_first_viewed_at` timestamp NULL DEFAULT NULL,
  `client_last_viewed_at` timestamp NULL DEFAULT NULL,
  `client_view_count` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `quote_groups_tenant_id_number_unique` (`tenant_id`,`number`),
  UNIQUE KEY `quote_groups_public_token_unique` (`public_token`),
  KEY `quote_groups_customer_id_foreign` (`customer_id`),
  CONSTRAINT `quote_groups_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_groups_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_products` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quote_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_quote_product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount` int NOT NULL DEFAULT '0',
  `tax` int NOT NULL DEFAULT '0',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `contratto_assistenza` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_products_product_id_foreign` (`product_id`),
  KEY `quote_products_quote_id_index` (`quote_id`),
  KEY `quote_products_parent_quote_product_id_index` (`parent_quote_product_id`),
  CONSTRAINT `quote_products_parent_quote_product_id_foreign` FOREIGN KEY (`parent_quote_product_id`) REFERENCES `quote_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `quote_products_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_responses` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quote_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quote_group_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signer_role` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_time` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `signature_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepted_pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepted_pdf_sha256` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `handled_by` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_responses_tenant_id_foreign` (`tenant_id`),
  KEY `quote_responses_quote_id_foreign` (`quote_id`),
  KEY `quote_responses_quote_group_id_foreign` (`quote_group_id`),
  KEY `quote_responses_handled_by_foreign` (`handled_by`),
  KEY `quote_responses_type_handled_at_index` (`type`,`handled_at`),
  CONSTRAINT `quote_responses_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quote_responses_quote_group_id_foreign` FOREIGN KEY (`quote_group_id`) REFERENCES `quote_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quote_responses_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quote_responses_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quote_group_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `information_request_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `billing_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bozza',
  `discount` decimal(5,2) NOT NULL DEFAULT '0.00',
  `extra_discount` decimal(5,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payment_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rental_monthly_fee` decimal(10,2) DEFAULT NULL,
  `rental_months` smallint unsigned DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `public_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_first_viewed_at` timestamp NULL DEFAULT NULL,
  `client_last_viewed_at` timestamp NULL DEFAULT NULL,
  `client_view_count` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `quotes_tenant_id_number_unique` (`tenant_id`,`number`),
  UNIQUE KEY `quotes_public_token_unique` (`public_token`),
  KEY `quotes_quote_group_id_foreign` (`quote_group_id`),
  KEY `quotes_customer_id_index` (`customer_id`),
  KEY `quotes_information_request_id_foreign` (`information_request_id`),
  KEY `quotes_billing_customer_id_foreign` (`billing_customer_id`),
  CONSTRAINT `quotes_billing_customer_id_foreign` FOREIGN KEY (`billing_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quotes_information_request_id_foreign` FOREIGN KEY (`information_request_id`) REFERENCES `information_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotes_quote_group_id_foreign` FOREIGN KEY (`quote_group_id`) REFERENCES `quote_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_tenant_id_name_guard_name_unique` (`tenant_id`,`name`,`guard_name`),
  KEY `roles_team_foreign_key_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_report_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_report_emails` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_report_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_report_emails_user_id_foreign` (`user_id`),
  KEY `service_report_emails_service_report_id_index` (`service_report_id`),
  CONSTRAINT `service_report_emails_service_report_id_foreign` FOREIGN KEY (`service_report_id`) REFERENCES `service_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_report_emails_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_report_maintenance_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_report_maintenance_schedule` (
  `service_report_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `maintenance_schedule_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`service_report_id`,`maintenance_schedule_id`),
  KEY `srms_maintenance_schedule_fk` (`maintenance_schedule_id`),
  CONSTRAINT `srms_maintenance_schedule_fk` FOREIGN KEY (`maintenance_schedule_id`) REFERENCES `maintenance_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `srms_service_report_fk` FOREIGN KEY (`service_report_id`) REFERENCES `service_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_report_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_report_materials` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_report_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_cost_snapshot` decimal(10,2) DEFAULT NULL,
  `line_total_snapshot` decimal(10,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_report_materials_material_id_foreign` (`material_id`),
  KEY `service_report_materials_service_report_id_index` (`service_report_id`),
  CONSTRAINT `service_report_materials_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_report_materials_service_report_id_foreign` FOREIGN KEY (`service_report_id`) REFERENCES `service_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_report_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_report_products` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_report_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_cost_snapshot` decimal(10,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_report_products_product_id_foreign` (`product_id`),
  KEY `service_report_products_service_report_id_index` (`service_report_id`),
  CONSTRAINT `service_report_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_report_products_service_report_id_foreign` FOREIGN KEY (`service_report_id`) REFERENCES `service_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_reports` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuale',
  `eureka_service_report_id` bigint unsigned DEFAULT NULL,
  `duplicato_suggerito_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duplicato_suggerito_motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eureka_destinazione_code` int unsigned DEFAULT NULL,
  `eureka_destinazione_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eureka_stato_documento` tinyint unsigned DEFAULT NULL,
  `eureka_stato_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eureka_fatture` json DEFAULT NULL,
  `eureka_fatturato_il` date DEFAULT NULL,
  `eureka_fatture_controllate_il` timestamp NULL DEFAULT NULL,
  `eureka_fattura_motivo` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eureka_fattura_indizio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `visita_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestionale_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestionale_document_date` date DEFAULT NULL,
  `customer_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `billing_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `machine_unit_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quote_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `machine_product_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `machine_material_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `machine_serial_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `technician_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `intervention_type` enum('installazione','disinstallazione','manutenzione_ordinaria','manutenzione_straordinaria','riparazione','garanzia','sanificazione') COLLATE utf8mb4_unicode_ci NOT NULL,
  `intervention_date` date NOT NULL,
  `arrival_at` datetime DEFAULT NULL,
  `departure_at` datetime DEFAULT NULL,
  `problem_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `work_performed` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `lavaggio_vie_count` smallint unsigned DEFAULT NULL,
  `status` enum('bozza','completato','firmato','inviato','in_gestionale','rifiutato') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bozza',
  `customer_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_signature_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `technician_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `gestionale_scheda_lavoro_id` int unsigned DEFAULT NULL,
  `gestionale_sync_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gestionale_sync_error` text COLLATE utf8mb4_unicode_ci,
  `gestionale_synced_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `pagante_fattura_customer_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pagante_fattura_rilevato_il` timestamp NULL DEFAULT NULL,
  `pagante_fattura_ok` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_reports_tenant_id_number_unique` (`tenant_id`,`number`),
  KEY `service_reports_quote_id_foreign` (`quote_id`),
  KEY `service_reports_machine_product_id_foreign` (`machine_product_id`),
  KEY `service_reports_customer_id_index` (`customer_id`),
  KEY `service_reports_technician_id_index` (`technician_id`),
  KEY `service_reports_intervention_type_index` (`intervention_type`),
  KEY `service_reports_machine_unit_id_foreign` (`machine_unit_id`),
  KEY `service_reports_eureka_service_report_id_index` (`eureka_service_report_id`),
  KEY `service_reports_machine_material_id_foreign` (`machine_material_id`),
  KEY `service_reports_billing_customer_id_foreign` (`billing_customer_id`),
  KEY `sr_tenant_data_index` (`tenant_id`,`intervention_date`),
  KEY `sr_status_index` (`status`),
  KEY `service_reports_duplicato_suggerito_id_foreign` (`duplicato_suggerito_id`),
  KEY `service_reports_tenant_id_eureka_fatturato_il_index` (`tenant_id`,`eureka_fatturato_il`),
  KEY `service_reports_tenant_id_eureka_fattura_motivo_index` (`tenant_id`,`eureka_fattura_motivo`),
  KEY `service_reports_visita_id_index` (`visita_id`),
  KEY `service_reports_pagante_fattura_customer_id_foreign` (`pagante_fattura_customer_id`),
  CONSTRAINT `service_reports_billing_customer_id_foreign` FOREIGN KEY (`billing_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_reports_duplicato_suggerito_id_foreign` FOREIGN KEY (`duplicato_suggerito_id`) REFERENCES `service_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_machine_material_id_foreign` FOREIGN KEY (`machine_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_machine_product_id_foreign` FOREIGN KEY (`machine_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_machine_unit_id_foreign` FOREIGN KEY (`machine_unit_id`) REFERENCES `machine_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_pagante_fattura_customer_id_foreign` FOREIGN KEY (`pagante_fattura_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_reports_technician_id_foreign` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reports_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `suppliers_tenant_id_index` (`tenant_id`),
  CONSTRAINT `suppliers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `legal_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sdi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iban` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notify_staff_emails` json DEFAULT NULL,
  `notify_information_request_emails` json DEFAULT NULL,
  `notify_leave_request_emails` json DEFAULT NULL,
  `notify_quote_emails` json DEFAULT NULL,
  `notify_quote_group_emails` json DEFAULT NULL,
  `notify_deadline_emails` json DEFAULT NULL,
  `notify_lavaggio_emails` json DEFAULT NULL,
  `notify_customer_gestionale_emails` json DEFAULT NULL,
  `notify_customer_gestionale_review_emails` json DEFAULT NULL,
  `notify_gestionale_sync_digest_emails` json DEFAULT NULL,
  `notify_gestionale_sync_failed_emails` json DEFAULT NULL,
  `notify_service_report_emails` json DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_master` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `client_contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_contact_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notify_quote_response_emails` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`),
  KEY `tenants_is_active_index` (`is_active`),
  KEY `tenants_is_master_index` (`is_master`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `time_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `time_entries` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `source` enum('app','manuale') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'app',
  `entered_by_user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('aperta','chiusa','corretta') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aperta',
  `trasferta` tinyint(1) NOT NULL DEFAULT '0',
  `destinazione_trasferta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `time_entries_user_id_foreign` (`user_id`),
  KEY `time_entries_entered_by_user_id_foreign` (`entered_by_user_id`),
  KEY `time_entries_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  KEY `time_entries_clock_in_index` (`clock_in`),
  CONSTRAINT `time_entries_entered_by_user_id_foreign` FOREIGN KEY (`entered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `time_entries_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `time_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tour_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tour_views` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `viewed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tour_views_user_id_page_slug_unique` (`user_id`,`page_slug`),
  KEY `tour_views_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `tour_views_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tour_views_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `daily_contract_hours` decimal(4,2) NOT NULL DEFAULT '8.00',
  `weekly_contract_hours` decimal(5,2) NOT NULL DEFAULT '40.00',
  `annual_leave_days` int unsigned NOT NULL DEFAULT '26',
  `default_morning_in` time DEFAULT NULL,
  `default_morning_out` time DEFAULT NULL,
  `default_afternoon_in` time DEFAULT NULL,
  `default_afternoon_out` time DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_tenant_id_index` (`tenant_id`),
  CONSTRAINT `users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vehicles` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `plate` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` smallint unsigned DEFAULT NULL,
  `assigned_user_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vehicles_assigned_user_id_foreign` (`assigned_user_id`),
  KEY `vehicles_tenant_id_index` (`tenant_id`),
  CONSTRAINT `vehicles_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vehicles_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_07_09_100000_create_tenants_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_07_09_100100_add_tenant_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_07_09_100911_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_07_09_110000_create_catalog_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_07_09_120000_create_customers_and_quotes_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_07_09_130000_create_information_requests_and_comodato_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_07_09_140000_create_service_reports_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_07_09_150000_create_time_tracking_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_07_09_160000_create_deadlines_and_maintenance_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_07_16_010000_create_brands_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_07_16_010100_add_brand_id_to_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_07_16_010200_add_parent_id_to_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_07_16_010300_create_product_option_slots_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_07_16_010400_drop_product_compatibilities_and_option_groups_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_07_17_010100_create_google_calendar_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_07_17_142255_create_municipality_postal_codes_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_07_17_163500_add_deadline_dates_to_vehicles_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_07_17_170000_add_coordinates_to_customers_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_07_18_090000_create_machine_units_tables',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_07_20_064001_create_materials_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_07_20_075823_create_material_orders_tables',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_07_20_081716_add_number_to_material_orders_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_07_20_090000_create_suppliers_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_07_20_090100_add_supplier_id_to_materials_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_07_20_090200_add_supplier_id_to_material_orders_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_07_21_150000_add_company_contact_fields_to_tenants_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_07_21_170000_backfill_tenant_contact_fields_from_defaults',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_07_21_000000_add_is_active_to_users_table',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_07_21_115550_create_breezy_sessions_table',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_07_21_134641_create_activity_log_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_07_20_100000_add_status_to_material_orders_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_07_20_100100_create_material_order_emails_table',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_07_21_180000_add_two_factor_columns_to_users_table',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_07_22_090000_drop_appointments_table',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_07_22_075127_add_amount_and_paid_at_to_deadlines_table',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_07_22_075150_drop_deadline_dates_from_vehicles_table',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_07_22_090000_create_notifications_table',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_07_22_112611_create_price_lists_table',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_07_22_132008_add_policy_number_to_deadlines_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_07_22_140000_add_notify_staff_emails_to_tenants_table',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_07_22_152102_add_source_and_gestionale_sync_to_customers_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_07_22_154706_create_lavaggi_table',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_07_23_090000_add_multi_contact_fields_to_customers_table',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_07_23_080917_add_website_fields_to_customers_table',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_07_23_154200_add_bollo_to_deadlines_type_enum',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_07_24_090000_add_rental_fields_to_quotes_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_07_24_100000_add_lavaggio_schedule_to_customers_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_07_24_120000_add_billing_customer_id_to_customers_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_07_24_171000_add_notification_recipient_groups_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_07_26_130000_add_legacy_id_for_reimportable_tables',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_07_27_150000_merge_lavaggio_into_maintenance_schedules',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_07_27_160000_add_default_shift_times_to_users_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_07_27_170000_add_billing_customer_id_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_07_27_170100_add_machine_unit_id_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_07_28_090000_add_gestionale_eureka_credentials_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_07_28_090100_add_gestionale_code_to_products_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_07_28_090200_add_gestionale_sync_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_07_28_100000_add_notify_deadline_emails_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_07_30_083758_add_status_and_nullable_due_date_to_maintenance_schedules_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_07_30_090000_add_notify_customer_gestionale_emails_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_07_30_090100_add_gestionale_review_to_customers_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_07_30_100000_add_gestionale_suggested_code_to_customers_and_products',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_07_31_090000_add_gestionale_code_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_08_02_090000_add_gestionale_suggested_label_to_customers_products_machine_units',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_08_03_090000_add_beverage_type_to_maintenance_schedules_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_08_03_090100_add_filtro_sostituito_to_lavaggi_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_08_03_150000_create_machine_unit_proposals_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_08_04_090000_add_dismissed_at_to_machine_unit_proposals_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_08_04_090000_add_eureka_ids_to_products_and_machine_units',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_08_04_090100_add_eureka_service_report_id_to_service_reports',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_08_04_154532_drop_material_order_emails_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_08_04_154533_drop_status_from_material_orders_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_08_04_160000_add_customer_signature_name_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_08_05_115551_add_source_and_gestionale_code_to_materials_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_08_05_115551_add_source_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_08_05_115551_create_service_report_materials_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_08_05_120115_drop_machine_unit_proposals_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_08_05_123944_widen_gestionale_review_note_on_customers_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_08_05_130000_drop_owner_name_from_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_08_05_161236_clean_rtf_from_service_report_notes',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_08_06_090000_make_vino_maintenance_schedules_a_chiamata',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_08_06_100000_replace_comodato_macchina_with_machine_unit_on_maintenance_schedules',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_08_06_110000_drop_comodato_macchine',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_08_06_130727_add_lines_count_to_maintenance_schedules_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_08_10_140000_split_gestionale_notification_emails_and_add_service_report',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_08_10_150000_add_deleted_at_to_soft_deletable_tables',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_08_10_150000_add_service_report_id_to_lavaggi_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_08_11_090000_add_source_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_08_11_100000_add_gestionale_number_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_08_11_101628_drop_unused_legacy_and_eureka_columns',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_08_12_090000_add_gestionale_document_date_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_08_12_100000_add_line_total_snapshot_to_service_report_materials_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_08_12_110000_add_iban_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_08_12_110000_add_time_from_to_to_leave_requests_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_08_12_120000_drop_gestionale_eureka_credentials_from_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_08_12_130000_drop_partner_commercial_fields_from_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_08_12_130100_drop_commission_fields_from_quotes_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_08_13_090000_drop_note_from_lavaggi_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_08_13_120221_add_sanificazione_to_service_reports_intervention_type',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_08_13_140000_add_eureka_destinazione_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_08_17_090000_drop_notes_from_deadlines_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_08_17_090448_add_rifiutato_to_service_reports_status',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_08_18_081040_create_service_report_maintenance_schedule_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_08_18_120000_drop_amount_and_paid_at_from_deadlines_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_08_19_090000_add_appointment_fields_to_information_requests_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_08_19_100000_create_information_request_notes_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_08_19_110000_add_lines_washed_to_lavaggi_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_08_19_110100_add_type_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_08_19_123341_remove_price_delta_override_from_product_option_slot_items_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_08_20_140000_add_list_price_to_materials_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_08_24_090000_create_tour_views_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_08_24_140000_add_eureka_stato_documento_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_08_24_140100_add_eureka_billing_customer_code_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_08_26_100000_add_lead_intake_fields',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_08_27_100000_add_machine_material_id_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_08_27_110000_add_material_id_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_08_28_120000_add_information_request_id_to_quotes_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_08_31_120000_add_billing_customer_id_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_08_31_130000_add_in_gestionale_status_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_08_31_150000_add_billing_customer_id_to_quotes_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_08_31_160000_add_extra_discount_to_quotes_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_08_31_170000_add_sorting_indexes_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_08_31_180000_add_lavaggio_vie_count_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_09_01_090000_add_eureka_note_to_customers_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_09_01_100000_create_eureka_partite_aperte_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_09_01_110000_add_notify_lavaggio_emails_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_09_01_110100_seed_notify_lavaggio_emails_for_master_tenant',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_09_01_160000_add_tipo_pagamento_to_eureka_partite_aperte',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_09_01_180000_create_eureka_fatture_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_09_01_190000_add_acconto_flags_to_eureka_fatture',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_09_02_100000_add_detrazione_ambigua_to_eureka_fatture',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_09_02_110000_create_eureka_saldi_anagrafiche_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_09_02_120000_create_eureka_fatturato_mesi_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_09_02_130000_create_eureka_cashflow_tables',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_09_02_140000_add_duplicato_suggerito_to_service_reports',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_09_02_180000_add_fusione_suggerita_to_machine_units',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_09_04_100000_add_maintenance_code_to_materials_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_09_04_140000_add_maintenance_code_to_machine_units_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_09_21_100000_add_trasferta_to_time_entries_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_09_21_110000_create_prodotti_caffe_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_09_21_120000_add_contratto_assistenza_to_quote_products_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_09_21_130000_lyrae_formato_1kg_in_prodotti_caffe',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_09_21_140000_add_eureka_fatture_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_09_21_140000_create_offerte_caffe_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_09_21_150000_cioccolato_formato_500g_in_prodotti_caffe',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_09_21_160000_add_eureka_fattura_motivo_to_service_reports_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_09_21_170000_add_category_to_price_lists_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_09_21_180000_create_quote_responses_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_09_21_181000_add_client_contact_to_tenants_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_09_22_090000_add_disinstallazione_to_service_reports_intervention_type',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_09_22_120000_add_spostamento_suggerito_to_machine_units',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_09_22_140000_add_pagante_to_machine_unit_placements',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_09_22_160000_add_visita_id_to_service_reports',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_09_22_180000_create_esecuzioni_eureka_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_09_22_190000_add_controllo_pagante_fattura_to_service_reports',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_09_22_200000_add_fusa_in_to_machine_units',33);
