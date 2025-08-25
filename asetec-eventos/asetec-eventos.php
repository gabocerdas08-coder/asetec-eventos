<?php
/**
 * Plugin Name: ASETEC Eventos
 * Description: Sistema de entradas con PDF+QR, check-in, padrón de asociados y reportes para ASETEC.
 * Version: 0.1.0
 * Author: ASETEC TI
 * Text Domain: asetec-eventos
 */
if (!defined('ABSPATH')) exit;

/** Helper: aviso en admin */
if (!function_exists('asetec_admin_notice')) {
  function asetec_admin_notice($msg, $type = 'warning') {
    add_action('admin_notices', function() use ($msg, $type) {
      echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . wp_kses_post($msg) . '</p></div>';
    });
  }
}

/** Paths base */
define('ASETEC_EVT_DIR', __DIR__);
define('ASETEC_EVT_INC', ASETEC_EVT_DIR . '/includes');

/** Require seguro (acumula faltantes) */
function asetec_safe_require($file_path, &$missing) {
  if (file_exists($file_path)) {
    require_once $file_path;
    return true;
  }
  $missing[] = str_replace(ABSPATH, '', $file_path);
  return false;
}

/** Archivos que queremos cargar */
$files = array(
  ASETEC_EVT_INC . '/core/class-activator.php',
  ASETEC_EVT_INC . '/core/class-events.php',
  ASETEC_EVT_INC . '/core/class-members.php',
  ASETEC_EVT_DIR . '/admin/class-admin-menu.php',
  ASETEC_EVT_INC . '/shortcodes.php',
  ASETEC_EVT_INC . '/api/class-board-api.php', // API REST pública
);

/** Cargar y recolectar faltantes sin usar arrow functions */
$missing = array();
foreach ($files as $f) {
  asetec_safe_require($f, $missing);
}

/** Si faltan archivos, mostramos panel básico y abortamos bootstrap */
if (!empty($missing)) {
  asetec_admin_notice(
    'ASETEC Eventos: faltan archivos del módulo. El panel básico permanecerá activo. Archivos faltantes: <code>' .
    implode('</code>, <code>', $missing) . '</code>',
    'warning'
  );

  add_action('admin_menu', function(){
    add_menu_page(
      'ASETEC Eventos',
      'ASETEC Eventos',
      'manage_options',
      'asetec-eventos',
      function(){
        echo '<div class="wrap"><h1>ASETEC Eventos</h1><p>Plugin activo. Módulos en instalación…</p></div>';
      },
      'dashicons-tickets',
      26
    );
  });

  // Salimos aquí para evitar instanciar clases inexistentes
  return;
}

/** Activación: crear/actualizar tablas si existe el activador */
if (class_exists('Asetec_Activator')) {
  register_activation_hook(__FILE__, array('Asetec_Activator', 'activate'));
}

/** Bootstrap: instanciar clases si existen */
add_action('plugins_loaded', function () {
  if (class_exists('Asetec_Members'))     { new Asetec_Members(); }
  if (class_exists('Asetec_Admin_Menu'))  { new Asetec_Admin_Menu(); }
  if (class_exists('Asetec_Board_API'))   { new Asetec_Board_API(); } // /wp-json/asetec/v1/...
});
