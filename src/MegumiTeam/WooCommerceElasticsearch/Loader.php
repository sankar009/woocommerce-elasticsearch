<?php
/**
 * Plugin Name: Woocommerce Elasticsearch
 * Version: 0.1
 * Description: WordPress search replace Elasticsearch
 * Author: horike
 * Text Domain: woocommerce-elasticsearch
 * Domain Path: /languages
 **/

namespace MegumiTeam\WooCommerceElasticsearch;

use Elastica\Client;
use Elastica\Type\Mapping;
use Elastica\Bulk;

class Loader {
	private static $instance;
	private function __construct() {}


	/**
	 * Magic Method
	 *
	 * @since 0.1
	 */
	public function __get($key){
		if ( $key === 'index' ) {
			$url = parse_url(home_url());
			if ( $url ) {
				return $url['host'];
			}
		}

		if ( $key === 'type' ) {
			return 'product';
		}

		if  ( $key === 'client' ) {
			return $this->_create_client();
		}
	}

	/**
	 * Return a singleton instance of the current class
	 *
	 * @since 0.1
	 * @return object
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			$c = __CLASS__;
			self::$instance = new $c();
		}
		return self::$instance;
	}

	/**
	 * Initialize.
	 *
	 * @since 0.1
	 */
	public function init() {
		add_action( 'add_option', array( $this, 'data_sync' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * admin_menu action hook.
	 *
	 * @since 0.1
	 */
	public function admin_menu() {
		add_options_page(
			'Woocommerce Elasticsearch',
			'Woocommerce Elasticsearch',
			'manage_options',
			'wp_elasticsearch',
			array( $this, 'options_page' )
		);
	}

	/**
	 * admin_init action hook. setting api.
	 *
	 * @since 0.1
	 */
	public function register_setting() {
		register_setting( 'wpElasticsearch', 'wpels_settings' );
		add_settings_section(
			'wpels_wpElasticsearch_section',
			__( '', 'wp-elasticsearch' ),
			array( $this, 'section_callback' ),
			'wpElasticsearch'
		);
		add_settings_field(
			'endpoint',
			__( 'Endpoint', 'wp-elasticsearch' ),
			array( $this, 'endpoint_render' ),
			'wpElasticsearch',
			'wpels_wpElasticsearch_section'
		);
	}

	/**
	 * render.
	 *
	 * @since 0.1
	 */
	public function endpoint_render() {
		$options = get_option( 'wpels_settings' );
		?>
		<input type='text' name='wpels_settings[endpoint]' value='<?php echo $options['endpoint']; ?>'>
		<?php
	}

	/**
	 * section_callback.
	 *
	 * @since 0.1
	 */
	public function section_callback() {
		echo __( '', 'wp-elasticsearch' );
	}

	/**
	 * rendr options_page.
	 *
	 * @since 0.1
	 */
	public function options_page() {
		?>
		<form action='options.php' method='post'>

			<h2>Woocommerce Elasticsearch</h2>
			<?php
			settings_fields( 'wpElasticsearch' );
			do_settings_sections( 'wpElasticsearch' );
			submit_button();
			?>

		</form>
		<?php
	}

	/**
	 * save_post action. Sync Elasticsearch.
	 *
	 * @param $post_id, $post
	 * @since 0.1
	 */
	public function save_post( $post_id, $post ) {
		if ( $post->post_type === 'product' ) {
			$ret = $this->_data_sync();
			if ( is_wp_error( $ret ) ) {
				$message = array_shift( $ret->get_error_messages( 'Elasticsearch Mapping Error' ) );
				wp_die($message);
			}
		}
	}

	/**
	 * admin_init action. mapping to Elasticsearch
	 *
	 * @since 0.1
	 */
	public function data_sync() {
		if ( isset( $_POST['wpels_settings']["endpoint"] ) ) {
			$ret = $this->_data_sync();
			if ( is_wp_error( $ret ) ) {
				$message = array_shift( $ret->get_error_messages( 'Elasticsearch Mapping Error' ) );
				wp_die($message);
			}
			return true;
		}
	}

	/**
	 * admin_init action. mapping to Elasticsearch
	 *
	 * @return true or WP_Error object
	 * @since 0.1
	 */
	private function _data_sync() {
		try {
			$client = $this->_create_client();

			$index = $client->getIndex( $this->index );

			$index->create( array(), true );
			$type = $index->getType( $this->type );

			$mapping = array(
							'post_title' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
							'post_content' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
							'post_excerpt' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
							'post_tags' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
							'post_category' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
						);

			$type->setMapping( $mapping );
			$my_posts = get_posts( array( 'posts_per_page' => -1, 'post_type' => 'product' ) );
			$docs = array();
			foreach ( $my_posts as $p ) {
				$d = array(
					'post_title' => (string) $p->post_title,
					'post_content' => (string) wp_strip_all_tags( $p->post_content, true ),
					'post_excerpt' => (string) wp_strip_all_tags( $p->post_excerpt, true ),
					'post_tags' => $this->_get_term_name_list( get_the_terms( $p->ID, 'product_tag' ) );
					'post_cat' => $this->_get_term_name_list( get_the_terms( $p->ID, 'product_cat' ) );
				);
				$docs[] = $type->createDocument( (int) $p->ID, $d );
			}
			$bulk = new Bulk( $client );
			$bulk->setType( $type );
			$bulk->addDocuments( $docs );
			$bulk->send();

			return true;
		} catch (Exception $e) {
			$err = new WP_Error( 'Elasticsearch Mapping Error', $e->getMessage() );
			return $err;
		}
	}

	private function _get_term_name_list( $terms ) {
		if ( ! $terms ) {
			return;
		}
		foreach ( $terms as $key => $value ) {
			$term_name_list[] = $value->name;
		}
		return $term_name_list;
	}

	/**
	 * Create connection to Elasticsearch
	 *
	 * @param $options
	 * @return Client client object
	 * @since 0.1
	 */
	private function _create_client() {
		$options = get_option( 'wpels_settings' );
		if ( empty( $options['endpoint'] ) ) {
			return false;
		}

		$client = new \Elastica\Client( array(
			'host' => $options['endpoint'],
			'port' => 80,
		));

		return $client;
	}
}
