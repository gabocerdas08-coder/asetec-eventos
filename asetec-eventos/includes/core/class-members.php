<?php
/**
 * Manejo de padrón de asociados (CSV)
 */
if (!defined('ABSPATH')) exit;

class Asetec_Members {
  private $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'asetec_members';

    add_action('admin_post_asetec_members_upload', [$this, 'upload']);
    add_action('admin_post_asetec_members_clear',  [$this, 'clear']);
  }

  /** Asegura que la tabla tenga el esquema completo (idempotente). */
  private function ensure_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$this->table} (
      cedula VARCHAR(20) NOT NULL PRIMARY KEY,
      nombre VARCHAR(180) NOT NULL DEFAULT '',
      email  VARCHAR(180) NOT NULL DEFAULT '',
      activo TINYINT(1)   NOT NULL DEFAULT 1,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";

    // dbDelta crea/actualiza columnas e índices si faltan
    dbDelta($sql);
  }

  /** Vacía la tabla de asociados. */
  public function clear() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_clear');

    global $wpdb;
    $this->ensure_schema();               // asegurar columnas completas
    $wpdb->query("TRUNCATE TABLE {$this->table}");

    $url = add_query_arg(['cleared'=>1, 'total'=>0], admin_url('admin.php?page=asetec-asociados'));
    wp_redirect($url); exit;
  }

  /**
   * Importa CSV usando wp_handle_upload (robusto p/ hosting).
   * - Detecta separador (',' o ';').
   * - Encabezado opcional; si no encuentra 'cedula', cae a modo sin encabezado.
   * - Normaliza "activo" (1/0, si/sí/no, true/false, activo/inactivo).
   * - purge opcional: borra los no presentes en el CSV.
   */
  public function upload() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_csv');

    // Asegurar esquema correcto ANTES de insertar
    $this->ensure_schema();

    if (empty($_FILES['csv']) || !is_array($_FILES['csv'])) {
      wp_redirect(add_query_arg(['upload_error'=>'Archivo no recibido'], admin_url('admin.php?page=asetec-asociados'))); exit;
    }

    // Subir a uploads con la API de WP
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $overrides = [
      'test_form' => false,
      'mimes' => [
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
        'csvx' => 'application/vnd.ms-excel',
      ],
    ];
    $move = wp_handle_upload($_FILES['csv'], $overrides);
    if (!empty($move['error'])) {
      error_log('[ASETEC CSV] wp_handle_upload error: ' . $move['error']);
      wp_redirect(add_query_arg(['upload_error'=>rawurlencode($move['error'])], admin_url('admin.php?page=asetec-asociados'))); exit;
    }

    $path = $move['file'];
    $f = @fopen($path, 'r');
    if (!$f) {
      wp_redirect(add_query_arg(['upload_error'=>'No se pudo abrir el CSV en uploads'], admin_url('admin.php?page=asetec-asociados'))); exit;
    }

    // Detectar delimitador en primera línea (quitando BOM)
    $firstLine = fgets($f, 4096);
    if ($firstLine === false) $firstLine = '';
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") $firstLine = substr($firstLine, 3);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    rewind($f);
    $header    = fgetcsv($f, 0, $delimiter);
    $hasHeader = is_array($header) && count($header) >= 2;
    $map       = $hasHeader ? array_flip(array_map('strtolower', array_map('trim', $header))) : [];

    // Si “hay encabezado” pero NO trae 'cedula', mejor modo sin encabezado
    if ($hasHeader && !isset($map['cedula'])) {
      rewind($f);
      $header    = null;
      $hasHeader = false;
      $map       = [];
    }

    $purge = !empty($_POST['purge']);
    $seen  = [];
    $inserted = 0;
    $updated  = 0;

    global $wpdb;

    while (($row = fgetcsv($f, 0, $delimiter)) !== false) {
      if (!is_array($row) || count($row) === 0) continue;

      if ($hasHeader) {
        $cedula = trim($row[$map['cedula']] ?? '');
        $nombre = trim($row[$map['nombre']] ?? '');
        $email  = trim($row[$map['email']]  ?? '');
        $activo = trim($row[$map['activo']] ?? '1');
      } else {
        // Orden fijo: cedula,nombre,email,activo
        $cedula = trim($row[0] ?? '');
        $nombre = trim($row[1] ?? '');
        $email  = trim($row[2] ?? '');
        $activo = trim($row[3] ?? '1');
      }

      if ($cedula === '') continue;

      // Normalizar activo
      $val = strtolower($activo);
      if ($val === '') { $activo = 1; }
      elseif (in_array($val, ['1','si','sí','true','activo','activa','yes'], true)) { $activo = 1; }
      elseif (in_array($val, ['0','no','false','inactivo','inactiva'], true)) { $activo = 0; }
      else { $activo = (int)$activo ? 1 : 0; }

      $seen[$cedula] = true;

      $data = [
        'cedula'     => $cedula,
        'nombre'     => $nombre,
        'email'      => $email,
        'activo'     => $activo,
        'updated_at' => current_time('mysql'),
      ];

      $exists = $wpdb->get_var($wpdb->prepare("SELECT cedula FROM {$this->table} WHERE cedula=%s", $cedula));

      if ($exists) {
        $ok = $wpdb->update($this->table, $data, ['cedula'=>$cedula], ['%s','%s','%s','%d','%s'], ['%s']);
        if ($ok !== false) { $updated++; } else { error_log('[ASETEC CSV] UPDATE error: '.$wpdb->last_error); }
      } else {
        $ok = $wpdb->insert($this->table, $data, ['%s','%s','%s','%d','%s']);
        if ($ok) { $inserted++; } else { error_log('[ASETEC CSV] INSERT error: '.$wpdb->last_error); }
      }
    }
    @fclose($f);

    // purge: eliminar cédulas no vistas
    $purged = 0;
    if ($purge) {
      $cedulas = array_keys($seen);
      if ($cedulas) {
        $placeholders = implode(',', array_fill(0, count($cedulas), '%s'));
        $sql = "DELETE FROM {$this->table} WHERE cedula NOT IN ($placeholders)";
        $wpdb->query($wpdb->prepare($sql, ...$cedulas));
        $purged = (int)$wpdb->rows_affected;
      } else {
        $wpdb->query("TRUNCATE TABLE {$this->table}");
      }
    }

    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");

    // Redirigir con métricas reales
    $url = add_query_arg([
      'imported' => ($inserted + $updated),
      'purged'   => $purged,
      'total'    => $total,
    ], admin_url('admin.php?page=asetec-asociados'));

    wp_redirect($url); exit;
  }
}
