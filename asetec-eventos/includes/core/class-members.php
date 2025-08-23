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

    // Hooks de acciones del admin
    add_action('admin_post_asetec_members_upload', [$this, 'upload']);
    add_action('admin_post_asetec_members_clear',  [$this, 'clear']);
  }

  /**
   * Vacía la tabla de asociados.
   * Redirige con ?cleared=1&total=0
   */
  public function clear() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_clear');

    global $wpdb;
    // Si la tabla no existe, evita fatal
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$this->table} (cedula VARCHAR(20) PRIMARY KEY)"); 
    $wpdb->query("TRUNCATE TABLE {$this->table}");

    $url = add_query_arg([
      'cleared' => 1,
      'total'   => 0,
    ], admin_url('admin.php?page=asetec-asociados'));

    wp_redirect($url);
    exit;
  }

  /**
   * Sube/actualiza el padrón desde CSV.
   * - Autodetecta delimitador (',' o ';')
   * - Encabezado opcional: cedula,nombre,email,activo
   * - Campo "activo" admite 1/0, si/sí/no, true/false, activo/inactivo (default 1)
   * - Si viene "purge" => borra los que no están en el CSV
   * Redirige con ?imported=N&purged=N&total=N
   */
  public function upload() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_csv');

    if (empty($_FILES['csv']) || empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
      wp_die('Archivo CSV inválido o no recibido.');
    }

    $purge = !empty($_POST['purge']); // reemplazar todo
    $seen  = [];                      // cédulas vistas en este CSV

    $tmp = $_FILES['csv']['tmp_name'];

    // Autodetectar delimitador leyendo la primera línea (maneja BOM)
    $firstLine = '';
    $fh = @fopen($tmp, 'r');
    if ($fh) {
      $firstLine = fgets($fh, 4096);
      @fclose($fh);
    }
    // Remover BOM si existe
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
      $firstLine = substr($firstLine, 3);
    }
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $f = @fopen($tmp, 'r');
    if (!$f) wp_die('No se pudo abrir el CSV.');

    // Leer encabezado (opcional)
    $header     = fgetcsv($f, 0, $delimiter);
    $hasHeader  = is_array($header) && count($header) >= 2;
    $map        = $hasHeader ? array_flip(array_map('strtolower', array_map('trim', $header))) : [];

    $count = 0;
    global $wpdb;

    // Asegurar que la tabla existe para evitar errores en sitios con activación previa fallida
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$this->table} (
      cedula VARCHAR(20) NOT NULL PRIMARY KEY,
      nombre VARCHAR(180) NOT NULL DEFAULT '',
      email  VARCHAR(180) NOT NULL DEFAULT '',
      activo TINYINT(1)   NOT NULL DEFAULT 1,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    while (($row = fgetcsv($f, 0, $delimiter)) !== false) {
      // Saltar filas vacías
      if (!is_array($row) || count($row) === 0) continue;

      // Mapeo con o sin encabezado
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

      if ($cedula === '') continue; // cédula obligatoria

      // Normalización flexible de "activo"
      $val = strtolower($activo);
      if ($val === '') { 
        $activo = 1;
      } elseif (in_array($val, ['1','si','sí','true','activo','activa','yes'], true)) { 
        $activo = 1;
      } elseif (in_array($val, ['0','no','false','inactivo','inactiva'], true)) { 
        $activo = 0;
      } else { 
        $activo = intval($activo) ? 1 : 0;
      }

      // Marcar vista
      $seen[$cedula] = true;

      // UPSERT manual por cédula
      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT cedula FROM {$this->table} WHERE cedula = %s",
        $cedula
      ));

      $data = [
        'cedula'     => $cedula,
        'nombre'     => $nombre,
        'email'      => $email,
        'activo'     => $activo,
        'updated_at' => current_time('mysql'),
      ];

      if ($exists) {
        $wpdb->update(
          $this->table,
          $data,
          ['cedula' => $cedula],
          ['%s','%s','%s','%d','%s'],
          ['%s']
        );
      } else {
        $wpdb->insert(
          $this->table,
          $data,
          ['%s','%s','%s','%d','%s']
        );
      }
      $count++;
    }
    @fclose($f);

    // PURGE (reemplazo total): eliminar cédulas no vistas
    $purged = 0;
    if ($purge) {
      $cedulas = array_keys($seen);
      if (!empty($cedulas)) {
        // Construir placeholders (%s, %s, ...)
        $placeholders = implode(',', array_fill(0, count($cedulas), '%s'));
        $sql = "DELETE FROM {$this->table} WHERE cedula NOT IN ($placeholders)";
        $wpdb->query($wpdb->prepare($sql, ...$cedulas));
        $purged = intval($wpdb->rows_affected);
      } else {
        // CSV vacío con purge → vaciar tabla
        $wpdb->query("TRUNCATE TABLE {$this->table}");
        // No sabemos cuántos había; calculamos total después
      }
    }

    // Total actual
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");

    // Redirigir con métricas
    $url = add_query_arg([
      'imported' => $count,
      'purged'   => $purged,
      'total'    => $total,
    ], admin_url('admin.php?page=asetec-asociados'));
    $url = add_query_arg([
  'imported' => $count,
  'purged'   => $purged,
  'total'    => $total,
  'dbg_delim'=> ($delimiter === ';' ? 'pcoma' : 'coma'),
  'dbg_hdr'  => ($hasHeader ? 1 : 0),
], admin_url('admin.php?page=asetec-asociados'));

    wp_redirect($url);
    exit;
  }
}
