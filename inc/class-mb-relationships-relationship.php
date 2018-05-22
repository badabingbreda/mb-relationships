<?php
/**
 * The relationship class.
 * Registers meta boxes and custom fields for objects, displays and handles data.
 *
 * @package    Meta Box
 * @subpackage MB Relationships
 */

/**
 * The relationship class.
 */
class MB_Relationships_Relationship {
	/**
	 * The relationship settings.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * The object that connects "from".
	 *
	 * @var MB_Relationships_Object_Interface
	 */
	protected $from_object;

	/**
	 * The object that connects "to".
	 *
	 * @var MB_Relationships_Object_Interface
	 */
	protected $to_object;

	/**
	 * The wpdb object.
	 *
	 * @var wpdb
	 */
	protected $db;
	
	protected $from_type;
	
	protected $to_type;

	/**
	 * Register a relationship.
	 *
	 * @param array                           $settings       Relationship settings.
	 * @param MB_Relationships_Object_Factory $object_factory The instance of the API class.
	 */
	public function __construct( $settings, MB_Relationships_Object_Factory $object_factory ) {
		$this->settings    = $settings;
		$this->from_object = $object_factory->build( $this->from['object_type'] );
		$this->to_object   = $object_factory->build( $this->to['object_type'] );

		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Magic method to quick access to relationship settings.
	 *
	 * @param string $name Setting name.
	 *
	 * @return mixed
	 */
	public function __get( $name ) {
		return isset( $this->settings[ $name ] ) ? $this->settings[ $name ] : '';
	}

	/**
	 * Check if 2 objects has a relationship.
	 *
	 * @param int $from From object ID.
	 * @param int $to   To object ID.
	 *
	 * @return bool
	 */
	public function has( $from, $to ) {
		$rel_id = $this->db->get_var( $this->db->prepare(
			"SELECT `ID` FROM {$this->db->mb_relationships} WHERE `from`=%d AND `to`=%d AND `type`=%s",
			$from, $to, $this->id
		) );

		return (bool) $rel_id;
	}

	/**
	 * Add a relationship for 2 objects.
	 *
	 * @param int $from From object ID.
	 * @param int $to   To object ID.
	 *
	 * @return bool
	 */
	public function add( $from, $to ) {
		if ( $this->has( $from, $to ) ) {
			return false;
		}

		return $this->db->insert(
			$this->db->mb_relationships,
			array(
				'from' => $from,
				'to'   => $to,
				'type' => $this->id,
			),
			array(
				'%d',
				'%d',
				'%s',
			)
		);
	}

	/**
	 * Delete a relationship for 2 objects.
	 *
	 * @param int $from From object ID.
	 * @param int $to   To object ID.
	 *
	 * @return bool
	 */
	public function delete( $from, $to ) {
		if ( ! $this->has( $from, $to ) ) {
			return false;
		}

		return $this->db->delete(
			$this->db->mb_relationships,
			array(
				'from' => $from,
				'to'   => $to,
				'type' => $this->id,
			)
		);
	}

	/**
	 * Get relationship object types.
	 *
	 * @param string $side "from" or "to".
	 *
	 * @return string
	 */
	public function get_object_type( $side ) {
		return $this->$side['object_type'];
	}

	/**
	 * Check if the relationship has an object type on either side.
	 *
	 * @param mixed $type Object type.
	 *
	 * @return bool
	 */
	public function has_object_type( $type ) {
		return $type === $this->get_object_type( 'from' ) || $type === $this->get_object_type( 'to' );
	}

	/**
	 * Get the database ID field of "from" or "to" object.
	 *
	 * @param string $side "from" or "to".
	 *
	 * @return string
	 */
	public function get_db_field( $side ) {
		$key = $side . '_object';

		return $this->$key->get_db_field();
	}

	/**
	 * Setup hooks to create meta boxes for relationships, using Meta Box API.
	 */
	public function init() {
		add_filter( 'rwmb_meta_boxes', array( $this, 'register_meta_boxes' ) );
		
		$this->from_type = $this->settings['from']['object_type'];
		$this->to_type = $this->settings['to']['object_type'];
		
		switch ( $this->from_type ) {
			case 'post':
				$post_type = $this->settings['from']['post_type'];
				add_filter( "manage_{$post_type}_posts_columns", array( $this, 'to_columns' ) );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'post_to_column_data' ), 10, 2 );
				break;
				
			case 'term':
				$taxonomy = $this->settings['from']['taxonomy'];
				add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'to_columns' ) );
				add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'term_to_column_data' ), 10, 3 );
				break;
		}
		
		switch ( $this->to_type ) {
			case 'post':
				$post_type = $this->settings['to']['post_type'];
				add_filter( "manage_{$post_type}_posts_columns", array( $this, 'from_columns' ) );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'post_from_column_data' ), 10, 2 );
				break;
				
			case 'term':
				$taxonomy = $this->settings['to']['taxonomy'];
				add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'from_columns' ) );
				break;
		}
		// echo '<pre>'; print_r( $this->settings ); echo '</pre>';
	}
	
	public function from_columns( $columns ) {
		$columns[ $this->settings['id'] . '_from'] = $this->settings['meta_box']['label'] . __( 'From', 'mb-relationships' );
		return $columns;
	}
	
	protected function get_column_data( $object_id, $object_type, $direction = 'from' ) {
		$output = '';
		switch ( $object_type ) {
			case 'post':
				$related = get_posts( array(
					'relationship' => array(
						'id'       => $this->settings['id'],
						$direction => $object_id,
					),
					'nopaging'     => true,
					'fields'       => 'ids',
				) );
				if ( $related ) {
					$output .= '<ul>';
					foreach ( $related as $value ) {
						$output .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_permalink( $value ) ), esc_html( get_the_title( $value ) ) );
					}
					$output .= '</ul>';
				}
				break;
			
			case 'term':
				$related = get_terms( array(
					'hide_empty'   => false,
					'relationship' => array(
						'id'       => $this->settings['id'],
						$direction => $object_id,
					),
				) );
				if ( $related ) {
					$output .= '<ul>';
					foreach ( $related as $term ) {
						$output .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_term_link( $term ) ), esc_html( $term->name ) );
					}
					$output .= '</ul>';
				}
				break;
		}
		
		return $output;
	}
	
	public function post_from_column_data( $column_name, $post_id ) {
		if ( $this->settings['id'] . '_from' !== $column_name ) {
			return;
		}
		
		echo $this->get_column_data( $post_id, $this->from_type, 'to' );
	}
	
	public function term_from_column_data( $content, $column_name, $term_id ) {
		if ( $this->settings['id'] . '_from' !== $column_name ) {
			return $content;
		}
		
		return $this->get_column_data( $term_id, $this->from_type, 'to' );
	}
	
	public function to_columns( $columns ) {
		$columns[ $this->settings['id'] . '_to'] = $this->settings['meta_box']['label'] . __( 'To', 'mb-relationships' );
		return $columns;
	}
	
	public function post_to_column_data( $column_name, $post_id ) {
		if ( $this->settings['id'] . '_to' !== $column_name ) {
			return;
		}
		
		echo $this->get_column_data( $post_id, $this->to_type, 'from' );
	}
	
	public function term_to_column_data( $content, $column_name, $term_id ) {
		if ( $this->settings['id'] . '_to' !== $column_name ) {
			return $content;
		}
		
		return $this->get_column_data( $term_id, $this->to_type, 'from' );
	}

	/**
	 * Register 2 meta boxes for "From" and "To" relationships.
	 *
	 * @param array $meta_boxes Meta boxes array.
	 *
	 * @return array
	 */
	public function register_meta_boxes( $meta_boxes ) {
		if ( ! $this->from['meta_box']['hidden'] ) {
			$meta_boxes[] = $this->parse_meta_box_from();
		}
		$meta_boxes[] = $this->parse_meta_box_to();

		return $meta_boxes;
	}

	/**
	 * Parse meta box for "from" object.
	 *
	 * @return array
	 */
	protected function parse_meta_box_from() {
		$field         = $this->to_object->get_field_settings( $this->to );
		$field['id']   = "{$this->id}_to";
		$field['name'] = $this->from['meta_box']['field_title'];

		$meta_box = array(
			'id'           => "{$this->id}_relationships_to",
			'title'        => $this->from['meta_box']['title'],
			'storage_type' => 'relationships_table',
			'fields'       => array( $field ),
		);
		$meta_box = array_merge( $meta_box, $this->from_object->get_meta_box_settings( $this->from ) );

		return $meta_box;
	}

	/**
	 * Parse meta box for "to" object.
	 *
	 * @return array
	 */
	protected function parse_meta_box_to() {
		$field         = $this->from_object->get_field_settings( $this->from );
		$field['id']   = "{$this->id}_from";
		$field['name'] = $this->to['meta_box']['field_title'];

		$meta_box = array(
			'id'           => "{$this->id}_relationships_from",
			'title'        => $this->to['meta_box']['title'],
			'storage_type' => 'relationships_table',
			'fields'       => array( $field ),
		);
		$meta_box = array_merge( $meta_box, $this->to_object->get_meta_box_settings( $this->to ) );

		return $meta_box;
	}
}
