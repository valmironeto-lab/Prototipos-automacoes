<?php
/**
 * Gerencia a lógica principal das automações. (Motor de Automação V2.1)
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Automations {

    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        $this->load_dependencies();
        $this->register_hooks();
    }

    private function load_dependencies() {
        $path = BLUESENDMAIL_PLUGIN_DIR . 'includes/automations/';
        require_once $path . 'class-bsm-data-layer.php';
        require_once $path . 'abstracts/abstract-bsm-action.php';
        require_once $path . 'actions/class-bsm-action-send-campaign.php';
        require_once $path . 'actions/class-bsm-action-delay.php';
    }

    private function register_hooks() {
        add_action( 'bsm_contact_added_to_list', array( $this, 'handle_contact_added_to_list' ), 10, 2 );
    }

    /**
     * Gatilho: Inicia um fluxo de trabalho para um contato quando ele entra numa lista.
     */
    public function handle_contact_added_to_list( $contact_id, $list_id ) {
        global $wpdb;
        $automations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bluesendmail_automations WHERE status = 'active' AND trigger_type = 'contact_added_to_list'" );

        foreach ( $automations as $automation ) {
            $trigger_settings = maybe_unserialize( $automation->trigger_settings );
            if ( ! empty( $trigger_settings['list_id'] ) && absint( $trigger_settings['list_id'] ) === $list_id ) {
                $this->start_automation_for_contact( $contact_id, $automation->automation_id );
            }
        }
    }

    /**
     * Coloca um contato na fila para iniciar uma automação.
     */
    public function start_automation_for_contact( $contact_id, $automation_id ) {
        global $wpdb;
        
        $contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_contacts WHERE contact_id = %d", $contact_id ) );
        if ( ! $contact ) {
            $this->plugin->log_event('warning', 'automation_trigger', "Tentativa de iniciar automação #{$automation_id} para contato #{$contact_id} que não existe.");
            return;
        }

        $data_layer = new BSM_Data_Layer( [ 'contact' => $contact ] );

        $wpdb->insert(
            "{$wpdb->prefix}bluesendmail_automation_queue",
            array(
                'automation_id'   => $automation_id,
                'contact_id'      => $contact_id,
                'status'          => 'waiting',
                'next_step_index' => 0,
                'data_layer'      => maybe_serialize( $data_layer->get_all_data() ),
                'process_at'      => current_time( 'mysql', 1 ),
                'created_at'      => current_time( 'mysql', 1 ),
            )
        );
        $this->plugin->log_event( 'info', 'automation_trigger', "Contato #{$contact_id} enfileirado para o início da automação #{$automation_id}." );
    }

    /**
     * Processa um item da fila de automação.
     */
    public function process_queue_item( $item ) {
        global $wpdb;

        $step = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE automation_id = %d ORDER BY step_order ASC LIMIT 1 OFFSET %d",
            $item->automation_id,
            $item->next_step_index
        ) );

        if ( ! $step ) {
            $wpdb->update( "{$wpdb->prefix}bluesendmail_automation_queue", [ 'status' => 'completed' ], [ 'queue_id' => $item->queue_id ] );
            $this->plugin->log_event( 'info', 'automation_engine', "Automação #{$item->automation_id} concluída para o contato #{$item->contact_id} (sem mais passos)." );
            return;
        }

        $data_layer = new BSM_Data_Layer( maybe_unserialize( $item->data_layer ) );
        $settings   = maybe_unserialize( $step->step_settings );
        $action_class = $this->get_action_class( $step->step_type );

        if ( class_exists( $action_class ) ) {
            $action = new $action_class( $this->plugin, $item, $data_layer, $settings );
            $action->run();
        } else {
            $this->plugin->log_event( 'error', 'automation_engine', "Classe de ação '{$action_class}' não encontrada para o passo #{$step->step_id}. O fluxo para o contato #{$item->contact_id} foi interrompido." );
            $wpdb->update( "{$wpdb->prefix}bluesendmail_automation_queue", [ 'status' => 'failed' ], [ 'queue_id' => $item->queue_id ] );
        }
    }

    /**
     * Mapeia um 'step_type' para um nome de classe de Ação.
     */
    private function get_action_class( $step_type ) {
        $map = [
            'action' => 'BSM_Action_Send_Campaign',
            'delay'  => 'BSM_Action_Delay',
        ];
        return $map[ $step_type ] ?? null;
    }
}

