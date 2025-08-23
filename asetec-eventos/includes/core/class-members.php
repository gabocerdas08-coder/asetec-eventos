<?php
if (!defined('ABSPATH')) exit;

class Asetec_Members {
  private $table;

  public function __construct() {
    global $wpdb; 
    $this->table = $wpdb->prefix.'asetec_members';
    add_action('admin_post_asetec_members_upload', [$this,'upload']);
  }

  /**
   * Subir/actualizar padrón de asociados desde CSV.
   * - Soporta separador coma "," o punto y coma ";" (autodetección).
   * - Encabezado opcional: cedula,nombre,email,activo
   * - Campo "activo" acepta: 1/0, si/no, sí/no, true/false, activo/inactivo (por defecto 1).
   * - Modo purge (opc.): borra los que no vengan en el CSV (reemplazo total).
   * 
   * Redirige con parámetros:
   *   imported=N  -> filas procesadas (insertadas/actualizadas)
   *   purged=N    -> filas borradas (si "purge" activo)
   *   total=N     -> total de asociados en la tabla después del proceso
   */
  public function upload() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_csv');

    if (empty($_FILES['csv']['tmp_name'])) wp_die('Archivo CSV inválido');

    $purge = !empty($_POST['purge']); // si viene marcado, se purga al final
    $seen  = [];                      // cédulas vistas en este CSV

    $tmp = $_FILES['csv']['tmp_name'];

    // Autodetectar delimitador leyendo la primera línea
    $firstLine = '';
    $fh = fopen($tmp,'r'); 
    if ($fh) { $firstLine = fgets($fh); fclose($fh); }
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $f = fopen($tmp, 'r');
    if (!$f) wp_die('No se pudo abrir el CSV');

    // Leer encabezado (opcional)
    $header = fgetcsv($f, 0, $delimiter);
    $hasHeader = $header && count($header) >= 2;
    $map = $hasHeader ? array_flip(array_map('strtolower', $header)) : [];

    $count = 0;
    global $wpdb;

    while ($row = fgetcsv($f, 0, $delimiter)) {
      // Obtener campos con o sin encabezado
      if ($hasHeader) {
        $cedula = trim($row[$map['cedula']] ?? '');
        $nombre = trim($row[$map['nombre']] ?? '');
        $email  = trim($row[$map['email']]  ?? '');
        $activo = trim($row[$map['activo']] ?? '1');
      } else {
        // Sin encabezado: cedula,nombre,email,activo
        $cedula = trim($row[0] ?? '');
        $nombre = trim($row[1] ?? '');
        $email  = trim($row[2] ?? '');
        $activo = trim($row[3] ?? '1');
      }

      if ($cedula === '') continue;

      // Normalización flexible del campo "activo"
      $val = strtolower($activo);
      if ($val === '') { 
        $activo = 1; 
      } elseif (in_array($val, ['1','si','sí','true','activo','activa','yes'])) { 
        $activo = 1; 
      } elseif (in_array($val, ['0','no','false','inactivo','inactiva'])) { 
        $activo = 0; 
      } else { 
        $activo = intval($activo) ? 1 : 0; 
      }

      // Marcar como visto
      $seen[$cedula] = true;

      // Datos a guardar
      $data = [
        'cedula'    => $cedula,
        'nombre'    => $nombre,
        'email'     => $email,
        'activo'    => $activo,
        'updated_at'=> current_time('mysql'),
      ];

      // UPSERT manual por cédula
      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT cedula FROM {$this->table} WHERE cedula=%s", $cedula
      ));
      if ($exists) {
        $wpdb->update(
          $this->table,
          $data,
          ['cedula'=>$cedula],
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
    fclose($f);

    // PURGE: borrar los que no vinieron en el CSV (reemplazar todo)
    $purged = 0;
    if ($purge) {
      $cedulas = array_keys($seen);
      if ($cedulas) {
        // DELETE WHERE cedula NOT IN (...)
        $placeholders = implode(',', array_fill(0, count($cedulas), '%s'));
        $sql = "DELETE FROM {$this->table} WHERE cedula NOT IN ($placeholders)";
        // Ejecutar y obtener filas afectadas
        $wpdb->query($wpdb->prepare($sql, ...$cedulas));
        $purged = $wpdb->rows_affected;
      } else {
        // CSV vacío con purge → vaciar tabla
        $wpdb->query("TRUNCATE TABLE {$this->table}");
        // No hay forma directa de saber cuántos borró; deja purged como 0.
      }
    }

    // Total actual en BD
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");

    // Redirigir con métrica completa
    $url = add_query_arg([
      'imported' => $count,
      'purged'   => $purged,
      'total'    => $total,
    ], admin_url('admin.php?page=asetec-asociados'));

    wp_redirect($url); 
    exit;
  }
  public function __construct() {
  global $wpdb; 
  $this->table = $wpdb->prefix.'asetec_members';
  add_action('admin_post_asetec_members_upload', [$this,'upload']);
  add_action('admin_post_asetec_members_clear', [$this,'clear']); // nuevo
}

// Nuevo método
public function clear() {
  if (!current_user_can('manage_options')) wp_die('No autorizado');
  check_admin_referer('asetec_members_clear');

  global $wpdb;
  $wpdb->query("TRUNCATE TABLE {$this->table}");

  wp_redirect(admin_url('admin.php?page=asetec-asociados&cleared=1'));
  exit;
}



}
