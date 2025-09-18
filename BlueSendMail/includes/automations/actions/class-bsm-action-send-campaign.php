<?php
/**
 * Ação de Automação: Enviar Campanha.
 *
 * @package BlueSendMail
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Action_Send_Campaign extends BSM_Abstract_Action {

    /**
     * Executa a ação de enviar a campanha.
     */
    public function run() {
        global $wpdb;

        $campaign_id = absint( $this->settings['campaign_id'] ?? 0 );
        $contact_id = $this->queue_item->contact_id;

        if ( ! $campaign_id || ! $contact_id ) {
            $this->plugin->log_event( 'error', 'automation_action', "Ação 'Enviar Campanha' falhou: ID da campanha ou do contato ausente para o item da fila #{$this->queue_item->queue_id}." );
            // Mesmo com erro, tentamos continuar o fluxo.
            $this->schedule_next_action();
            return;
        }

        // Adiciona o e-mail à fila de envio principal.
        $wpdb->insert( "{$wpdb->prefix}bluesendmail_queue", array(
            'campaign_id' => $campaign_id,
            'contact_id'  => $contact_id,
            'status'      => 'pending',
            'added_at'    => current_time( 'mysql', 1 )
        ));

        $this->plugin->log_event( 'info', 'automation_action', "Ação: Campanha #{$campaign_id} enfileirada para o contato #{$contact_id} pela automação #{$this->queue_item->automation_id}." );

        // Agenda a próxima ação para ser executada imediatamente.
        $this->schedule_next_action();
    }
}

