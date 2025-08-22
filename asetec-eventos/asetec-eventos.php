<?php
/**
 * Plugin Name: ASETEC Eventos
 * Description: Control de entradas con PDF+QR, check-in y sorteos (esqueleto).
 * Version: 0.0.6
 * Author: ASETEC TI
 * Text Domain: asetec-eventos
 */
if (!defined('ABSPATH')) exit;

/** Helper: aviso en admin */
function asetec_ev_notice($msg, $type='error'){
  add_action('admin_notices', function() use ($msg,$type){
    echo '<div class="notice notice-'.esc_attr($type).'"><p>'.wp_kses_post($msg).'</p></div>';
  });
}

/** Intentar cargar clases nuevas (sin romper) */
$files = [
  __DIR__.'/includes/core/class-activator.php',
  __DIR__.'/includes/core/class-events.php',
  __DIR__.'/admin/class-admin-menu.php',
];
$missing = array_filter($files, fn($f)=>!file_exists($f));

if ($missing) {
  // Si faltan archivos, mostramos solo el “hola mundo” que ya te funcionaba
  asetec_ev_notice('ASETEC Eventos: faltan archivos para el módulo de eventos. Sigo cargando el panel básico.', 'warning');

  add_action('admin_menu', function(){
    add_menu_page(
      'ASETEC Eventos', 'ASETEC Eventos',
      'manage_options', 'asetec-eventos',
      function(){
        echo '<div class="wrap"><h1>ASETEC Eventos</h1><p>Plugin activo. Aquí iremos agregando módulos.</p></div>';
      },
      'dashicons-tickets', 26
    );
  });

} else {
  // Cargar clases del módulo
  require_once $files[0];
  require_once $files[1];
  require_once $files[2];

  if (class_exists('Asetec_Activator')) {
    register_activation_hook(__FILE__, ['Asetec_Activator','activate']);
  } else {
    asetec_ev_notice('No se encontró la clase Asetec_Activator.', 'error');
  }

  if (class_exists('Asetec_Events') && class_exists('Asetec_Admin_Menu')) {
    // Inicializar el admin CRUD
    add_action('plugins_loaded', function(){
      new Asetec_Events();
      new Asetec_Admin_Menu();
    });
  } else {
    asetec_ev_notice('No se encontraron clases Asetec_Events/Admin_Menu.', 'error');
  }
}
