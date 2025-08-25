<?php
if (!defined('ABSPATH')) exit;

class Asetec_Activator {
  public static function activate() {
    global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    // Eventos
    $sql1 = "CREATE TABLE {$wpdb->prefix}asetec_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(50) NOT NULL UNIQUE,
      name VARCHAR(180) NOT NULL,
      starts_at DATETIME NULL,
      ends_at DATETIME NULL,
      location VARCHAR(180) NULL,
      status ENUM('draft','open','locked','closed') NOT NULL DEFAULT 'draft',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL
    ) $charset;";

    // Tickets (para módulo 2/3: QR y check-in)
    $sql2 = "CREATE TABLE {$wpdb->prefix}asetec_tickets (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_id BIGINT UNSIGNED NOT NULL,
      entry_number INT NOT NULL,
      token CHAR(32) NOT NULL UNIQUE,
      cedula VARCHAR(20) NOT NULL,
      nombre VARCHAR(180) NOT NULL,
      email VARCHAR(180) NOT NULL,
      status ENUM('issued','checked_in','revoked') NOT NULL DEFAULT 'issued',
      checked_in_at DATETIME NULL,
      checked_in_gate VARCHAR(20) NULL,
      checked_in_device VARCHAR(36) NULL,
      version INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      UNIQUE KEY uniq_event_entry (event_id, entry_number),
      KEY idx_event (event_id),
      KEY idx_token (token),
      KEY idx_cedula (cedula)
    ) $charset;";

    // Padrón de asociados (CSV)
    $sql3 = "CREATE TABLE {$wpdb->prefix}asetec_members (
      cedula VARCHAR(20) NOT NULL PRIMARY KEY,
      nombre VARCHAR(180) NOT NULL,
      email VARCHAR(180) NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
  }
}
