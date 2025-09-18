<?php
/**
 * Gerencia o processamento de todas as ações e formulários do admin.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Admin_Handler {

    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_post_bsm_import_contacts', array( $this, 'handle_import_contacts' ) );
        add_action( 'wp_ajax_bsm_get_template_content', array( $this, 'ajax_get_template_content' ) );
    }
    
    public function ajax_get_template_content() {
        check_ajax_referer( 'bsm-template-nonce', 'nonce' );
        if ( ! current_user_can( 'bsm_manage_campaigns' ) ) { wp_send_json_error( array( 'message' => 'Permissão negada.' ), 403 ); }
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( ! $template_id ) { wp_send_json_success( array( 'content' => '' ) ); }
        global $wpdb;
        $content = $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$wpdb->prefix}bluesendmail_templates WHERE template_id = %d", $template_id ) );
        if ( is_null( $content ) ) { wp_send_json_error( array( 'message' => 'Template não encontrado.' ), 404 ); }
        wp_send_json_success( array( 'content' => $content ) );
    }

    public function handle_actions() {
        if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'bluesendmail' ) !== false ) {
			if ( false === get_transient( 'bsm_scheduled_check_lock' ) ) {
				set_transient( 'bsm_scheduled_check_lock', true, 5 * MINUTE_IN_SECONDS );
				$this->plugin->cron->enqueue_scheduled_campaigns();
			}
		}

		$page = $_GET['page'] ?? '';
		if ( 'bluesendmail-settings' === $page && isset( $_POST['bsm_send_test_email'] ) ) $this->handle_send_test_email();
		if ( ( 'bluesendmail-campaigns' === $page || 'bluesendmail-new-campaign' === $page ) && ( isset( $_POST['bsm_save_draft'] ) || isset( $_POST['bsm_send_campaign'] ) || isset( $_POST['bsm_schedule_campaign'] ) ) ) $this->handle_save_campaign();
		
        if ( 'bluesendmail-contacts' === $page ) {
			if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['contact'] ) ) $this->handle_delete_contact();
			if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) $this->handle_bulk_delete_contacts();
			if ( isset( $_POST['bsm_save_contact'] ) ) $this->handle_save_contact();
		}

		if ( 'bluesendmail-lists' === $page ) {
			if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['list'] ) ) $this->handle_delete_list();
			if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) $this->handle_bulk_delete_lists();
			if ( isset( $_POST['bsm_save_list'] ) ) $this->handle_save_list();
		}
        if ( 'bluesendmail-templates' === $page ) {
            if ( isset( $_POST['bsm_save_template'] ) ) $this->handle_save_template();
            if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['template'] ) ) $this->handle_delete_template();
        }
        if ( 'bluesendmail-automations' === $page ) {
            if ( isset( $_POST['bsm_save_automation'] ) ) $this->handle_save_automation();
            if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['automation'] ) ) $this->handle_delete_automation();
        }

        // Handlers para Formulários
        if ( 'bluesendmail-forms' === $page ) {
            if ( isset( $_POST['bsm_save_form'] ) ) $this->handle_save_form();
            if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['form'] ) ) $this->handle_delete_form();
            if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) $this->handle_bulk_delete_forms();
        }
    }

    public function handle_save_campaign() {
		if ( ! isset( $_POST['bsm_save_campaign_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_save_campaign_nonce'], 'bsm_save_campaign_action' ) ) {
            wp_die( esc_html__( 'Falha na verificação de segurança.', 'bluesendmail' ) );
        }
		if ( ! current_user_can( 'bsm_manage_campaigns' ) ) {
            wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
        }
		
        global $wpdb;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$title = sanitize_text_field( wp_unslash( $_POST['bsm_title'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['bsm_subject'] ?? '' ) );
		$preheader = sanitize_text_field( wp_unslash( $_POST['bsm_preheader'] ?? '' ) );
		$content = wp_kses_post( wp_unslash( $_POST['bsm_content'] ?? '' ) );
		$lists = isset( $_POST['bsm_lists'] ) ? array_map( 'absint', (array) $_POST['bsm_lists'] ) : array();
		
        $is_send_now = isset( $_POST['bsm_send_campaign'] );
		$is_schedule = isset( $_POST['bsm_schedule_campaign'] );
		$schedule_enabled = isset( $_POST['bsm_schedule_enabled'] ) && '1' === $_POST['bsm_schedule_enabled'];
		$schedule_date = sanitize_text_field( $_POST['bsm_schedule_date'] ?? '' );
		$schedule_time = sanitize_text_field( $_POST['bsm_schedule_time'] ?? '' );
		$scheduled_for = null;
		
        if ( ( $is_schedule || $schedule_enabled ) && ! empty( $schedule_date ) && ! empty( $schedule_time ) ) {
			$schedule_datetime = new DateTime( "$schedule_date $schedule_time", $this->plugin->bsm_get_timezone() );
			$schedule_datetime->setTimezone( new DateTimeZone( 'UTC' ) );
			$scheduled_for = $schedule_datetime->format( 'Y-m-d H:i:s' );
		}

		$status = 'draft';
		if ( $is_send_now ) {
            $status = 'queued';
        } elseif ( $is_schedule && $scheduled_for ) {
            $status = 'scheduled';
        }

		$data = array( 
            'title' => $title, 
            'subject' => $subject, 
            'preheader' => $preheader, 
            'content' => $content, 
            'status' => $status, 
            'lists' => maybe_serialize( $lists ), 
            'scheduled_for' => $scheduled_for 
        );

		if ( $campaign_id ) {
			$wpdb->update( "{$wpdb->prefix}bluesendmail_campaigns", $data, array( 'campaign_id' => $campaign_id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', 1 );
			$wpdb->insert( "{$wpdb->prefix}bluesendmail_campaigns", $data );
			$campaign_id = $wpdb->insert_id;
		}

		if ( ! $campaign_id ) { 
            set_transient( 'bsm_admin_notice', ['type' => 'error', 'message' => __('Falha ao salvar a campanha.', 'bluesendmail')], 30 ); 
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) ); 
            exit; 
        }

		$this->plugin->log_event( 'info', 'campaign', "Campanha #{$campaign_id} salva com status '{$status}'." );
		
        if ( $is_send_now ) {
			$this->plugin->enqueue_campaign_recipients( $campaign_id );
			set_transient( 'bsm_admin_notice', ['type' => 'success', 'message' => __('Campanha enfileirada para envio imediato!', 'bluesendmail')], 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-campaigns' ) );
			exit;
		}

		if ( $is_schedule ) {
			set_transient( 'bsm_admin_notice', ['type' => 'success', 'message' => __('Campanha agendada com sucesso!', 'bluesendmail')], 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-campaigns' ) );
			exit;
		}
        
        set_transient( 'bsm_admin_notice', ['type' => 'success', 'message' => __('Rascunho da campanha salvo com sucesso!', 'bluesendmail')], 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-campaigns&action=edit&campaign=' . $campaign_id ) );
		exit;
	}

    public function handle_import_contacts() {
        if ( ! isset( $_POST['bsm_import_nonce_field_step2'] ) || ! wp_verify_nonce( $_POST['bsm_import_nonce_field_step2'], 'bsm_import_nonce_action_step2' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_contacts' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
    
        set_time_limit(300);
    
        $file_path = sanitize_text_field( $_POST['bsm_import_file_path'] );
        $list_id   = absint( $_POST['bsm_import_list_id'] );
        $map       = (array) ( $_POST['column_map'] ?? [] );
    
        if ( ! file_exists( $file_path ) ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Arquivo de importação não encontrado. Por favor, tente novamente desde o passo 1.', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import' ) );
            exit;
        }
    
        if ( ! $list_id ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Nenhuma lista foi selecionada para a importação.', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import' ) );
            exit;
        }
    
        $email_column_index = array_search( 'email', $map, true );
        if ( false === $email_column_index ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('É obrigatório associar uma coluna ao campo "E-mail".', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import' ) );
            exit;
        }
    
        global $wpdb;
        $this->plugin->log_event('info', 'import', "Iniciando importação do arquivo: {$file_path}");
        
        $imported_count = 0; $skipped_count = 0; $row_count = 0;
    
        if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle ) ) !== false ) {
                $row_count++;
                if ( 1 === $row_count ) continue;
    
                $email = isset($data[ $email_column_index ]) ? sanitize_email( $data[ $email_column_index ] ) : '';
                if ( ! is_email( $email ) ) { $skipped_count++; continue; }
    
                $current_time = current_time( 'mysql', 1 );
                $contact_data = [ 'email' => $email, 'status' => 'subscribed', 'updated_at' => $current_time ];
    
                foreach ( $map as $index => $field ) {
                    if ( ! empty( $field ) && isset( $data[ $index ] ) ) { $contact_data[ $field ] = sanitize_text_field( $data[ $index ] ); }
                }
    
                $existing_contact_id = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM {$wpdb->prefix}bluesendmail_contacts WHERE email = %s", $email ) );
    
                if ( $existing_contact_id ) {
                    unset( $contact_data['email'] );
                    $wpdb->update( "{$wpdb->prefix}bluesendmail_contacts", $contact_data, array( 'contact_id' => $existing_contact_id ) );
                    $contact_id = $existing_contact_id;
                } else {
                    $contact_data['created_at'] = $current_time;
                    $wpdb->insert( "{$wpdb->prefix}bluesendmail_contacts", $contact_data );
                    $contact_id = $wpdb->insert_id;
                }
    
                if ( $contact_id ) {
                    $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}bluesendmail_contact_lists (contact_id, list_id) VALUES (%d, %d)", $contact_id, $list_id ) );
                    do_action('bsm_contact_added_to_list', $contact_id, $list_id);
                    $imported_count++;
                } else { $skipped_count++; }
            }
            fclose( $handle );
        }
    
        @unlink( $file_path );
        $this->plugin->log_event('info', 'import', "Importação concluída. {$imported_count} importados, {$skipped_count} ignorados.");
    
        $message = sprintf( __( 'Importação concluída! %d contatos importados/atualizados e %d linhas ignoradas.', 'bluesendmail' ), $imported_count, $skipped_count );
        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => $message], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts' ) );
        exit;
    }
    
    public function handle_save_contact() {
		if ( ! isset( $_POST['bsm_save_contact_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_contact_nonce_field'], 'bsm_save_contact_nonce_action' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
		
        global $wpdb;
		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		$data = array('email' => sanitize_email( $_POST['email'] ),'first_name' => sanitize_text_field( $_POST['first_name'] ),'last_name' => sanitize_text_field( $_POST['last_name'] ),'company' => sanitize_text_field( $_POST['company'] ),'job_title' => sanitize_text_field( $_POST['job_title'] ),'segment' => sanitize_text_field( $_POST['segment'] ),'status' => sanitize_key( $_POST['status'] ));
		if ( empty( $data['email'] ) ) { 
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('O e-mail é obrigatório.', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&action=new' ) ); 
            exit; 
        }
		
        $is_new_contact = ! $contact_id;
        $original_lists = [];
        if( ! $is_new_contact ){
            $results = $wpdb->get_results( $wpdb->prepare( "SELECT list_id FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE contact_id = %d", $contact_id ), ARRAY_A );
			if ( $results ) { $original_lists = wp_list_pluck( $results, 'list_id' ); }
        }

        if ( $contact_id ) {
			$result = $wpdb->update( "{$wpdb->prefix}bluesendmail_contacts", $data, array( 'contact_id' => $contact_id ) );
		} else {
			$result = $wpdb->insert( "{$wpdb->prefix}bluesendmail_contacts", $data );
			if ( $result ) { $contact_id = $wpdb->insert_id; }
		}

		if ( false === $result || ! $contact_id ) { 
            $db_error = ! empty( $wpdb->last_error ) ? ' ' . sprintf( __( 'Erro: %s', 'bluesendmail' ), $wpdb->last_error ) : '';
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Falha ao salvar o contato.', 'bluesendmail') . $db_error], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&action=' . ( $contact_id ? 'edit&contact=' . $contact_id : 'new' ) ) ); 
            exit;
        }

        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => $is_new_contact ? __('Contato adicionado com sucesso!', 'bluesendmail') : __('Contato atualizado com sucesso!', 'bluesendmail')], 30);

		$submitted_lists = isset( $_POST['lists'] ) ? array_map( 'absint', $_POST['lists'] ) : array();
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'contact_id' => $contact_id ), array( '%d' ) );
		
        if ( ! empty( $submitted_lists ) ) { 
            foreach ( $submitted_lists as $list_id ) {
                $wpdb->insert( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'contact_id' => $contact_id, 'list_id' => $list_id ), array( '%d', '%d' ) );
                
                if( !in_array($list_id, $original_lists) ){
                    $this->plugin->log_event( 'debug', 'automation_dispatch', sprintf('Disparando ação bsm_contact_added_to_list para contato #%d e lista #%d.', $contact_id, $list_id) );
                    do_action('bsm_contact_added_to_list', $contact_id, $list_id);
                }
            }
        }
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts' ) );
		exit;
	}

    public function handle_delete_contact() {
		$contact_id = absint( $_GET['contact'] );
		$nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_contact_' . $contact_id ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }

		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contacts", array( 'contact_id' => $contact_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'contact_id' => $contact_id ), array( '%d' ) );
		
        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Contato excluído com sucesso!', 'bluesendmail')], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts' ) );
		exit;
	}

	public function handle_bulk_delete_contacts() {
		if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
		
        $contact_ids = isset( $_POST['contact'] ) ? array_map( 'absint', $_POST['contact'] ) : array();
		if ( empty( $contact_ids ) ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Nenhum item foi selecionado.', 'bluesendmail')], 30);
        } else {
            global $wpdb;
            $ids_placeholder = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contacts WHERE contact_id IN ($ids_placeholder)", $contact_ids ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE contact_id IN ($ids_placeholder)", $contact_ids ) );
            set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Os contatos selecionados foram excluídos com sucesso.', 'bluesendmail')], 30);
        }
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts' ) );
		exit;
	}

	public function handle_delete_list() {
        $list_id = absint( $_GET['list'] );
        $nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'bsm_delete_list_' . $list_id ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_lists' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
    
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}bluesendmail_lists", array( 'list_id' => $list_id ), array( '%d' ) );
        $wpdb->delete( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'list_id' => $list_id ), array( '%d' ) );
        
        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Lista excluída com sucesso!', 'bluesendmail')], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists' ) );
        exit;
    }

	public function handle_bulk_delete_lists() {
        if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_lists' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
        
        $list_ids = isset( $_POST['list'] ) ? array_map( 'absint', $_POST['list'] ) : array();
        if ( empty( $list_ids ) ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Nenhum item foi selecionado.', 'bluesendmail')], 30);
        } else {
            global $wpdb;
            $ids_placeholder = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_lists WHERE list_id IN ($ids_placeholder)", $list_ids ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE list_id IN ($ids_placeholder)", $list_ids ) );
            set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('As listas selecionadas foram excluídas com sucesso.', 'bluesendmail')], 30);
        }
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists' ) );
        exit;
    }

	public function handle_save_list() {
        if ( ! isset( $_POST['bsm_save_list_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_list_nonce_field'], 'bsm_save_list_nonce_action' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_lists' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
        
        global $wpdb;
        $list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
        $data = array( 'name' => sanitize_text_field( $_POST['name'] ?? '' ), 'description' => sanitize_textarea_field( $_POST['description'] ?? '' ) );
    
        if ( empty( $data['name'] ) ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('O nome da lista não pode estar vazio.', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&action=' . ( $list_id ? 'edit&list=' . $list_id : 'new' ) ) );
            exit;
        }
    
        if ( $list_id ) {
            $result = $wpdb->update( "{$wpdb->prefix}bluesendmail_lists", $data, array( 'list_id' => $list_id ) );
            $message = __( 'Lista atualizada com sucesso!', 'bluesendmail' );
        } else {
            $data['created_at'] = current_time( 'mysql', 1 );
            $result = $wpdb->insert( "{$wpdb->prefix}bluesendmail_lists", $data );
            $message = __( 'Lista adicionada com sucesso!', 'bluesendmail' );
        }
    
        if ( false === $result ) {
            $db_error = ! empty( $wpdb->last_error ) ? ' ' . sprintf( __( 'Erro: %s', 'bluesendmail' ), $wpdb->last_error ) : '';
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Ocorreu um erro ao salvar a lista.', 'bluesendmail') . $db_error], 30);
            $redirect_url = admin_url( 'admin.php?page=bluesendmail-lists&action=' . ( $list_id ? 'edit&list=' . $list_id : 'new' ) );
        } else {
            set_transient('bsm_admin_notice', ['type' => 'success', 'message' => $message], 30);
            $redirect_url = admin_url( 'admin.php?page=bluesendmail-lists' );
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

	public function handle_save_automation() {
		if ( ! isset( $_POST['bsm_save_automation_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_automation_nonce_field'], 'bsm_save_automation_nonce_action' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_campaigns' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
	
		global $wpdb;
		$automation_id = isset( $_POST['automation_id'] ) ? absint( $_POST['automation_id'] ) : 0;
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		$status = sanitize_key( $_POST['status'] ?? 'inactive' );
		$trigger_list_id = absint( $_POST['trigger_list_id'] ?? 0 );
		$steps = isset( $_POST['steps'] ) && is_array( $_POST['steps'] ) ? $_POST['steps'] : array();
	
		if ( empty($name) || empty($trigger_list_id) ) {
			set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Nome e Gatilho da Automação são obrigatórios.', 'bluesendmail')], 30);
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-automations&action=' . ( $automation_id ? 'edit&automation=' . $automation_id : 'new' ) ) );
			exit;
		}
	
		$data = array(
			'name' => $name,
			'status' => $status,
			'trigger_type' => 'contact_added_to_list',
			'trigger_settings' => maybe_serialize( array( 'list_id' => $trigger_list_id ) ),
		);
	
		if ( $automation_id ) {
			$result = $wpdb->update( "{$wpdb->prefix}bluesendmail_automations", $data, array( 'automation_id' => $automation_id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', 1 );
			$result = $wpdb->insert( "{$wpdb->prefix}bluesendmail_automations", $data );
			if ($result) {
				$automation_id = $wpdb->insert_id;
			}
		}
	
		if ( ! $automation_id ) {
            $db_error = ! empty( $wpdb->last_error ) ? ' ' . sprintf( __( 'Erro DB: %s', 'bluesendmail' ), $wpdb->last_error ) : '';
			set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Ocorreu um erro ao salvar a automação.', 'bluesendmail') . $db_error], 30);
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-automations&action=' . ( $automation_id ? 'edit&automation=' . $automation_id : 'new' ) ) );
			exit;
		}
	
		// Apaga os passos antigos e salva os novos
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_automation_steps", array( 'automation_id' => $automation_id ), array( '%d' ) );
		$this->_save_steps_recursive( $automation_id, $steps );
	
		set_transient('bsm_admin_notice', ['type' => 'success', 'message' => $automation_id ? __( 'Automação atualizada com sucesso!', 'bluesendmail' ) : __( 'Automação criada com sucesso!', 'bluesendmail' )], 30);
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-automations' ) );
		exit;
	}
	
	private function _save_steps_recursive( $automation_id, $steps_data, $parent_id = 0, $branch = null ) {
		global $wpdb;
		$order = 0;
		if ( ! is_array( $steps_data ) ) {
			return;
		}

		foreach ( $steps_data as $step_data ) {
			if ( ! isset( $step_data['type'] ) ) { continue; }
			$order++;
			$settings = array();
			$type = sanitize_key( $step_data['type'] );
			
			if ( 'action' === $type ) {
				$settings['campaign_id'] = absint( $step_data['campaign_id'] ?? 0 );
			} elseif ( 'delay' === $type ) {
				$settings['value'] = absint( $step_data['value'] ?? 1 );
				$settings['unit'] = sanitize_key( $step_data['unit'] ?? 'day' );
			} elseif ( 'condition' === $type ) {
				$settings['type'] = 'campaign_opened';
				$settings['campaign_id'] = absint( $step_data['campaign_id'] ?? 0 );
			}
			
			$wpdb->insert(
				"{$wpdb->prefix}bluesendmail_automation_steps",
				array(
					'automation_id' => $automation_id,
					'parent_id'     => $parent_id,
					'branch'        => $branch,
					'step_order'    => $order,
					'step_type'     => $type,
					'step_settings' => maybe_serialize( $settings ),
				)
			);
			$new_step_id = $wpdb->insert_id;

			// Salva os ramos da condição de forma recursiva
			if ( 'condition' === $type && $new_step_id ) {
				if ( isset( $step_data['yes_branch'] ) && is_array( $step_data['yes_branch'] ) ) {
					$this->_save_steps_recursive( $automation_id, $step_data['yes_branch'], $new_step_id, 'yes' );
				}
				if ( isset( $step_data['no_branch'] ) && is_array( $step_data['no_branch'] ) ) {
					$this->_save_steps_recursive( $automation_id, $step_data['no_branch'], $new_step_id, 'no' );
				}
			}
		}
	}

	public function handle_delete_automation() {
		$automation_id = absint( $_GET['automation'] );
		$nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_automation_' . $automation_id ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_campaigns' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }

		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_automations", array( 'automation_id' => $automation_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_automation_steps", array( 'automation_id' => $automation_id ), array( '%d' ) );
		
        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Automação excluída com sucesso!', 'bluesendmail')], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-automations' ) );
		exit;
	}

	public function handle_save_template() {
        if ( ! isset( $_POST['bsm_save_template_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_template_nonce_field'], 'bsm_save_template_nonce_action' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_campaigns' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
        
        global $wpdb;
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

        if ( empty( $name ) ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('O nome não pode estar vazio.', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-templates&action=' . ( $template_id ? 'edit&template=' . $template_id : 'new' ) ) );
            exit;
        }

        $data = array( 'name' => $name, 'content' => $content );

        if ( $template_id ) {
            $result = $wpdb->update( "{$wpdb->prefix}bluesendmail_templates", $data, array( 'template_id' => $template_id ) );
            $message = __( 'Template atualizado com sucesso!', 'bluesendmail' );
        } else {
            $data['created_at'] = current_time( 'mysql', 1 );
            $result = $wpdb->insert( "{$wpdb->prefix}bluesendmail_templates", $data );
            $message = __( 'Template adicionado com sucesso!', 'bluesendmail' );
        }

        if ( false === $result ) {
            $db_error = ! empty( $wpdb->last_error ) ? ' ' . sprintf( __( 'Erro: %s', 'bluesendmail' ), $wpdb->last_error ) : '';
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Ocorreu um erro ao salvar na base de dados.', 'bluesendmail') . $db_error], 30);
            $redirect_url = admin_url( 'admin.php?page=bluesendmail-templates&action=' . ( $template_id ? 'edit&template=' . $template_id : 'new' ) );
        } else {
            set_transient('bsm_admin_notice', ['type' => 'success', 'message' => $message], 30);
            $redirect_url = admin_url( 'admin.php?page=bluesendmail-templates' );
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }
    
    public function handle_delete_template() {
		$template_id = absint( $_GET['template'] );
		$nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_template_' . $template_id ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_campaigns' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }

		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_templates", array( 'template_id' => $template_id ), array( '%d' ) );
		
        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Template excluído com sucesso!', 'bluesendmail')], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-templates' ) );
		exit;
	}

    public function handle_save_form() {
        if ( ! isset( $_POST['bsm_save_form_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_form_nonce_field'], 'bsm_save_form_nonce_action' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_lists' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }

        global $wpdb;
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        
        // CORREÇÃO: Adicionada a captura e sanitização da cor do botão
        $data = array(
            'title'           => sanitize_text_field( $_POST['title'] ),
            'list_id'         => absint( $_POST['list_id'] ),
            'fields'          => isset( $_POST['bsm_form_fields'] ) ? maybe_serialize( array_map( 'sanitize_key', (array) $_POST['bsm_form_fields'] ) ) : null,
            'content'         => sanitize_textarea_field( $_POST['content'] ),
            'button_text'     => sanitize_text_field( $_POST['button_text'] ),
            'button_color'    => sanitize_hex_color( $_POST['button_color'] ),
            'success_message' => sanitize_text_field( $_POST['success_message'] ),
            'error_message'   => sanitize_text_field( $_POST['error_message'] ),
        );

        if ( empty( $data['title'] ) || empty( $data['list_id'] ) ) {
            set_transient('bsm_admin_notice', ['type' => 'error', 'message' => __('Título e Lista são obrigatórios.', 'bluesendmail')], 30);
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-forms&action=' . ( $form_id ? 'edit&form=' . $form_id : 'new' ) ) );
            exit;
        }

        if ( $form_id ) {
            $wpdb->update( "{$wpdb->prefix}bluesendmail_forms", $data, array( 'form_id' => $form_id ) );
        } else {
            $data['created_at'] = current_time( 'mysql', 1 );
            $wpdb->insert( "{$wpdb->prefix}bluesendmail_forms", $data );
        }

        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Formulário salvo com sucesso!', 'bluesendmail')], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-forms' ) );
        exit;
    }

    public function handle_delete_form() {
        $form_id = absint( $_GET['form'] );
        $nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'bsm_delete_form_' . $form_id ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_lists' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }

        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}bluesendmail_forms", array( 'form_id' => $form_id ), array( '%d' ) );

        set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Formulário excluído com sucesso!', 'bluesendmail')], 30);
        wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-forms' ) );
        exit;
    }

    public function handle_bulk_delete_forms() {
		if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
		if ( ! current_user_can( 'bsm_manage_lists' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
		
        $form_ids = isset( $_POST['form'] ) ? array_map( 'absint', $_POST['form'] ) : array();
		if ( ! empty( $form_ids ) ) {
            global $wpdb;
            $ids_placeholder = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_forms WHERE form_id IN ($ids_placeholder)", $form_ids ) );
            set_transient('bsm_admin_notice', ['type' => 'success', 'message' => __('Os formulários selecionados foram excluídos com sucesso.', 'bluesendmail')], 30);
        }
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-forms' ) );
		exit;
	}

    public function handle_send_test_email() {
        if ( ! isset( $_POST['bsm_send_test_email_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_send_test_email_nonce'], 'bsm_send_test_email_action' ) ) { wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) ); }
        if ( ! current_user_can( 'bsm_manage_settings' ) ) { wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) ); }
        
        $recipient = sanitize_email( $_POST['bsm_test_email_recipient'] );
        if ( ! is_email( $recipient ) ) {
            set_transient( 'bsm_test_email_result', array( 'success' => false, 'message' => __( 'O endereço de e-mail fornecido é inválido.', 'bluesendmail' ) ), 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-settings' ) );
            exit;
        }

        $subject = '[' . get_bloginfo( 'name' ) . '] ' . __( 'E-mail de Teste do BlueSendMail', 'bluesendmail' );
		$body    = '<h1>🎉 ' . __( 'Teste de Envio Bem-sucedido!', 'bluesendmail' ) . '</h1><p>' . __( 'Se você está recebendo este e-mail, suas configurações de envio estão funcionando corretamente.', 'bluesendmail' ) . '</p>';
		$mock_contact = (object) array( 'email' => $recipient, 'first_name' => 'Teste', 'last_name' => 'Usuário' );
		$result = $this->plugin->send_email( $recipient, $subject, $body, $mock_contact, 0 );

		if ( $result ) {
			set_transient( 'bsm_test_email_result', array( 'success' => true, 'message' => __( 'E-mail de teste enviado com sucesso!', 'bluesendmail' ) ), 30 );
			$this->plugin->log_event( 'success', 'test_email', "E-mail de teste enviado para {$recipient}." );
		} else {
			$message = __( 'Falha ao enviar o e-mail de teste.', 'bluesendmail' );
			if ( ! empty( $this->plugin->mail_error ) ) {
                $message .= '<br><strong>' . __( 'Erro retornado:', 'bluesendmail' ) . '</strong> <pre>' . esc_html( $this->plugin->mail_error ) . '</pre>';
            }
			set_transient( 'bsm_test_email_result', array( 'success' => false, 'message' => $message ), 30 );
			$this->plugin->log_event( 'error', 'test_email', "Falha ao enviar e-mail de teste para {$recipient}.", $this->plugin->mail_error );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-settings' ) );
		exit;
    }
}

