<?php
/**
 * Camada de Dados da Automação (Data Layer).
 * Um container padronizado para os dados que fluem através de uma automação.
 *
 * @package BlueSendMail
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Data_Layer {

    /**
     * Armazena os dados.
     * @var array
     */
    private $data = [];

    /**
     * Construtor. Pode ser inicializado com dados.
     *
     * @param array $initial_data Dados iniciais.
     */
    public function __construct( array $initial_data = [] ) {
        $this->data = $initial_data;
    }

    /**
     * Adiciona ou atualiza um item de dados.
     *
     * @param string $key   A chave do item (ex: 'contact').
     * @param mixed  $value O valor do item.
     */
    public function add_item( $key, $value ) {
        $this->data[ sanitize_key( $key ) ] = $value;
    }

    /**
     * Recupera um item de dados.
     *
     * @param string $key A chave do item.
     * @return mixed|null O valor do item ou null se não existir.
     */
    public function get_item( $key ) {
        $key = sanitize_key( $key );
        return $this->data[ $key ] ?? null;
    }

    /**
     * Retorna todos os dados como um array.
     *
     * @return array
     */
    public function get_all_data() {
        return $this->data;
    }
}

