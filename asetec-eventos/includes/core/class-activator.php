<?php
if (!defined('ABSPATH')) exit;

class Asetec_Activator {
  public static function activate() {
    self::ensure_schema();
  }

  public static function ensure_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    // Eventos
    $sql_events = "CREATE TABLE {$wpdb->prefix}asetec_events (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(64) NOT NULL UNIQUE,
      name VARCHAR(255) NOT NULL,
      location VARCHAR(255) DEFAULT '',
      starts_at DATETIME NULL,
      ends_at   DATETIME NULL,
      status ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(id)
    ) $charset;";

    // Padrón de asociados
    $sql_members = "CREATE TABLE {$wpdb->prefix}asetec_members (
      cedula VARCHAR(20) NOT NULL PRIMARY KEY,
      nombre VARCHAR(180) NOT NULL DEFAULT '',
      email  VARCHAR(180) NOT NULL DEFAULT '',
      activo TINYINT(1)   NOT NULL DEFAULT 1,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";

    // Tickets: añadimos party_size INT DEFAULT 1 (QR único por asociado)
    $sql_tickets = "CREATE TABLE {$wpdb->prefix}asetec_tickets (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      event_id INT UNSIGNED NOT NULL,
      entry_number INT UNSIGNED NOT NULL,
      token VARCHAR(64) NOT NULL UNIQUE,
      cedula VARCHAR(20) NOT NULL,
      nombre VARCHAR(180) NOT NULL DEFAULT '',
      email  VARCHAR(180) NOT NULL DEFAULT '',
      status ENUM('issued','checked_in') NOT NULL DEFAULT 'issued',
      party_size INT UNSIGNED NOT NULL DEFAULT 1,
      checked_in_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_event (event_id),
      KEY idx_event_cedula (event_id, cedula),
      CONSTRAINT fk_ticket_event FOREIGN KEY (event_id) REFERENCES {$wpdb->prefix}asetec_events(id) ON DELETE CASCADE
    ) $charset;";

    dbDelta($sql_events);
    dbDelta($sql_members);
    dbDelta($sql_tickets);
  }
}
