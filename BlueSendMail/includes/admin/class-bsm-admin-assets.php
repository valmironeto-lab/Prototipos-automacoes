<?php
/**
 * Gerencia o enfileiramento de scripts e estilos (CSS, JS).
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Admin_Assets {

    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets( $hook ) {
        $screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'bluesendmail' ) === false ) {
            return;
        }

		wp_enqueue_style( 'bluesendmail-admin-styles', plugins_url( '../../assets/css/admin.css', __FILE__ ), array(), BLUESENDMAIL_VERSION );
		
        $page = $_GET['page'] ?? '';
		$action = $_GET['action'] ?? '';

		$is_dashboard_page = 'bluesendmail' === $page;
		$is_campaign_editor = 'bluesendmail-new-campaign' === $page || ( 'bluesendmail-campaigns' === $page && 'edit' === $action ) || 'bluesendmail-templates' === $page;
		$is_import_page = 'bluesendmail-import' === $page;
		$is_reports_page = 'bluesendmail-reports' === $page;
        $is_settings_page = 'bluesendmail-settings' === $page;
		$is_automation_editor = ('bluesendmail-automations' === $page && in_array($action, ['new', 'edit']));
		
		$main_script_dependencies = array( 'jquery' );

		// Carrega assets para Select2 a partir do CDN
		if ( $is_campaign_editor || $is_import_page ) {
			wp_enqueue_style( 'bsm-select2-styles', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0-rc.0' );
			wp_enqueue_script( 'bsm-select2-script', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array( 'jquery' ), '4.1.0-rc.0', true );
			$main_script_dependencies[] = 'bsm-select2-script';
		}
		
		// Carrega assets para Chart.js a partir do CDN
		if ( $is_reports_page || $is_dashboard_page ) {
			wp_enqueue_script( 'bsm-chartjs-script', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', array(), '4.4.3', true );
			$main_script_dependencies[] = 'bsm-chartjs-script';
		}

		if ( $is_campaign_editor ) { 
            $main_script_dependencies[] = 'wp-editor'; 
        }

		// Carrega o script de administração principal com as dependências corretas.
		wp_enqueue_script( 'bluesendmail-admin-script', BLUESENDMAIL_PLUGIN_URL . 'assets/js/admin.js', $main_script_dependencies, BLUESENDMAIL_VERSION, true );
		
		// Carrega o novo script para o construtor de automações
		if ( $is_automation_editor ) {
			wp_enqueue_script( 'bsm-automation-builder', BLUESENDMAIL_PLUGIN_URL . 'assets/js/automation-builder.js', array('jquery', 'jquery-ui-sortable'), BLUESENDMAIL_VERSION, true );
		}
		
		$script_data = array( 
            'is_dashboard_page' => $is_dashboard_page, 
            'is_campaign_editor' => $is_campaign_editor, 
            'is_import_page' => $is_import_page, 
            'is_reports_page' => $is_reports_page,
            'is_settings_page' => $is_settings_page,
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bsm-template-nonce')
        );
		
		if ( $is_dashboard_page ) {
			$script_data['growth_chart_data'] = $this->get_contacts_growth_data();
			$script_data['performance_chart_data'] = $this->get_performance_data();
		}

		if ( $is_reports_page && ! empty( $_GET['campaign_id'] ) ) {
			global $wpdb;
			$campaign_id   = absint( $_GET['campaign_id'] );
			$sent          = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $campaign_id ) );
			$unique_opens  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $campaign_id ) );
			$unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $campaign_id ) );
			$script_data['chart_data'] = array( 'sent' => (int) $sent, 'opens' => (int) $unique_opens, 'clicks' => (int) $unique_clicks, 'labels' => array( 'not_opened' => __( 'Não Aberto', 'bluesendmail' ), 'opened' => __( 'Abertura Única', 'bluesendmail' ), 'clicked' => __( 'Clique Único (dentro dos que abriram)', 'bluesendmail' ) ) );
		}

		wp_localize_script( 'bluesendmail-admin-script', 'bsm_admin_data', $script_data );
    }

    private function get_contacts_growth_data() {
		global $wpdb;
		$results = $wpdb->get_results(
			"SELECT DATE(created_at) AS date, COUNT(contact_id) AS count
			 FROM {$wpdb->prefix}bluesendmail_contacts
			 WHERE created_at >= CURDATE() - INTERVAL 30 DAY
			 GROUP BY DATE(created_at)
			 ORDER BY date ASC"
		);

		$dates = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$date = date( 'Y-m-d', strtotime( "-{$i} days" ) );
			$dates[ $date ] = 0;
		}

		foreach ( $results as $result ) {
			$dates[ $result->date ] = (int) $result->count;
		}

		return array(
			'labels' => array_keys( $dates ),
			'data'   => array_values( $dates ),
		);
	}

    private function get_performance_data() {
        global $wpdb;
        $total_sent_emails = $wpdb->get_var( "SELECT COUNT(q.queue_id) FROM {$wpdb->prefix}bluesendmail_queue q JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
        $total_unique_opens = $wpdb->get_var( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
        $total_unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT cl.contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks cl JOIN {$wpdb->prefix}bluesendmail_campaigns c ON cl.campaign_id = c.campaign_id WHERE c.status = 'sent'" ) );
        
        return array(
            'sent' => (int) $total_sent_emails,
            'opens' => (int) $total_unique_opens,
            'clicks' => (int) $total_unique_clicks,
            'labels' => array(
                'not_opened' => __( 'Não Aberto', 'bluesendmail' ),
                'opened'     => __( 'Abertos (sem clique)', 'bluesendmail' ),
                'clicked'    => __( 'Clicados', 'bluesendmail' ),
            ),
        );
    }
}
