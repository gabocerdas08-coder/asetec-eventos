<?php
if (!defined('ABSPATH')) exit;

/**
 * Helper: prepare() seguro. Solo usa $wpdb->prepare si hay placeholders.
 * $params debe ser array (puede estar vacío).
 */
if (!function_exists('asetec_wpdb_prepare_safe')) {
  function asetec_wpdb_prepare_safe($sql, $params) {
    global $wpdb;
    if (!is_array($params) || count($params) === 0) {
      // No hay placeholders, devolver SQL sin preparar
      return $sql;
    }
    // Llamada variable: prepare($sql, ...$params)
    $args = array_merge(array($sql), $params);
    return call_user_func_array(array($wpdb, 'prepare'), $args);
  }
}

if (!class_exists('Asetec_Board_API')) {

  class Asetec_Board_API {

    public function __construct() {
      add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
      register_rest_route('asetec/v1', '/board', array(
        'methods'  => 'GET',
        'callback' => array($this, 'get_board'),
        'permission_callback' => '__return_true',
        'args' => array(
          'event_code' => array('required' => true),
          'q'          => array(),
          'status'     => array(), // all|no_qr|qr|checked_in|qr_no_checkin
          'page'       => array(),
          'per_page'   => array(),
        ),
      ));

      register_rest_route('asetec/v1', '/board/export', array(
        'methods'  => 'GET',
        'callback' => array($this, 'export_csv'),
        'permission_callback' => '__return_true',
      ));
    }

    /** Construye JOIN/WHERE/params según filtros */
    private function build_where($event_id, $q, $status) {
      global $wpdb;
      $t = $wpdb->prefix . 'asetec_tickets';
      $where   = array("m.activo = 1");
      $params  = array();

      if ($q !== '') {
        if (is_numeric($q)) {
          // Búsqueda exacta por cédula, igual que el check-in
          $where[] = "m.cedula = %s";
          $params[] = $q;
        } else {
          // Búsqueda por nombre (LIKE, insensible a mayúsculas/minúsculas)
          $like = '%' . $wpdb->esc_like($q) . '%';
          $where[] = "LOWER(TRIM(m.nombre)) LIKE LOWER(TRIM(%s))";
          $params[] = $like;
        }
      }

      switch ($status) {
        case 'no_qr':
          $where[] = "tk.id IS NULL";
          break;
        case 'qr':
          $where[] = "tk.id IS NOT NULL";
          break;
        case 'checked_in':
          $where[] = "tk.status = 'checked_in'";
          break;
        case 'qr_no_checkin':
          $where[] = "tk.id IS NOT NULL AND tk.status <> 'checked_in'";
          break;
        case 'cap_available':
            $where[] = "tk.id IS NOT NULL AND tk.consumed < tk.capacity";
            break;
        default:
          // all
          break;
      }

      $join = $wpdb->prepare("LEFT JOIN $t tk ON tk.cedula = m.cedula AND tk.event_id = %d", $event_id);
      return array($join, $where, $params);
    }

    /** GET /asetec/v1/board */
    public function get_board($req) {
      global $wpdb;

      $event_code = isset($req['event_code']) ? sanitize_text_field($req['event_code']) : '';
      $q = isset($req['q']) ? trim($req['q']) : '';
      $status     = isset($req['status']) ? sanitize_text_field($req['status']) : 'all';
      $page       = max(1, intval(isset($req['page']) ? $req['page'] : 1));
      $per_page   = min(500, max(1, intval(isset($req['per_page']) ? $req['per_page'] : 50)));

      if ($event_code === '') {
        return new WP_Error('bad_request', 'Falta event_code', array('status' => 400));
      }

      $ev = $wpdb->get_row(
        $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code),
        ARRAY_A
      );
      if (!$ev) {
        return new WP_Error('not_found', 'Evento no encontrado', array('status' => 404));
      }

      $m = $wpdb->prefix . 'asetec_members';
      list($join, $where, $params) = $this->build_where($ev['id'], $q, $status);
      $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

      // ---- Total
      $sql_total = "SELECT COUNT(*) FROM $m m $join $where_sql";
      $sql_total = asetec_wpdb_prepare_safe($sql_total, $params);
      $total = (int) $wpdb->get_var($sql_total);

      // ---- Data (LIMIT/OFFSET no necesitan prepare si son enteros casteados)
      $offset = ($page - 1) * $per_page;
      $sql = "SELECT m.cedula, m.nombre,
                    COALESCE(tk.status,'') AS tk_status,
                    COALESCE(tk.capacity,0) AS capacity,
                    COALESCE(tk.consumed,0) AS consumed
                FROM $m m
                $join
                $where_sql
                ORDER BY m.nombre ASC
                LIMIT " . intval($per_page) . " OFFSET " . intval($offset);

      $rows = $wpdb->get_results($sql, ARRAY_A);

      $items = array();
      if ($rows) {
        foreach ($rows as $r) {
          $no_qr   = ($r['tk_status'] === '' || $r['tk_status'] === null);
            $items[] = array(
            'cedula'      => $r['cedula'],
            'nombre'      => $r['nombre'],
            'no_qr'       => ($r['tk_status'] === '' || $r['tk_status'] === null),
            'solicito_qr' => !($r['tk_status'] === '' || $r['tk_status'] === null),
            'check_in'    => ($r['tk_status'] === 'checked_in'),
            'estado_raw'  => $r['tk_status'],
            'capacity'    => intval($r['capacity']),
            'consumed'    => intval($r['consumed']),
            );

        }
      }

      return array(
        'event'    => array('id'=>$ev['id'], 'code'=>$ev['code'], 'name'=>$ev['name']),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'items'    => $items,
      );
    }

    /** GET /asetec/v1/board/export */
    public function export_csv($req) {
      $data = $this->get_board($req);
      if (is_wp_error($data)) return $data;

      // CSV
      $fh = fopen('php://temp', 'w+');
      fputcsv($fh, array('Cedula','Nombre','No solicito QR','Solicito QR','Check-in','Estado (raw)'));
      if (!empty($data['items'])) {
        foreach ($data['items'] as $it) {
          fputcsv($fh, array(
            $it['cedula'],
            $it['nombre'],
            $it['no_qr']       ? '1' : '0',
            $it['solicito_qr'] ? '1' : '0',
            $it['check_in']    ? '1' : '0',
            $it['estado_raw'],
          ));
        }
      }
      rewind($fh);
      $csv = stream_get_contents($fh);
      fclose($fh);

      $resp = new WP_REST_Response($csv, 200);
      $resp->header('Content-Type', 'text/csv; charset=UTF-8');
      $filename = 'reporte_' . sanitize_file_name($data['event']['code']) . '_' . date('Ymd_His') . '.csv';
      $resp->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
      return $resp;
    }
  }
}
