<?php
if (!defined('ABSPATH')) exit;

class Asetec_Members {
  private $table;
  public function __construct() {
    global $wpdb; $this->table = $wpdb->prefix.'asetec_members';
    add_action('admin_post_asetec_members_upload', [$this,'upload']);
  }

  /** Subida y actualización de padrón de asociados (CSV) */
  public function upload() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_members_csv');

    if (empty($_FILES['csv']['tmp_name'])) wp_die('Archivo CSV inválido');

    $tmp = $_FILES['csv']['tmp_name'];

    // Autodetectar delimitador (coma o punto y coma) leyendo la primera línea
    $firstLine = '';
    $fh = fopen($tmp,'r'); if ($fh) { $firstLine = fgets($fh); fclose($fh); }
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $f = fopen($tmp, 'r');
    if (!$f) wp_die('No se pudo abrir el CSV');

    // Leer encabezado (opcional)
    $header = fgetcsv($f, 0, $delimiter);
    $hasHeader = $header && count($header) >= 2;
    $map = $hasHeader ? array_flip(array_map('strtolower', $header)) : [];

    $count=0;
    global $wpdb;
    while ($row = fgetcsv($f, 0, $delimiter)) {
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

      $data = [
        'cedula'=>$cedula,
        'nombre'=>$nombre,
        'email'=>$email,
        'activo'=> (intval($activo) ? 1 : 0),
        'updated_at'=> current_time('mysql')
      ];

      $exists = $wpdb->get_var($wpdb->prepare("SELECT cedula FROM {$this->table} WHERE cedula=%s", $cedula));
      if ($exists) {
        $wpdb->update($this->table, $data, ['cedula'=>$cedula], ['%s','%s','%s','%d','%s'], ['%s']);
      } else {
        $wpdb->insert($this->table, $data, ['%s','%s','%s','%d','%s']);
      }
      $count++;
    }
    fclose($f);

    wp_redirect(admin_url('admin.php?page=asetec-asociados&imported='.$count)); exit;
  }
}
