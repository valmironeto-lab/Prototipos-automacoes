<?php
/**
 * Gerencia todas as funcionalidades de Cron (tarefas agendadas).
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BSM_MAX_RETRY_ATTEMPTS', 3 );
define( 'BSM_CRON_LOCK_DURATION', 5 * MINUTE_IN_SECONDS );


class BSM_Cron {

	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->register_hooks();
	}

	private function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( 'bsm_process_sending_queue', array( $this, 'process_sending_queue' ) );
		add_action( 'bsm_check_scheduled_campaigns', array( $this, 'enqueue_scheduled_campaigns' ) );
		add_action( 'bsm_process_automation_queue', array( $this, 'process_automation_queue' ) );

		add_action( 'init', array( $this, 'ensure_cron_jobs_are_scheduled' ) );
		add_action( 'update_option_bluesendmail_settings', array( $this, 'reschedule_cron_on_settings_update' ), 10, 2 );
	}

	public function add_cron_interval( $schedules ) {
		$schedules['every_three_minutes'] = array( 'interval' => 180, 'display' => esc_html__( 'A Cada 3 Minutos', 'bluesendmail' ) );
		$schedules['every_five_minutes']  = array( 'interval' => 300, 'display' => esc_html__( 'A Cada 5 Minutos (Recomendado)', 'bluesendmail' ) );
		$schedules['every_ten_minutes']   = array( 'interval' => 600, 'display' => esc_html__( 'A Cada 10 Minutos', 'bluesendmail' ) );
		$schedules['every_fifteen_minutes'] = array( 'interval' => 900, 'display' => esc_html__( 'A Cada 15 Minutos', 'bluesendmail' ) );
		return $schedules;
	}

	public function reschedule_cron_on_settings_update( $old_value, $new_value ) {
		$old_interval = $old_value['cron_interval'] ?? 'every_five_minutes';
		$new_interval = $new_value['cron_interval'] ?? 'every_five_minutes';
		if ( $old_interval !== $new_interval ) {
			wp_clear_scheduled_hook( 'bsm_process_sending_queue' );
			wp_schedule_event( time(), $new_interval, 'bsm_process_sending_queue' );
			wp_clear_scheduled_hook( 'bsm_check_scheduled_campaigns' );
			wp_schedule_event( time(), 'every_five_minutes', 'bsm_check_scheduled_campaigns' );
			wp_clear_scheduled_hook( 'bsm_process_automation_queue' );
			wp_schedule_event( time(), 'every_five_minutes', 'bsm_process_automation_queue' );
		}
	}
    
	public function process_automation_queue() {
		if ( get_transient( 'bsm_automation_queue_lock' ) ) {
			return;
		}
		set_transient( 'bsm_automation_queue_lock', true, BSM_CRON_LOCK_DURATION );

		global $wpdb;
		$table_queue = $wpdb->prefix . 'bluesendmail_automation_queue';

		$items_to_process = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_queue} WHERE status = 'waiting' AND process_at <= %s ORDER BY process_at ASC LIMIT 20",
				current_time( 'mysql', 1 )
			)
		);

		if ( empty( $items_to_process ) ) {
			delete_transient( 'bsm_automation_queue_lock' );
			return;
		}
		
		$item_ids = wp_list_pluck( $items_to_process, 'queue_id' );
		$ids_placeholder = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table_queue} SET status = 'processing' WHERE queue_id IN ($ids_placeholder)", $item_ids ) );

		foreach ( $items_to_process as $item ) {
			$this->plugin->automations->process_queue_item( $item );
		}

		delete_transient( 'bsm_automation_queue_lock' );
	}

	public function process_sending_queue() {
		if ( get_transient( 'bsm_sending_queue_lock' ) ) {
			return;
		}
		set_transient( 'bsm_sending_queue_lock', true, BSM_CRON_LOCK_DURATION );

		global $wpdb;
		update_option( 'bsm_last_cron_run', time() );
		$items_to_process = $wpdb->get_results( $wpdb->prepare( "SELECT q.queue_id, q.campaign_id, q.attempts, c.* FROM {$wpdb->prefix}bluesendmail_queue q JOIN {$wpdb->prefix}bluesendmail_contacts c ON q.contact_id = c.contact_id WHERE q.status = 'pending' ORDER BY q.added_at ASC LIMIT %d", 20 ) );
		
		if ( empty( $items_to_process ) ) {
			delete_transient( 'bsm_sending_queue_lock' );
			return;
		}

		$campaign_ids = array_unique( wp_list_pluck( $items_to_process, 'campaign_id' ) );
		$campaigns = array();

		if ( ! empty( $campaign_ids ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
			$campaigns = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id IN ($ids_placeholder)", $campaign_ids ),
				OBJECT_K
			);
		}

		$sent_ids = [];
		$failed_ids = [];
		$retry_items = [];

		foreach ( $items_to_process as $item ) {
			$this->plugin->mail_error = '';
			$campaign = $campaigns[ $item->campaign_id ] ?? null;

			if ( ! $campaign ) {
				$failed_ids[] = $item->queue_id;
				$this->plugin->log_event( 'error', 'queue_processor', "Campanha ID #{$item->campaign_id} não encontrada para o item da fila #{$item->queue_id}." );
				continue;
			}
			$subject = ! empty( $campaign->subject ) ? $campaign->subject : $campaign->title;
			$content = $campaign->content;
			if ( ! empty( $campaign->preheader ) ) $content = '<span style="display:none !important; visibility:hidden; mso-hide:all; font-size:1px; color:#ffffff; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">' . esc_html( $campaign->preheader ) . '</span>' . $content;
			
			if ( $this->plugin->send_email( $item->email, $subject, $content, $item, $item->queue_id ) ) {
				$sent_ids[] = $item->queue_id;
			} else {
				$attempts = (int) $item->attempts + 1;
				if ( $attempts >= BSM_MAX_RETRY_ATTEMPTS ) {
					$failed_ids[] = $item->queue_id;
					$this->plugin->log_event( 'error', 'queue_processor', "Falha ao enviar e-mail para {$item->email} (Tentativa {$attempts}/" . BSM_MAX_RETRY_ATTEMPTS . "). Status alterado para: failed.", $this->plugin->mail_error );
				} else {
					$retry_items[] = $item;
					$this->plugin->log_event( 'error', 'queue_processor', "Falha ao enviar e-mail para {$item->email} (Tentativa {$attempts}/" . BSM_MAX_RETRY_ATTEMPTS . "). Tentando novamente mais tarde.", $this->plugin->mail_error );
				}
			}
		}

		if ( ! empty( $sent_ids ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $sent_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bluesendmail_queue SET status = 'sent' WHERE queue_id IN ($ids_placeholder)", $sent_ids ) );
		}
		if ( ! empty( $failed_ids ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $failed_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bluesendmail_queue SET status = 'failed' WHERE queue_id IN ($ids_placeholder)", $failed_ids ) );
		}
		foreach ( $retry_items as $item ) {
			$wpdb->update(
				"{$wpdb->prefix}bluesendmail_queue",
				array( 'attempts' => (int) $item->attempts + 1 ),
				array( 'queue_id' => $item->queue_id )
			);
		}

		foreach ( array_unique( wp_list_pluck( $items_to_process, 'campaign_id' ) ) as $campaign_id ) {
			if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d AND status = 'pending'", $campaign_id ) ) ) {
				$is_from_automation = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE step_settings LIKE %s", '%"campaign_id";i:' . $campaign_id . ';%'));
				if ( ! $is_from_automation ) {
					$wpdb->update( "{$wpdb->prefix}bluesendmail_campaigns", array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', 1 ) ), array( 'campaign_id' => $campaign_id ) );
					$this->plugin->log_event( 'info', 'campaign', "Campanha #{$campaign_id} concluída e marcada como 'enviada'." );
				}
			}
		}

		delete_transient( 'bsm_sending_queue_lock' );
	}

	public function enqueue_scheduled_campaigns() {
		global $wpdb;
		$campaigns_to_send = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'scheduled' AND scheduled_for <= %s", current_time( 'mysql', 1 ) ) );
		if ( empty( $campaigns_to_send ) ) return;
		foreach ( $campaigns_to_send as $campaign ) {
			$wpdb->update( "{$wpdb->prefix}bluesendmail_campaigns", array( 'status' => 'queued' ), array( 'campaign_id' => $campaign->campaign_id ) );
			$this->plugin->enqueue_campaign_recipients( $campaign->campaign_id );
		}
	}

	public function ensure_cron_jobs_are_scheduled() {
		if ( get_transient( 'bsm_cron_check_lock' ) ) {
			return;
		}
		set_transient( 'bsm_cron_check_lock', true, 5 * MINUTE_IN_SECONDS );
		
		$events = [
			'bsm_process_sending_queue'     => $this->plugin->options['cron_interval'] ?? 'every_five_minutes',
			'bsm_check_scheduled_campaigns' => 'every_five_minutes',
			'bsm_process_automation_queue'  => 'every_five_minutes',
		];

		foreach ( $events as $event => $interval ) {
			if ( ! wp_next_scheduled( $event ) ) {
				wp_schedule_event( time(), $interval, $event );
				$this->plugin->log_event( 'info', 'cron_system', "Tarefa agendada '{$event}' não encontrada. Reagendada com sucesso." );
			}
		}
	}
}

