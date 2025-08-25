<?php
/**
 * Plugin Name: ASETEC Eventos
 * Description: Sistema de entradas con PDF+QR, check-in, padrón de asociados y reportes para ASETEC.
 * Version: 0.1.0
 * Author: ASETEC TI
 * Text Domain: asetec-eventos
 */
if (!defined('ABSPATH')) exit;

/** Avisos en admin (helper) */
function asetec_admin_notice($msg, $type = 'warning') {
  add_action('admin_notices', function() use ($msg, $type) {
    echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . wp_kses_post($msg) . '</p></div>';
  });
}

/** Cargar módulos (condicional, para evitar fatales si falta algún archivo) */
$files = [
  __DIR__ . '/includes/core/class-activator.php',
  __DIR__ . '/includes/core/class-events.php',
  __DIR__ . '/includes/core/class-members.php',
  __DIR__ . '/admin/class-admin-menu.php',
  __DIR__ . '/includes/shortcodes.php',



];

$missing = array_filter($files, fn($f) => !file_exists($f));
if ($missing) {
  asetec_admin_notice('ASETEC Eventos: faltan archivos del módulo. El panel básico permanecerá activo. Archivos faltantes: <code>'.implode('</code>, <code>', array_map(fn($p)=>str_replace(ABSPATH,'',$p),$missing)).'</code>', 'warning');

  // Panel básico (como el que ya te funcionaba)
  add_action('admin_menu', function(){
    add_menu_page(
      'ASETEC Eventos','ASETEC Eventos','manage_options','asetec-eventos',
      function(){ echo '<div class="wrap"><h1>ASETEC Eventos</h1><p>Plugin activo. Módulos en instalación…</p></div>'; },
      'dashicons-tickets', 26
    );
  });
  return;
}

/** Requerir clases */
require_once $files[0]; // Activator
require_once $files[1]; // Events
require_once $files[2]; // Members (upload CSV)
require_once $files[3]; // Admin Menu
require_once $files[4]; // Shortcodes

/** Activación (crea/actualiza tablas) */
if (class_exists('Asetec_Activator')) {
  register_activation_hook(__FILE__, ['Asetec_Activator','activate']);
}

/** Bootstrap */
add_action('plugins_loaded', function () {
  // Inicializar handlers de admin-post (members upload)
  if (class_exists('Asetec_Members')) new Asetec_Members();
  // Menú y pantallas
  if (class_exists('Asetec_Admin_Menu')) new Asetec_Admin_Menu();
});
