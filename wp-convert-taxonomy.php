<?php
/*
Plugin Name: Convert Taxonomy Tools
Version: 0.1
Plugin URI: http://redmine.beapi.fr/projects/show/simple-custom-type
Description: A small WordPress plugin that allow to convert a taxonomy to an another taxonomy.
Author: Amaury Balmer
Author URI: http://www.beapi.fr

----

Copyright 2012 Amaury Balmer (amaury@beapi.fr)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

add_action( 'plugins_loaded', 'init_convert_taxonomy', 11 );
function init_convert_taxonomy() {
	global $convert_taxonomy;
	
	// Load translations
	load_plugin_textdomain ( 'wp-convert-taxonomy', false, basename(rtrim(dirname(__FILE__), '/')) . '/languages' );

	if ( is_admin() && !class_exists('SimpleTaxonomy_Admin_Conversion') ) {
		$convert_taxonomy = new ConvertTaxonomy_Admin();
	}
}

class ConvertTaxonomy_Admin {
	/**
	 * Constructor
	 *
	 */
	function __construct() {
		global $messages;
		
		// Add message for conversion
		$messages[9992] = __('Item(s) converted on another taxonomy with success.', 'wp-convert-taxonomy');
		
		// Add action on edit tags page
		add_filter( 'admin_footer', array(&$this, 'addActions') );
		add_action( 'admin_init', array(&$this, 'listenConversion' ) );
	}
	
	/**
	 * Listen POST datas for make bulk terms conversion to new taxonomy
	 */
	function listenConversion() {
		global $pagenow, $wpdb, $taxnow;
		
		if ( $pagenow != 'edit-tags.php' ) 
			return false;
		
		if ( isset($_POST['taxonomy']) && isset($_POST['action']) && substr($_POST['action'], 0, strlen('convert_taxo')) == 'convert_taxo' ) {
			check_admin_referer( 'bulk-tags' );
			
			// Source taxo
			$source_taxo = get_taxonomy( $taxnow );
			if ( !current_user_can( $source_taxo->cap->manage_terms ) )
				wp_die( __( 'Cheatin&#8217; uh?' ) );
			
			// Destination taxo
			$destination_taxo = get_taxonomy( substr($_POST['action'], strlen('convert_taxo')+1) );
			if ( !current_user_can( $destination_taxo->cap->manage_terms ) )
				wp_die( __( 'Cheatin&#8217; uh?' ) );
			
			foreach ( (array) $_REQUEST['delete_tags'] as $tag_ID ) {
				$tag_ID = (int) $tag_ID;
				
				// Get objects for current term/taxo
				$objects = get_objects_in_term( (int) $tag_ID, $source_taxo->name );
				if ( empty($objects) ) { // No relations with this term, make the update directly in DB !
					// Change the taxonomy for this term_id/taxo
					$wpdb->update($wpdb->term_taxonomy, array('taxonomy' => $destination_taxo->name), array('term_id' => $tag_ID, 'taxonomy' => $source_taxo->name) );
				} else {
					// Get term detail 
					$term = get_term($tag_ID, $source_taxo->name);
					
					// Keep parent if destination taxo is hierarchical
					$parent = ( $destination_taxo->hierarchical == true ) ? $term->parent : 0;
					
					// Todo : How manage the new term ID for parent ?
					
					// Insert the term for the new taxo
					$new_term = wp_insert_term( $term->name, $destination_taxo->name, array('alias_of' => $term->alias_of, 'description' => $term->description, 'parent' => $parent, 'slug' => $term->slug) );
					if ( is_wp_error($new_term) || !is_array($new_term) ) {
						// A term with the name and same parent already exist on the target destination ?
						// Probably need to merge to this already term and delete source term... 
						
						// Term exist ?
						$term = term_exists( $term->name, $destination_taxo->name, $parent );
						if ( $term == false )
							continue; // WTF?
						elseif ( is_int($term) ) 
							$new_term_id = $term;
						elseif ( is_object($term) && isset($term->term_id) )
							$new_term_id = $term->term_id;
						elseif ( is_array($term) && isset($term['term_id']) )
							$new_term_id = $term['term_id'];
						else
							continue; // WTF?
					} else {
						$new_term_id = $new_term['term_id']; // Probably the same as tag_ID...
					}
					
					// Skip if invalid new term ID ?
					if ( (int) $new_term_id == 0 )
						continue; // WTF?
					
					// Set relation for new term/taxo
					foreach( $objects as $object_id ) {
						wp_set_object_terms( (int) $object_id, (int) $new_term_id, $destination_taxo->name, true);
					}
					
					// Remove old term
					wp_delete_term( $tag_ID, $source_taxo->name );
				}
			}
			
			$location = 'edit-tags.php?taxonomy=' . $taxnow;
			if ( 'post' != $post_type )
				$location .= '&post_type=' . $post_type;
			if ( $referer = wp_get_referer() ) {
				if ( false !== strpos( $referer, 'edit-tags.php' ) )
					$location = $referer;
			}
			
			$location = add_query_arg( 'message', 9992, $location );
			wp_redirect( $location );
			exit;
		}
	}
	
	/**
	 * Add JS on footer WP Admin for add option in select bulk action list
	 */
	function addActions() {
		global $pagenow;
		
		if ( $pagenow == 'edit-tags.php' ) {
			?>
			<script type="text/javascript">
				<?php foreach( get_taxonomies( array( 'show_ui' => true, 'public' => true ), 'objects' ) as $taxonomy ) :
					if ( $taxonomy->name == $_GET['taxonomy'] ) continue; // Not itself...
					if ( !current_user_can( $taxonomy->cap->manage_terms ) ) continue; // User can ?
					?>
					jQuery('div.actions select').append('<option value="convert_taxo-<?php echo $taxonomy->name; ?>"><?php echo esc_js(sprintf(__('Convert to %s', 'wp-convert-taxonomy'), $taxonomy->labels->name)); ?></option>');
				<?php endforeach; ?>
			</script>
			<?php
		}
	}
}
?>