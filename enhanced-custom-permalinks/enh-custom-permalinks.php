<?php
/*
Plugin Name: Enhanced Custom Permalinks
Plugin URI: http://wordpress.org/plugins/enhanced-custom-permalinks
Description: Set custom permalinks on a per-post basis
Version: 0.1.1
Author: Tor N. Johnson
Author URI: http://www.wordpress.org/kasigi/
*/

/*  

    This is a fork of the earlier Custom Permalinks plugin by Michael Tyson (http://wordpress.org/plugins/custom-permalinks/).
    
*/


/**
 ** Actions and filters
 **
 **/

/**
 * Filter to replace the post permalink with the custom one
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_post_link($permalink, $post) {
	$custom_permalink = get_post_meta( $post->ID, 'custom_permalink', true );
	if ( $custom_permalink ) {
		return get_home_url()."/".$custom_permalink;
	}
	
	return $permalink;
}


/**
 * Filter to replace the page permalink with the custom one
 *
 * @package CustomPermalinks
 * @since 0.4
 */
function custom_permalinks_page_link($permalink, $page) {
	$custom_permalink = get_post_meta( $page, 'custom_permalink', true );
	if ( $custom_permalink ) {
		return get_home_url()."/".$custom_permalink;
	}
	
	return $permalink;
}


/**
 * Filter to replace the term permalink with the custom one
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_term_link($permalink, $term) {
	$table = get_option('custom_permalink_table');
	if ( is_object($term) ) $term = $term->term_id;
	
	$custom_permalink = custom_permalinks_permalink_for_term($term);
	
	if ( $custom_permalink ) {
		return get_home_url()."/".$custom_permalink;
	}
	
	return $permalink;
}


/**
 * Action to redirect to the custom permalink
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_redirect() {
	
	// Get request URI, strip parameters
	$url = parse_url(get_bloginfo('url')); 
	$url = isset($url['path']) ? $url['path'] : '';
	$request = ltrim(substr($_SERVER['REQUEST_URI'], strlen($url)),'/');
	if ( ($pos=strpos($request, "?")) ) $request = substr($request, 0, $pos);
	
	global $wp_query;
	
	$custom_permalink = '';
	$original_permalink = '';

	// If the post/tag/category we're on has a custom permalink, get it and check against the request
	if ( is_single() || is_page() ) {
		$post = $wp_query->post;
		$custom_permalink = get_post_meta( $post->ID, 'custom_permalink', true );
		$original_permalink = ( $post->post_type == 'page' ? custom_permalinks_original_page_link( $post->ID ) : custom_permalinks_original_post_link( $post->ID ) );
	} else if ( is_tag() || is_category() ) {
		$theTerm = $wp_query->get_queried_object();
		$custom_permalink = custom_permalinks_permalink_for_term($theTerm->term_id);
		$original_permalink = (is_tag() ? custom_permalinks_original_tag_link($theTerm->term_id) :
							   			  custom_permalinks_original_category_link($theTerm->term_id));
	}

	if ( $custom_permalink && 
			(substr($request, 0, strlen($custom_permalink)) != $custom_permalink ||
			 $request == $custom_permalink."/" ) ) {
		// Request doesn't match permalink - redirect
		$url = $custom_permalink;

		if ( substr($request, 0, strlen($original_permalink)) == $original_permalink &&
				trim($request,'/') != trim($original_permalink,'/') ) {
			// This is the original link; we can use this url to derive the new one
			$url = preg_replace('@//*@', '/', str_replace(trim($original_permalink,'/'), trim($custom_permalink,'/'), $request));
			$url = preg_replace('@([^?]*)&@', '\1?', $url);
		}
		
		// Append any query compenent
		$url .= strstr($_SERVER['REQUEST_URI'], "?");
		
		wp_redirect( get_home_url()."/".$url, 301 );
		exit();
	}	
}


/*
Utility Function to get a list of ALL non-trash and non-menu posts that have the same custom permalink
*/

function custom_permalinks_content_list_verify($query,$thisPostID){
    global $wpdb;

    // Get request URI, strip parameters and /'s
	$request_noslash = preg_replace('@/+@','/', trim($query, '/'));

    if(isset($_GET['fp']) && intval($_GET['fp'])!=0){
        $forcematch = " AND $wpdb->posts.ID = ".intval($_GET['fp'])." ";   
    }else{
        $forcematch = "";
    }
    
    if(isset($_GET['preview_id']) && intval($_GET['preview_id'])!=0){
        $forcematch = " AND $wpdb->posts.ID = ".intval($_GET['preview_id'])." ";   
    }
	if ( !$query ) return false;

    $sql = "SELECT $wpdb->posts.ID, $wpdb->postmeta.meta_value, $wpdb->posts.post_type FROM $wpdb->posts ".
        "LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id) WHERE ".
        "  meta_key = 'custom_permalink' AND ".
        "  meta_value != '' AND ".
        "  (LOWER(meta_value) = LOWER('%s') OR ".
        "    LOWER(meta_value) = LOWER('%s') ) ".
        "  AND post_status!=\"trash\" AND post_type != \"nav_menu_item\" ". $forcematch .
        " ORDER BY LENGTH(meta_value) DESC,
				  FIELD(post_status,\"publish\",\"private\",\"draft\",\"auto-draft\",\"inherit\"),
				  FIELD(post_type,\"post\",\"page\"),
				 $wpdb->posts.ID ASC ";

	$posts = $wpdb->get_results($wpdb->prepare($sql,$request_noslash,$request_noslash."/"));
    if($posts){
        foreach($posts as $aPost){
             if(isset($thisPostID)){
                if($aPost->ID != $thisPostID){
                $idList[]=$aPost->ID;         
                }
            }else{
                $idList[]=$aPost->ID;      
            }
            
        }
        if(count($idList)>0){
            
         return $idList;   
        }else{
         return false;
        }
        
    }else{
        return false;   
    }
    
}

/**
 * Filter to rewrite the query if we have a matching post
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_request($query) {
	global $wpdb;
	global $_CPRegisteredURL;
	
	// First, search for a matching custom permalink, and if found, generate the corresponding
	// original URL
	
	$originalUrl = NULL;
	
	// Get request URI, strip parameters and /'s
	$url = parse_url(get_bloginfo('url'));
	$url = isset($url['path']) ? $url['path'] : '';
	$request = ltrim(substr($_SERVER['REQUEST_URI'], strlen($url)),'/');
	$request = (($pos=strpos($request, '?')) ? substr($request, 0, $pos) : $request);
	$request_noslash = preg_replace('@/+@','/', trim($request, '/'));

    if(isset($_GET['fp']) && intval($_GET['fp'])!=0){
        $forcematch = " AND $wpdb->posts.ID = ".intval($_GET['fp'])." ";   
    }else{
        $forcematch = "";
    }
    
    if(isset($_GET['preview_id']) && intval($_GET['preview_id'])!=0){
        $forcematch = " AND $wpdb->posts.ID = ".intval($_GET['preview_id'])." ";   
    }
    
	if ( !$request ) return $query;
	
	$sql = "SELECT $wpdb->posts.ID, $wpdb->postmeta.meta_value, $wpdb->posts.post_type FROM $wpdb->posts ".
				"LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id) WHERE ".
				"  meta_key = 'custom_permalink' AND ".
				"  meta_value != '' AND ".
				"  (LOWER(meta_value) = LOWER('%s') OR ".
				"    LOWER(meta_value) = LOWER('%s') ) ".
				"  AND post_status!=\"trash\" AND post_type != \"nav_menu_item\" ". $forcematch .
				" ORDER BY LENGTH(meta_value) DESC,
				  FIELD(post_status,\"publish\",\"private\",\"draft\",\"auto-draft\",\"inherit\"),
				  FIELD(post_type,\"post\",\"page\"),
				 $wpdb->posts.ID ASC  LIMIT 1";

	$posts = $wpdb->get_results($wpdb->prepare($sql,$request_noslash,$request_noslash."/"));

	if ( $posts ) {
		// A post matches our request
	
		// Preserve this url for later if it's the same as the permalink (no extra stuff)
		if ( $request_noslash == trim($posts[0]->meta_value,'/') ) 
			$_CPRegisteredURL = $request;
			
		$originalUrl = 	preg_replace( '@/+@', '/', str_replace( trim( strtolower($posts[0]->meta_value),'/' ),
									( $posts[0]->post_type == 'page' ? 
											custom_permalinks_original_page_link($posts[0]->ID) 
											: custom_permalinks_original_post_link($posts[0]->ID) ),
								   strtolower($request_noslash) ) );
	}

	if ( $originalUrl === NULL ) {
	        
	    // See if any terms have a matching permalink
		$table = get_option('custom_permalink_table');

		if ( !$table ) return $query;

		foreach ( array_keys($table) as $permalink ) {
			if ( $permalink == substr($request_noslash, 0, strlen($permalink)) ||
			     $permalink == substr($request_noslash."/", 0, strlen($permalink)) ) {
				$term = $table[$permalink];
					
				// Preserve this url for later if it's the same as the permalink (no extra stuff)
				if ( $request_noslash == trim($permalink,'/') ) 
					$_CPRegisteredURL = $request;
				
				
				if ( $term['kind'] == 'category') {
					$originalUrl = str_replace(trim($permalink,'/'),
										       custom_permalinks_original_category_link($term['id']),
											   trim($request,'/'));
				} else {
					$originalUrl = str_replace(trim($permalink,'/'),
										       custom_permalinks_original_tag_link($term['id']),
											   trim($request,'/'));
				}
			}
		}
	}
		
	if ( $originalUrl !== NULL ) {
		$originalUrl = str_replace('//', '/', $originalUrl);
		
		if ( ($pos=strpos($_SERVER['REQUEST_URI'], '?')) !== false ) {
			$queryVars = substr($_SERVER['REQUEST_URI'], $pos+1);
			$originalUrl .= (strpos($originalUrl, '?') === false ? '?' : '&') . $queryVars;
		}
		
		// Now we have the original URL, run this back through WP->parse_request, in order to
		// parse parameters properly.  We set $_SERVER variables to fool the function.
		$oldRequestUri = $_SERVER['REQUEST_URI']; $oldQueryString = $_SERVER['QUERY_STRING'];
		$_SERVER['REQUEST_URI'] = '/'.ltrim($originalUrl,'/');
		$_SERVER['QUERY_STRING'] = (($pos=strpos($originalUrl, '?')) !== false ? substr($originalUrl, $pos+1) : '');
		parse_str($_SERVER['QUERY_STRING'], $queryArray);
		$oldValues = array();
		if ( is_array($queryArray) )
		foreach ( $queryArray as $key => $value ) {
			$oldValues[$key] = $_REQUEST[$key];
			$_REQUEST[$key] = $_GET[$key] = $value;
		}

		// Re-run the filter, now with original environment in place
		remove_filter( 'request', 'custom_permalinks_request', 10, 1 );
		global $wp;
		$wp->parse_request();
		$query = $wp->query_vars;
		add_filter( 'request', 'custom_permalinks_request', 10, 1 );
		
		// Restore values
		$_SERVER['REQUEST_URI'] = $oldRequestUri; $_SERVER['QUERY_STRING'] = $oldQueryString;
		foreach ( $oldValues as $key => $value ) {
			$_REQUEST[$key] = $value;
		}
	}

	return $query;
}

/**
 * Filter to handle trailing slashes correctly
 *
 * @package CustomPermalinks
 * @since 0.3
 */
function custom_permalinks_trailingslash($string, $type) {     
	global $_CPRegisteredURL;

	$url = parse_url(get_bloginfo('url'));

	if(!isset($url['path'])) {
		$url['path'] = '';
	}

	$request = ltrim(substr($string, strlen($url['path'])),'/');

	if ( !trim($request) ) return $string;

	if ( isset($_CPRegisteredURL) && trim($_CPRegisteredURL,'/') == trim($request,'/') ) {
		return ($string[0] == '/' ? '/' : '') . trailingslashit($url['path']) . $_CPRegisteredURL;
	}
	return $string;
}

/**
 ** Administration
 **
 **/
 
/**
 * Per-post/page options (Wordpress > 2.9)
 *
 * @package CustomPermalinks
 * @since 0.6
 */
function custom_permalink_get_sample_permalink_html($html, $id, $new_title, $new_slug) {
    $permalink = get_post_meta( $id, 'custom_permalink', true );
	$post = &get_post($id);
	
	ob_start();
	?>
	<?php custom_permalinks_form($permalink, ($post->post_type == "page" ? custom_permalinks_original_page_link($id) : custom_permalinks_original_post_link($id)), false); ?>
	<?php
	$content = ob_get_contents();
	ob_end_clean();
    
    if ( 'publish' == $post->post_status ) {
        $view_post = 'page' == $post->post_type ? __('View Page') : __('View Post');
	}
	
	if ( preg_match("@view-post-btn.*?href='([^']+)'@s", $html, $matches) ) {
	    $permalink = $matches[1];
    } else {
        list($permalink, $post_name) = get_sample_permalink($post->ID, $new_title, $new_slug);
        if ( false !== strpos($permalink, '%postname%') || false !== strpos($permalink, '%pagename%') ) {
            $permalink = str_replace(array('%pagename%','%postname%'), $post_name, $permalink);
        }
    }
	
	return '<strong>' . __('Permalink:') . "</strong>\n" . $content . 
	     ( isset($view_post) ? "<span id='view-post-btn'><a href='$permalink' class='button' target='_blank'>$view_post</a></span>\n" : "" );
}


/**
 * Per-post options (Wordpress < 2.9)
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_post_options() {
	global $post;
	$post_id = $post;
	if (is_object($post_id)) {
		$post_id = $post_id->ID;
	}
	
	$permalink = get_post_meta( $post_id, 'custom_permalink', true );
	
	?>
	<div class="postbox closed">
	<h3><?php _e('Custom Permalink', 'custom-permalink') ?></h3>
	<div class="inside">
	<?php custom_permalinks_form($permalink, custom_permalinks_original_post_link($post_id)); ?>
	</div>
	</div>
	<?php
}


/**
 * Per-page options (Wordpress < 2.9)
 *
 * @package CustomPermalinks
 * @since 0.4
 */
function custom_permalinks_page_options() {
	global $post;
	$post_id = $post;
	if (is_object($post_id)) {
		$post_id = $post_id->ID;
	}
	
	$permalink = get_post_meta( $post_id, 'custom_permalink', true );
	
	?>
	<div class="postbox closed">
	<h3><?php _e('Custom Permalink', 'custom-permalink') ?></h3>
	<div class="inside">
	<?php custom_permalinks_form($permalink, custom_permalinks_original_page_link($post_id)); ?>
	</div>
	</div>
	<?php
}


/**
 * Per-category/tag options
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_term_options($object) {
	$permalink = custom_permalinks_permalink_for_term($object->term_id);
	
	if ( $object->term_id ) {
    	$originalPermalink = ($object->taxonomy == 'post_tag' ? 
    								custom_permalinks_original_tag_link($object->term_id) :
    								custom_permalinks_original_category_link($object->term_id) );
    }
    	
	custom_permalinks_form($permalink, $originalPermalink);

	// Move the save button to above this form
	wp_enqueue_script('jquery');
	?>
	<script type="text/javascript">
	jQuery(document).ready(function() {
		var button = jQuery('#custom_permalink_form').parent().find('.submit');
		button.remove().insertAfter(jQuery('#custom_permalink_form'));
	});
	</script>
	<?php
}

/**
 * Helper function to render form
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_form($permalink, $original="", $renderContainers=true) {
    
	?>
	<input value="true" type="hidden" name="custom_permalinks_edit" />
	<input value="<?php echo htmlspecialchars(urldecode($permalink)) ?>" type="hidden" name="custom_permalink" id="custom_permalink" />
	
	<?php if ( $renderContainers ) : ?>
	<table class="form-table" id="custom_permalink_form">
	<tr>
		<th scope="row"><?php _e('Custom Permalink', 'custom-permalink') ?></th>
		<td>
	<?php endif; ?>
			<?php echo get_home_url() ?>/
			<input type="text" class="text" value="<?php echo htmlspecialchars($permalink ? urldecode($permalink) : urldecode($original)) ?>" 
				style="width: 250px; <?php if ( !$permalink ) echo 'color: #ddd;' ?>"
			 	onfocus="if ( this.style.color = '#ddd' ) { this.style.color = '#000'; }" 
				onblur="document.getElementById('custom_permalink').value = this.value; if ( this.value == '' || this.value == '<?php echo htmlspecialchars(urldecode($original)) ?>' ) { this.value = '<?php echo htmlspecialchars(urldecode($original)) ?>'; this.style.color = '#ddd'; }"/>
	<?php if ( $renderContainers ) : ?>				
			<br />
			<small><?php _e('Leave blank to disable', 'custom-permalink') ?></small>
			
		</td>
	</tr>
	</table>
	<?php
	endif;

}


function custom_permalink_conflict_notice(){
    global $sharedConflictingPermalinks;
     ?>
    <div class="updated">
        <p><strong>Warning!</strong> This custom permalink is used on multiple posts/pages: <?php foreach($sharedConflictingPermalinks as $sLink){ echo " <a href=\"".get_edit_post_link($sLink)."\" target=\"blank\">$sLink</a> ";}?></p>
    </div>
    <?php
} // custom_permalink_conflict_notice

function custom_permalink_trigger_conflict_check() {
    global $sharedConflictingPermalinks;
    $permalink = get_post_meta( get_the_ID(), 'custom_permalink', true );
    $sharedConflictingPermalinks = custom_permalinks_content_list_verify($permalink,get_the_ID());
    if($sharedConflictingPermalinks != false){
        add_action( 'admin_notices', 'custom_permalink_conflict_notice',10,$sharedLinks);
     }
}//custom_permalink_trigger_conflict_check

add_action('admin_head','custom_permalink_trigger_conflict_check');


/**
 * Save per-post options
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_save_post($id) {
	if ( !isset($_REQUEST['custom_permalinks_edit']) ) return;
	
	delete_post_meta( $id, 'custom_permalink' );
	
	$original_link = custom_permalinks_original_post_link($id);
	$permalink_structure = get_option('permalink_structure');
	
	if ( $_REQUEST['custom_permalink'] && $_REQUEST['custom_permalink'] != $original_link ) {
	    add_post_meta( $id, 'custom_permalink', str_replace('%2F', '/', urlencode(ltrim(stripcslashes($_REQUEST['custom_permalink']),"/"))) );
	}
}


/**
 * Save per-tag options
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_save_tag($id) {
	if ( !isset($_REQUEST['custom_permalinks_edit']) || isset($_REQUEST['post_ID']) ) return;
	$newPermalink = ltrim(stripcslashes($_REQUEST['custom_permalink']),"/");
	
	if ( $newPermalink == custom_permalinks_original_tag_link($id) )
		$newPermalink = ''; 
	
	$term = get_term($id, 'post_tag');
	custom_permalinks_save_term($term, str_replace('%2F', '/', urlencode($newPermalink)));
}

/**
 * Save per-category options
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_save_category($id) {
	if ( !isset($_REQUEST['custom_permalinks_edit']) || isset($_REQUEST['post_ID']) ) return;
	$newPermalink = ltrim(stripcslashes($_REQUEST['custom_permalink']),"/");
	
	if ( $newPermalink == custom_permalinks_original_category_link($id) )
		$newPermalink = ''; 
	
	$term = get_term($id, 'category');
	custom_permalinks_save_term($term, str_replace('%2F', '/', urlencode($newPermalink)));
}

/**
 * Save term (common to tags and categories)
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_save_term($term, $permalink) {
	
	custom_permalinks_delete_term($term->term_id);
	$table = get_option('custom_permalink_table');
	if ( $permalink )
		$table[$permalink] = array(
			'id' => $term->term_id, 
			'kind' => ($term->taxonomy == 'category' ? 'category' : 'tag'),
			'slug' => $term->slug);

	update_option('custom_permalink_table', $table);
}

/**
 * Delete post
 *
 * @package CustomPermalinks
 * @since 0.7.14
 * @author Piero <maltesepiero@gmail.com>
 */
function custom_permalinks_delete_permalink( $id ){
	global $wpdb;
	$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->postmeta." WHERE meta_key = 'custom_permalink' AND post_id = '%s';",$id));
}

/**
 * Delete term
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_delete_term($id) {
	
	$table = get_option('custom_permalink_table');
	if ( $table )
	foreach ( $table as $link => $info ) {
		if ( $info['id'] == $id ) {
			unset($table[$link]);
			break;
		}
	}
	
	update_option('custom_permalink_table', $table);
}

/**
 * Options page
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_options_page() {
	
	// Handle revert
	if ( isset($_REQUEST['revertit']) && isset($_REQUEST['revert']) ) {
		check_admin_referer('custom-permalinks-bulk');
		foreach ( (array)$_REQUEST['revert'] as $identifier ) {
			list($kind, $id) = explode('.', $identifier);
			switch ( $kind ) {
				case 'post':
				case 'page':
					delete_post_meta( $id, 'custom_permalink' );
					break;
				case 'tag':
				case 'category':
					custom_permalinks_delete_term($id);
					break;
			}
		}
		
		// Redirect
		$redirectUrl = $_SERVER['REQUEST_URI'];
		?>
		<script type="text/javascript">
		document.location = '<?php echo $redirectUrl ?>'
		</script>
		<?php ;
	}
	
	?>
	<div class="wrap">
	<h2><?php _e('Custom Permalinks', 'custom-permalinks') ?></h2>
	
	<form method="post" action="<?php echo $_SERVER['REQUEST_URI'] ?>">
	<?php wp_nonce_field('custom-permalinks-bulk') ?>
	
	<div class="tablenav">
	<div class="alignleft">
	<input type="submit" value="<?php _e('Revert', 'custom-permalinks'); ?>" name="revertit" class="button-secondary delete" />
	</div>
	<br class="clear" />
	</div>
	<br class="clear" />
	<table class="widefat">
		<thead>
		<tr>
			<th scope="col" class="check-column"><input type="checkbox" /></th>
			<th scope="col"><?php _e('Title', 'custom-permalinks') ?></th>
			<th scope="col"><?php _e('Type', 'custom-permalinks') ?></th>
			<th scope="col"><?php _e('Permalink', 'custom-permalinks') ?></th>
		</tr>
		</thead>
		<tbody>
	<?php
	$rows = custom_permalinks_admin_rows();
	foreach ( $rows as $row ) {
        $duplicateWarning = "";
        $duplicateWarningColor = "";
        if($row['duplicates']==true){
                $duplicateWarning = " <strong>[duplicate custom permalinks detected]</strong>";
                $duplicateWarningColor = " style=\"background-color:rgb(255,245,146);\"";
        }
		?>
		<tr valign="top">
		<th scope="row" class="check-column"><input type="checkbox" name="revert[]" value="<?php echo $row['id'] ?>" /></th>
		<td><strong><a class="row-title" href="<?php echo htmlspecialchars($row['editlink']) ?>"><?php echo htmlspecialchars($row['title']) ?></a></strong></td>
		<td><?php echo htmlspecialchars($row['type']) ?></td>
		<td <?php echo $duplicateWarningColor; ?>><a href="<?php echo $row['permalink'] ?>" target="_blank" title="Visit <?php echo htmlspecialchars($row['title']) ?>">
			<?php echo htmlspecialchars(urldecode($row['permalink'])). $duplicateWarning; ?> 
			</a>
		</td>
		</tr>
		<?php
	}
	?>
	</tbody>
	</table>
	</form>
	</div>
	<?php
}

/**
 * Get rows for management view
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_admin_rows() {
	$rows = array();
	
	// List tags/categories
	$table = get_option('custom_permalink_table');
	if ( $table && is_array($table) ) {
		foreach ( $table as $permalink => $info ) {
			$row = array();
			$term = get_term($info['id'], ($info['kind'] == 'tag' ? 'post_tag' : 'category'));
			$row['id'] = $info['kind'].'.'.$info['id'];
			$row['permalink'] = get_home_url()."/".$permalink;
			$row['type'] = ucwords($info['kind']);
			$row['title'] = $term->name;
			$row['editlink'] = ( $info['kind'] == 'tag' ? 'edit-tags.php?action=edit&taxonomy=post_tag&tag_ID='.$info['id'] : 'edit-tags.php?action=edit&taxonomy=category&tag_ID='.$info['id'] );
			$rows[] = $row;
		}
	}
	
	// List posts/pages
	global $wpdb;
   
    
    var_dump($multiPermalinkSet);
	$query = "
SELECT $wpdb->posts.*, doubler.uses FROM $wpdb->posts LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID =
$wpdb->postmeta.post_id) 
LEFT JOIN (
SELECT TRIM(BOTH '/' FROM $wpdb->postmeta.meta_value) as shortpermalinkD, count(meta_value) AS uses FROM $wpdb->posts 
LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id) 
WHERE meta_key = 'custom_permalink' 
	AND   meta_value != '' 
	AND post_status!=\"trash\" AND post_type != \"nav_menu_item\"  
	GROUP BY shortpermalinkD
	HAVING uses > 1
) AS doubler ON doubler.shortpermalinkD = TRIM(BOTH '/' FROM $wpdb->postmeta.meta_value)
            WHERE $wpdb->postmeta.meta_key = 'custom_permalink' 
            AND $wpdb->postmeta.meta_value != ''
            AND post_status!=\"trash\" 
            AND post_type != \"nav_menu_item\" ;";
	$posts = $wpdb->get_results($query);
	foreach ( $posts as $post ) {
		$row = array();
		$row['id'] = 'post.'.$post->ID;
		$row['permalink'] = get_permalink($post->ID);
		$row['type'] = ucwords( $post->post_type );
		$row['title'] = $post->post_title;
		$row['editlink'] = 'post.php?action=edit&post='.$post->ID;
        
        if($post->uses > 0){
            $row['duplicates'] = true;
        }else{
            $row['duplicates'] = false;
        }
        
		$rows[] = $row;
	}
	
	return $rows;
}


/**
 * Get original permalink for post
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_original_post_link($post_id) {
	remove_filter( 'post_link', 'custom_permalinks_post_link', 10, 2 ); // original hook
	remove_filter( 'post_type_link', 'custom_permalinks_post_link', 10, 2 );
	$originalPermalink = ltrim(str_replace(get_option('home'), '', get_permalink( $post_id )), '/');
	add_filter( 'post_link', 'custom_permalinks_post_link', 10, 2 ); // original hook
	add_filter( 'post_type_link', 'custom_permalinks_post_link', 10, 2 );
	return $originalPermalink;
}

/**
 * Get original permalink for page
 *
 * @package CustomPermalinks
 * @since 0.4
 */
function custom_permalinks_original_page_link($post_id) {
	remove_filter( 'page_link', 'custom_permalinks_page_link', 10, 2 );
	remove_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );
	$originalPermalink = ltrim(str_replace(get_home_url(), '', get_permalink( $post_id )), '/');
	add_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );
	add_filter( 'page_link', 'custom_permalinks_page_link', 10, 2 );
	return $originalPermalink;
}


/**
 * Get original permalink for tag
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_original_tag_link($tag_id) {
	remove_filter( 'tag_link', 'custom_permalinks_term_link', 10, 2 );
	remove_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );
	$originalPermalink = ltrim(str_replace(get_home_url(), '', get_tag_link($tag_id)), '/');
	add_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );
	add_filter( 'tag_link', 'custom_permalinks_term_link', 10, 2 );
	return $originalPermalink;
}

/**
 * Get original permalink for category
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_original_category_link($category_id) {
	remove_filter( 'category_link', 'custom_permalinks_term_link', 10, 2 );
	remove_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );
	$originalPermalink = ltrim(str_replace(get_home_url(), '', get_category_link($category_id)), '/');
	add_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );
	add_filter( 'category_link', 'custom_permalinks_term_link', 10, 2 );
	return $originalPermalink;
}

/**
 * Get permalink for term
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_permalink_for_term($id) {
	$table = get_option('custom_permalink_table');
	if ( $table )
	foreach ( $table as $link => $info ) {
		if ( $info['id'] == $id ) {
			return $link;
		}
	}
	return false;
}

/**
 * Set up administration
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_setup_admin() {
	add_management_page( 'Custom Permalinks', 'Custom Permalinks', 5, 'custom_permalinks', 'custom_permalinks_options_page' );
	if ( is_admin() )
		wp_enqueue_script('admin-forms');
}

if ( !function_exists("get_home_url") ) {
    function get_home_url() {
        return get_option('home');
    }
}



/*
If Codepress Admin Columns Plugin is present, add to custom columns option list

*/
function cac_register_custom_permalink_column( $columns ) {
 
    // Class name and absolute filepath of the custom column
    $columns['CPAC_Column_EnhCustomPermalink'] = plugin_dir_path( __FILE__ ) . '/class-column-EnhCustomPermalink.php';
 
    return $columns;
}

add_filter( 'cac/columns/custom/type=post', 'cac_register_custom_permalink_column',10,2 );
add_filter( 'cac/columns/custom/type=page', 'cac_register_custom_permalink_column',10,2 );

    



# Check whether we're running within the WP environment, to avoid showing errors like
# "Fatal error: Call to undefined function get_bloginfo() in C:\xampp\htdocs\custom-permalinks\custom-permalinks.php on line 753"
# and similar errors that occurs when the script is called directly to e.g. find out the full path.

if (function_exists("add_action") && function_exists("add_filter")) {
	add_action( 'template_redirect', 'custom_permalinks_redirect', 5 );
	add_filter( 'post_link', 'custom_permalinks_post_link', 10, 2 );
	add_filter( 'post_type_link', 'custom_permalinks_post_link', 10, 2 );
	add_filter( 'page_link', 'custom_permalinks_page_link', 10, 2 );
	add_filter( 'tag_link', 'custom_permalinks_term_link', 10, 2 );
	add_filter( 'category_link', 'custom_permalinks_term_link', 10, 2 );
	add_filter( 'request', 'custom_permalinks_request', 10, 1 );
	add_filter( 'user_trailingslashit', 'custom_permalinks_trailingslash', 10, 2 );

	if (function_exists("get_bloginfo")) {
		$v = explode('.', get_bloginfo('version'));
	}

	if ( $v[0] >= 2 ) {
	    add_filter( 'get_sample_permalink_html', 'custom_permalink_get_sample_permalink_html', 10, 4 );
	} else {
	    add_action( 'edit_form_advanced', 'custom_permalinks_post_options' );
	    add_action( 'edit_page_form', 'custom_permalinks_page_options' );
	}

	add_action( 'edit_tag_form', 'custom_permalinks_term_options' );
	add_action( 'add_tag_form', 'custom_permalinks_term_options' );
	add_action( 'edit_category_form', 'custom_permalinks_term_options' );
	add_action( 'save_post', 'custom_permalinks_save_post' );
	add_action( 'save_page', 'custom_permalinks_save_post' );
	add_action( 'edited_post_tag', 'custom_permalinks_save_tag' );
	add_action( 'edited_category', 'custom_permalinks_save_category' );
	add_action( 'create_post_tag', 'custom_permalinks_save_tag' );
	add_action( 'create_category', 'custom_permalinks_save_category' );
	add_action( 'delete_post', 'custom_permalinks_delete_permalink', 10);
	add_action( 'delete_post_tag', 'custom_permalinks_delete_term' );
	add_action( 'delete_post_category', 'custom_permalinks_delete_term' );
	add_action( 'admin_menu', 'custom_permalinks_setup_admin' );
}
?>
