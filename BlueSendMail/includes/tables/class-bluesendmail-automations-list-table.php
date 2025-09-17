<?php
/**
 * Automations List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Automations_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Automação', 'bluesendmail' ),
				'plural'   => __( 'Automações', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'name'       => __( 'Nome', 'bluesendmail' ),
			'trigger'    => __( 'Gatilho', 'bluesendmail' ),
			'action'     => __( 'Ação', 'bluesendmail' ),
			'status'     => __( 'Status', 'bluesendmail' ),
			'created_at' => __( 'Data de Criação', 'bluesendmail' ),
		);
	}

	/**
	 * Prepara os itens para a tabela (versão compatível com a nova estrutura).
	 */
	public function prepare_items() {
		global $wpdb;
		$table_automations = $wpdb->prefix . 'bluesendmail_automations';
		$table_lists       = $wpdb->prefix . 'bluesendmail_lists';
		
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// A query busca apenas os dados principais da automação.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_automations} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		
		$this->items = $wpdb->get_results( $sql, ARRAY_A );

		// Busca todas as listas de uma vez para mapeamento (melhora a performance).
		$lists = $wpdb->get_results( "SELECT list_id, name FROM {$table_lists}", OBJECT_K );

		// Adiciona os dados do gatilho e um resumo das ações a cada item.
		foreach ( $this->items as $key => $item ) {
			// Adiciona o nome da lista do gatilho.
			$trigger_settings = maybe_unserialize( $item['trigger_settings'] );
			if ( ! empty( $trigger_settings['list_id'] ) && isset( $lists[ $trigger_settings['list_id'] ] ) ) {
				$this->items[ $key ]['list_name'] = $lists[ $trigger_settings['list_id'] ]->name;
			} else {
				$this->items[ $key ]['list_name'] = __( 'N/D', 'bluesendmail' );
			}

			// Adiciona um resumo da ação (contagem de passos).
			$step_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE automation_id = %d", $item['automation_id'] ) );
			$this->items[ $key ]['action_summary'] = sprintf( _n( '%s passo', '%s passos', $step_count, 'bluesendmail' ), number_format_i18n($step_count) );
		}

		$total_items = $wpdb->get_var( "SELECT COUNT(automation_id) FROM $table_automations" );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	protected function column_default( $item, $column_name ) {
		return $item[ $column_name ] ? esc_html( $item[ $column_name ] ) : '';
	}
    
	protected function column_name( $item ) {
		$edit_url     = admin_url( 'admin.php?page=bluesendmail-automations&action=edit&automation=' . $item['automation_id'] );
		$delete_nonce = wp_create_nonce( 'bsm_delete_automation_' . $item['automation_id'] );
		$delete_url   = admin_url( 'admin.php?page=bluesendmail-automations&action=delete&automation=' . $item['automation_id'] . '&_wpnonce=' . $delete_nonce );
		
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Editar', 'bluesendmail' ) ),
			'delete' => sprintf( '<a href="%s" style="color:red;" onclick="return confirm(\'Tem certeza que deseja excluir esta automação?\');">%s</a>', esc_url( $delete_url ), __( 'Excluir', 'bluesendmail' ) ),
		);

		return sprintf( '<strong><a href="%1$s">%2$s</a></strong> %3$s', esc_url( $edit_url ), esc_html( $item['name'] ), $this->row_actions( $actions ) );
	}

	protected function column_status( $item ) {
		$status = $item['status'];
		$color  = 'inactive' === $status ? '#888' : 'green';
		$text   = 'active' === $status ? __( 'Ativo', 'bluesendmail' ) : __( 'Inativo', 'bluesendmail' );
		return '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $text ) . '</strong>';
	}

	/**
	 * Renderiza a coluna 'Gatilho'.
	 */
	protected function column_trigger( $item ) {
		if ( 'contact_added_to_list' === $item['trigger_type'] && ! empty( $item['list_name'] ) ) {
			return sprintf( __( 'Contato adicionado à lista: %s', 'bluesendmail' ), '<strong>' . esc_html( $item['list_name'] ) . '</strong>' );
		}
		return esc_html( $item['trigger_type'] );
	}

	/**
	 * Renderiza a coluna 'Ação'.
	 */
	protected function column_action( $item ) {
		return esc_html( $item['action_summary'] );
	}

	public function no_items() {
		_e( 'Nenhuma automação encontrada. Crie uma para começar!', 'bluesendmail' );
	}
}

