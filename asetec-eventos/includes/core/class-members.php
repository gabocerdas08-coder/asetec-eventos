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

  /** Vacía la tabla de asociados. */
  public function clear() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_clear');

    global $wpdb;
    // Garantizar existencia mínima (por si la activación no corrió)
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$this->table} (cedula VARCHAR(20) PRIMARY KEY)");
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

    if (empty($_FILES['csv']) || !is_array($_FILES['csv'])) {
      wp_redirect(add_query_arg(['upload_error'=>'Archivo no recibido'], admin_url('admin.php?page=asetec-asociados'))); exit;
    }

    // Log rápido para depurar en el error_log del servidor
    error_log('[ASETEC CSV] _FILES: ' . print_r($_FILES['csv'], true));

    // Usar API de WP para manejar el upload
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $overrides = [
      'test_form' => false,
      'mimes' => [
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        // Excel a veces manda esto para CSV
        'csvx' => 'application/vnd.ms-excel',
      ],
    ];
    $move = wp_handle_upload($_FILES['csv'], $overrides);

    if (!empty($move['error'])) {
      error_log('[ASETEC CSV] wp_handle_upload error: ' . $move['error']);
      wp_redirect(add_query_arg(['upload_error'=>rawurlencode($move['error'])], admin_url('admin.php?page=asetec-asociados'))); exit;
    }

    $path = $move['file']; // ruta física en uploads
    error_log('[ASETEC CSV] saved to: ' . $path);

    // Abrir el archivo desde uploads (no /tmp)
    $f = @fopen($path, 'r');
    if (!$f) {
      wp_redirect(add_query_arg(['upload_error'=>'No se pudo abrir el CSV en uploads'], admin_url('admin.php?page=asetec-asociados'))); exit;
    }

    // Leer la primera línea cruda para detectar delimitador
    $firstLine = fgets($f, 4096);
    if ($firstLine === false) $firstLine = '';
    // Remover BOM
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") $firstLine = substr($firstLine, 3);
    // Detectar separador
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
    error_log('[ASETEC CSV] delimiter: ' . $delimiter);

    // Rebobinar y usar fgetcsv normalmente
    rewind($f);
    $header    = fgetcsv($f, 0, $delimiter);
    $hasHeader = is_array($header) && count($header) >= 2;
    $map       = $hasHeader ? array_flip(array_map('strtolower', array_map('trim', $header))) : [];

    // Si “hay encabezado” pero NO trae 'cedula', mejor tratamos el archivo como SIN encabezado
    if ($hasHeader && !isset($map['cedula'])) {
      error_log('[ASETEC CSV] header sin "cedula" → modo sin encabezado');
      rewind($f);
      $header    = null;
      $hasHeader = false;
      $map       = [];
    } else {
      error_log('[ASETEC CSV] header: ' . json_encode($header));
    }

    $purge = !empty($_POST['purge']);
    $seen  = [];
    $count = 0;

    global $wpdb;
    // Asegurar estructura (por si activación no corrió)
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$this->table} (
      cedula VARCHAR(20) NOT NULL PRIMARY KEY,
      nombre VARCHAR(180) NOT NULL DEFAULT '',
      email  VARCHAR(180) NOT NULL DEFAULT '',
      activo TINYINT(1)   NOT NULL DEFAULT 1,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

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
        $wpdb->update($this->table, $data, ['cedula'=>$cedula], ['%s','%s','%s','%d','%s'], ['%s']);
      } else {
        $wpdb->insert($this->table, $data, ['%s','%s','%s','%d','%s']);
      }
      $count++;
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

    error_log("[ASETEC CSV] imported={$count}, purged={$purged}, total={$total}");

    // Redirigir con métricas o error
    $url = add_query_arg([
      'imported' => $count,
      'purged'   => $purged,
      'total'    => $total,
    ], admin_url('admin.php?page=asetec-asociados'));

    wp_redirect($url); exit;
  }
}
