<?php
/**
 * Deposit transaction and related support functions.
 *
 * @package HumCORE
 * @subpackage Deposits
 */

/**
 * Process the uploaded file:
 * Check for duplicate entries.
 * Make a usable unique filename.
 * Generate a thumb if necessary.
 * For this uploaded file create 2 objects in Fedora, 1 document in Solr and 2 posts.
 * Get the next 2 object id values for Fedora.
 * Prepare the metadata sent to Fedora and Solr.
 * Mint and reserve a DOI.
 * Determine post date, status and necessary activity.
 * Create XML needed for the fedora objects.
 * Create the aggregator post so that we can reference the ID in the Solr document.
 * Set object terms for subjects.
 * Add any new keywords and set object terms for tags.
 * Add to metadata and store in post meta.
 * Prepare an array of post data for the resource post.
 * Insert the resource post.
 * Extract text first if small. If Tika errors out we'll index without full text.
 * Index the deposit content and metadata in Solr.
 * Create the aggregator Fedora object along with the DC and RELS-EXT datastreams.
 * Upload the MODS file to the Fedora server temp file storage.
 * Create the descMetadata datastream for the aggregator object.
 * Upload the deposited file to the Fedora server temp file storage.
 * Create the CONTENT datastream for the resource object.
 * Upload the thumb to the Fedora server temp file storage if necessary.
 * Create the THUMB datastream for the resource object if necessary.
 * Add the activity entry for the author.
 * Publish the reserved DOI.
 * Notify provisional deposit review group for HC member deposits.
 * Re-index larger text based deposits in the background.
 */
function humcore_deposit_file() {

	if ( empty( $_POST ) ) {
		return false;
	}

	global $fedora_api, $solr_client;
	//$tika_client = \Vaites\ApacheTika\Client::make('localhost', 9998);
	$tika_client = \Vaites\ApacheTika\Client::make( '/srv/www/commons/current/vendor/tika/tika-app-1.16.jar' );     // app mode

	$upload_error_message = '';
	if ( empty( $_POST['selected_file_name'] ) ) {
		// Do something!
		$upload_error_message = __( 'No file was uploaded! Please press "Select File" and upload a file first.', 'humcore_domain' );
	} elseif ( 0 == $_POST['selected_file_size'] ) {
		$upload_error_message = sprintf( __( '%1$s appears to be empty, please choose another file.', 'humcore_domain' ), sanitize_file_name( $_POST['selected_file_name'] ) );
	}
	if ( ! empty( $upload_error_message ) ) {
		echo '<div id="message" class="info"><p>' . $upload_error_message . '</p></div>'; // XSS OK.
		return false;
	}

	/**
	 * Check for duplicate entries.
	 */
	$title_check = wp_strip_all_tags( stripslashes( $_POST['deposit-title-unchanged'] ) );
	$genre       = sanitize_text_field( $_POST['deposit-genre'] );
	if ( 'yes' === $_POST['deposit-on-behalf-flag'] ) {
		$group_id = intval( $_POST['deposit-committee'] );
	} else {
		$group_id = '';
	}
	$user        = get_user_by( 'login', sanitize_text_field( $_POST['deposit-author-uni'] ) );
	$title_match = humcore_get_deposit_by_title_genre_and_author( $title_check, $genre, $group_id, $user );
	if ( ! empty( $title_match ) ) {
		echo '<div id="message" class="info">';
		if ( ! empty( $group_id ) ) {
			$group            = groups_get_group( array( 'group_id' => $group_id ) );
			$sentence_subject = sprintf( '[ %s ]', $group->name );
		} else {
			$sentence_subject = 'You';
		}
		echo sprintf(
			'Wait a minute! %1$s deposited another %2$s entitled <a onclick="target=%3$s" href="%4$s/deposits/item/%5$s">%6$s</a> %7$s ago.<br />Perhaps this is a duplicate deposit? If not, please change the title and click <b>Deposit</b> again.',
			$sentence_subject,
			strtolower( $genre ),
			"'blank'",
			HC_SITE_URL,
			$title_match->id,
			$title_match->title_unchanged,
			human_time_diff( strtotime( $title_match->record_creation_date ) )
		);
		echo '</div>';
		return false;
	}

	// Single file uploads at this point.
	$tempname             = sanitize_file_name( $_POST['selected_temp_name'] );
	$time                 = current_time( 'mysql' );
	$y                    = substr( $time, 0, 4 );
	$m                    = substr( $time, 5, 2 );
	$yyyy_mm              = "$y/$m";
	$fileloc              = $fedora_api->temp_dir . '/' . $yyyy_mm . '/' . $tempname;
	$filename             = strtolower( sanitize_file_name( $_POST['selected_file_name'] ) );
	$filesize             = sanitize_text_field( $_POST['selected_file_size'] );
	$renamed_file         = $fileloc . '.' . $filename;
	$mods_file            = $fileloc . '.MODS.' . $filename . '.xml';
	$filename_dir         = pathinfo( $renamed_file, PATHINFO_DIRNAME );
	$datastream_id        = 'CONTENT';
	$thumb_datastream_id  = 'THUMB';
	$generated_thumb_name = '';

	/**
	 * Make a usable unique filename.
	 */
	if ( file_exists( $fileloc ) ) {
		$file_rename_status = rename( $fileloc, $renamed_file );
	}
	// TODO handle file error.
	$check_filetype = wp_check_filetype( $filename, wp_get_mime_types() );
	$filetype       = $check_filetype['type'];

	//TODO fix thumbs if ( preg_match( '~^image/|/pdf$~', $check_filetype['type'] ) ) {
	if ( preg_match( '~^image/$~', $check_filetype['type'] ) ) {
		$thumb_image = wp_get_image_editor( $renamed_file );
		if ( ! is_wp_error( $thumb_image ) ) {
			$current_size = $thumb_image->get_size();
			$thumb_image->resize( 150, 150, false );
			$thumb_image->set_quality( 95 );
			$thumb_filename       = $thumb_image->generate_filename( 'thumb', $filename_dir . '/' . $yyyy_mm . '/', 'jpg' );
			$generated_thumb      = $thumb_image->save( $thumb_filename, 'image/jpeg' );
			$generated_thumb_path = $generated_thumb['path'];
			$generated_thumb_name = str_replace( $tempname . '.', '', $generated_thumb['file'] );
			$generated_thumb_mime = $generated_thumb['mime-type'];
		} else {
			echo 'Error - thumb_image : ' . esc_html( $thumb_image->get_error_code() ) . '-' . esc_html( $thumb_image->get_error_message() );
			humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - thumb_image : %1$s-%2$s', $thumb_image->get_error_code(), $thumb_image->get_error_message() ) );
		}
	}

	humcore_write_error_log( 'info', 'HumCORE deposit started' );
	humcore_write_error_log( 'info', 'HumCORE deposit - check_filetype ' . var_export( $check_filetype, true ) );
	if ( ! empty( $thumb_image ) ) {
		humcore_write_error_log( 'info', 'HumCORE deposit - thumb_image ' . var_export( $thumb_image, true ) );
	}

	/**
	 * For this uploaded file create 2 objects in Fedora, 1 document in Solr and 2 posts.
	 * Get the next 2 object id values for Fedora.
	 */
	$next_pids = $fedora_api->get_next_pid(
		array(
			'numPIDs'   => '2',
			'namespace' => $fedora_api->namespace,
		)
	);
	if ( is_wp_error( $next_pids ) ) {
		echo 'Error - next_pids : ' . esc_html( $next_pids->get_error_code() ) . '-' . esc_html( $next_pids->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - next_pids : %1$s-%2$s', $next_pids->get_error_code(), $next_pids->get_error_message() ) );
		return false;
	}

	/**
	 * Prepare the metadata to send to Fedore and Solr.
	 */
	$curr_val                          = $_POST;
	$metadata                          = prepare_user_entered_metadata( $user, $curr_val );
	$metadata['id']                    = $next_pids[0];
	$metadata['pid']                   = $next_pids[0];
	$metadata['creator']               = 'HumCORE';
	$metadata['submitter']             = $user->ID;
	$metadata['society_id']            = Humanities_Commons::$society_id;
	$metadata['member_of']             = $fedora_api->collection_pid;
	$metadata['record_content_source'] = 'HumCORE';
	$metadata['record_creation_date']  = gmdate( 'Y-m-d\TH:i:s\Z' );
	$metadata['record_change_date']    = gmdate( 'Y-m-d\TH:i:s\Z' );

	/**
	 * Mint and reserve a DOI.
	 */
	$creators = array();
	foreach ( $metadata['authors'] as $author ) {
		if ( ( in_array( $author['role'], array( 'creator', 'author', 'editor', 'translator' ) ) ) && ! empty( $author['fullname'] ) ) {
							$creators[] = $author['fullname'];
		}
	}
			$creator_list = implode( ',', $creators );

			$deposit_doi = humcore_create_handle(
				$metadata['title'],
				$next_pids[0],
				$creator_list,
				$metadata['genre'],
				$metadata['date_issued'],
				$metadata['publisher']
			);
	if ( ! $deposit_doi ) {
		$metadata['handle']      = sprintf( HC_SITE_URL . '/deposits/item/%s/', $next_pids[0] );
		$metadata['deposit_doi'] = ''; // Not stored in solr.
	} else {
		$metadata['handle']      = 'http://dx.doi.org/' . str_replace( 'doi:', '', $deposit_doi );
		$metadata['deposit_doi'] = $deposit_doi; // Not stored in solr.
	}

	/**
	 * Determine post date, status and necessary activity.
	 */
	$deposit_activity_needed = true;
	$deposit_review_needed   = false;
	$deposit_post_date       = ( new DateTime() )->format( 'Y-m-d H:i:s' );
	$deposit_post_status     = 'draft';
	if ( 'yes' === $metadata['embargoed'] ) {
		$deposit_post_status = 'future';
		$deposit_post_date   = date( 'Y-m-d H:i:s', strtotime( '+' . sanitize_text_field( $_POST['deposit-embargo-length'] ) ) );
	}
	if ( 'hcadmin' === $user->user_login ) {
		$deposit_activity_needed     = false;
		$deposit_post_date           = '';
				$deposit_post_status = 'publish';
	}

	//if in HC lookup user
	//if HC only user send to provisional deposit review group
	if ( 'hc' === Humanities_Commons::$society_id && 'hcadmin' !== $user->user_login ) {
		$query_args = array(
			'post_parent' => 0,
			'post_type'   => 'humcore_deposit',
			'post_status' => array( 'draft', 'publish' ),
			'author'      => $user->ID,
		);

		$deposit_posts    = get_posts( $query_args );
			$member_types = bp_get_member_type( $user->ID, false );
		if ( empty( $deposit_posts ) && 1 === count( $member_types ) && 'hc' === $member_types[0] ) {
			$deposit_review_needed = true;
			$deposit_post_status   = 'pending';
		}
	}

	/**
	 * Create XML needed for the fedora objects.
	 */
	$aggregator_xml = create_aggregator_xml(
		array(
			'pid'     => $next_pids[0],
			'creator' => $metadata['creator'],
		)
	);

	$aggregator_rdf = create_aggregator_rdf(
		array(
			'pid'           => $next_pids[0],
			'collectionPid' => $fedora_api->collection_pid,
		)
	);

	$aggregator_foxml = create_foxml(
		array(
			'pid'        => $next_pids[0],
			'label'      => '',
			'xmlContent' => $aggregator_xml,
			'state'      => 'Active',
			'rdfContent' => $aggregator_rdf,
		)
	);

	$metadata_mods = create_mods_xml( $metadata );

	$resource_xml = create_resource_xml( $metadata, $filetype );

	$resource_rdf = create_resource_rdf(
		array(
			'aggregatorPid' => $next_pids[0],
			'resourcePid'   => $next_pids[1],
		)
	);

	$resource_foxml = create_foxml(
		array(
			'pid'        => $next_pids[1],
			'label'      => $filename,
			'xmlContent' => $resource_xml,
			'state'      => 'Active',
			'rdfContent' => $resource_rdf,
		)
	);
	// TODO handle file write error.
	$file_write_status = file_put_contents( $mods_file, $metadata_mods );
			humcore_write_error_log( 'info', 'HumCORE deposit metadata complete' );

	/**
	 * Create the aggregator post now so that we can reference the ID in the Solr document.
	 */
	$deposit_post_data = array(
		'post_title'   => $metadata['title'],
		'post_excerpt' => $metadata['abstract'],
		'post_status'  => $deposit_post_status,
		'post_date'    => $deposit_post_date,
		'post_type'    => 'humcore_deposit',
		'post_name'    => str_replace( ':', '', $next_pids[0] ),
		'post_author'  => $user->ID,
	);

	$deposit_post_id               = wp_insert_post( $deposit_post_data );
	$metadata['record_identifier'] = get_current_blog_id() . '-' . $deposit_post_id;

	/**
	 * Set object terms for subjects.
	 */
	if ( ! empty( $metadata['subject'] ) ) {
		$term_ids = array();
		foreach ( $metadata['subject'] as $subject ) {
			$term_key = wpmn_term_exists( $subject, 'humcore_deposit_subject' );
			if ( ! is_wp_error( $term_key ) && ! empty( $term_key ) ) {
				$term_ids[] = intval( $term_key['term_id'] );
			} else {
				humcore_write_error_log( 'error', '*****HumCORE Deposit Error - bad subject*****' . var_export( $term_key, true ) );
			}
		}
		if ( ! empty( $term_ids ) ) {
			$term_object_id          = str_replace( $fedora_api->namespace . ':', '', $next_pids[0] );
			$term_taxonomy_ids       = wpmn_set_object_terms( $term_object_id, $term_ids, 'humcore_deposit_subject' );
			$metadata['subject_ids'] = $term_taxonomy_ids;
		}
	}

	/**
	 * Add any new keywords and set object terms for tags.
	 */
	if ( ! empty( $metadata['keyword'] ) ) {
		$term_ids = array();
		foreach ( $metadata['keyword'] as $keyword ) {
			$term_key = wpmn_term_exists( $keyword, 'humcore_deposit_tag' );
			if ( empty( $term_key ) ) {
				$term_key = wpmn_insert_term( sanitize_text_field( $keyword ), 'humcore_deposit_tag' );
			}
			if ( ! is_wp_error( $term_key ) ) {
				$term_ids[] = intval( $term_key['term_id'] );
			} else {
				humcore_write_error_log( 'error', '*****HumCORE Deposit Error - bad tag*****' . var_export( $term_key, true ) );
			}
		}
		if ( ! empty( $term_ids ) ) {
			$term_object_id          = str_replace( $fedora_api->namespace . ':', '', $next_pids[0] );
			$term_taxonomy_ids       = wpmn_set_object_terms( $term_object_id, $term_ids, 'humcore_deposit_tag' );
			$metadata['keyword_ids'] = $term_taxonomy_ids;
		}
	}

	$json_metadata = json_encode( $metadata, JSON_HEX_APOS );
	if ( json_last_error() ) {
		humcore_write_error_log( 'error', '*****HumCORE Deposit Error***** Post Meta Encoding Error - Post ID: ' . $deposit_post_id . ' - ' . json_last_error_msg() );
	}
	$post_meta_id = update_post_meta( $deposit_post_id, '_deposit_metadata', wp_slash( $json_metadata ) );
			humcore_write_error_log( 'info', 'HumCORE deposit - postmeta (1)', json_decode( $json_metadata, true ) );

	/**
	 * Add to metadata and store in post meta.
	 */
	$post_metadata['files'][] = array(
		'pid'                 => $next_pids[1],
		'datastream_id'       => $datastream_id,
		'filename'            => $filename,
		'filetype'            => $filetype,
		'filesize'            => $filesize,
		'fileloc'             => $renamed_file,
		'thumb_datastream_id' => ( ! empty( $generated_thumb_name ) ) ? $thumb_datastream_id : '',
		'thumb_filename'      => ( ! empty( $generated_thumb_name ) ) ? $generated_thumb_name : '',
	);

	$json_metadata = json_encode( $post_metadata, JSON_HEX_APOS );
	if ( json_last_error() ) {
		humcore_write_error_log( 'error', '*****HumCORE Deposit Error***** File Post Meta Encoding Error - Post ID: ' . $deposit_post_id . ' - ' . json_last_error_msg() );
	}
	$post_meta_id = update_post_meta( $deposit_post_id, '_deposit_file_metadata', wp_slash( $json_metadata ) );
			humcore_write_error_log( 'info', 'HumCORE deposit - postmeta (2)', json_decode( $json_metadata, true ) );

	/**
	 * Prepare an array of post data for the resource post.
	 */
	$resource_post_data = array(
		'post_title'  => $filename,
		'post_status' => 'publish',
		'post_type'   => 'humcore_deposit',
		'post_name'   => $next_pids[1],
		'post_author' => $user->ID,
		'post_parent' => $deposit_post_id,
	);

	/**
	 * Insert the resource post.
	 */
	$resource_post_id = wp_insert_post( $resource_post_data );

	/**
	 * Add POST variables needed for async tika extraction.
	 */
	$_POST['aggregator-post-id'] = $deposit_post_id;

	/**
	 * Extract text first if small. If Tika errors out we'll index without full text.
	 */
	if ( ! preg_match( '~^audio/|^image/|^video/~', $check_resource_filetype['type'] ) && (int) $filesize < 1000000 ) {

		try {
			$tika_text = $tika_client->getText( $renamed_file );
			$content   = $tika_text;
		} catch ( Exception $e ) {
			humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - A Tika error occurred extracting text from the uploaded file. This deposit, %1$s, will be indexed using only the web form metadata.', $next_pids[0] ) );
			humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - Tika error message: ' . $e->getMessage(), var_export( $e, true ) ) );
			$content = '';
		}
	}

	/**
	 * Index the deposit content and metadata in Solr.
	 */
	try {
		if ( preg_match( '~^audio/|^image/|^video/~', $check_filetype['type'] ) ) {
			$s_result = $solr_client->create_humcore_document( '', $metadata );
		} else {
			//$s_result = $solr_client->create_humcore_extract( $renamed_file, $metadata ); //no longer using tika on server
			$s_result = $solr_client->create_humcore_document( $content, $metadata );
		}
	} catch ( Exception $e ) {
		if ( '500' == $e->getCode() && strpos( $e->getMessage(), 'TikaException' ) ) { // Only happens if tika is on the solr server.
			try {
				$s_result = $solr_client->create_humcore_document( '', $metadata );
				humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - A Tika error occurred extracting text from the uploaded file. This deposit, %1$s, will be indexed using only the web form metadata.', $next_pids[0] ) );
			} catch ( Exception $e ) {
				echo '<h3>', __( 'An error occurred while depositing your file!', 'humcore_domain' ), '</h3>';
				humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - solr : %1$s-%2$s', $e->getCode(), $e->getMessage() ) );
				wp_delete_post( $deposit_post_id );
				return false;
			}
		} else {
			echo '<h3>', __( 'An error occurred while depositing your file!', 'humcore_domain' ), '</h3>';
			humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - solr : %1$s-%2$s', $e->getCode(), $e->getMessage() ) );
			wp_delete_post( $deposit_post_id );
			wp_delete_post( $resource_post_id );
			return false;
		}
	}

	/**
	 * Create the aggregator Fedora object along with the DC and RELS-EXT datastreams.
	 */
	$a_ingest = $fedora_api->ingest( array( 'xmlContent' => $aggregator_foxml ) );
	if ( is_wp_error( $a_ingest ) ) {
		echo 'Error - a_ingest : ' . esc_html( $a_ingest->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - a_ingest : %1$s-%2$s', $a_ingest->get_error_code(), $a_ingest->get_error_message() ) );
		return false;
	}

	/**
	 * Upload the MODS file to the Fedora server temp file storage.
	 */
	$upload_mods = $fedora_api->upload( array( 'file' => $mods_file ) );
	if ( is_wp_error( $upload_mods ) ) {
		echo 'Error - upload_mods : ' . esc_html( $upload_mods->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - upload_mods : %1$s-%2$s', $upload_mods->get_error_code(), $upload_mods->get_error_message() ) );
	}

	/**
	 * Create the descMetadata datastream for the aggregator object.
	 */
	$m_content = $fedora_api->add_datastream(
		array(
			'pid'          => $next_pids[0],
			'dsID'         => 'descMetadata',
			'controlGroup' => 'M',
			'dsLocation'   => $upload_mods,
			'dsLabel'      => $metadata['title'],
			'versionable'  => true,
			'dsState'      => 'A',
			'mimeType'     => 'text/xml',
			'content'      => false,
		)
	);
	if ( is_wp_error( $m_content ) ) {
		echo esc_html( 'Error - m_content : ' . $m_content->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - m_content : %1$s-%2$s', $m_content->get_error_code(), $m_content->get_error_message() ) );
	}

	$r_ingest = $fedora_api->ingest( array( 'xmlContent' => $resource_foxml ) );
	if ( is_wp_error( $r_ingest ) ) {
		echo esc_html( 'Error - r_ingest : ' . $r_ingest->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - r_ingest : %1$s-%2$s', $r_ingest->get_error_code(), $r_ingest->get_error_message() ) );
	}

	/**
	 * Upload the deposited file to the Fedora server temp file storage.
	 */
	$upload_url = $fedora_api->upload(
		array(
			'file'     => $renamed_file,
			'filename' => $filename,
			'filetype' => $filetype,
		)
	);
	if ( is_wp_error( $upload_url ) ) {
		echo 'Error - upload_url : ' . esc_html( $upload_url->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - upload_url (1) : %1$s-%2$s', $upload_url->get_error_code(), $upload_url->get_error_message() ) );
	}

	/**
	 * Create the CONTENT datastream for the resource object.
	 */
	$r_content = $fedora_api->add_datastream(
		array(
			'pid'          => $next_pids[1],
			'dsID'         => $datastream_id,
			'controlGroup' => 'M',
			'dsLocation'   => $upload_url,
			'dsLabel'      => $filename,
			'versionable'  => true,
			'dsState'      => 'A',
			'mimeType'     => $filetype,
			'content'      => false,
		)
	);
	if ( is_wp_error( $r_content ) ) {
		echo 'Error - r_content : ' . esc_html( $r_content->get_error_message() );
		humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - r_content : %1$s-%2$s', $r_content->get_error_code(), $r_content->get_error_message() ) );
	}

	/**
	 * Upload the thumb to the Fedora server temp file storage if necessary.
	 */
	//TODO fix thumbs if ( preg_match( '~^image/|/pdf$~', $check_filetype['type'] ) && ! empty( $generated_thumb_path ) ) {
	if ( preg_match( '~^image/$~', $check_filetype['type'] ) && ! empty( $generated_thumb_path ) ) {

		$upload_url = $fedora_api->upload(
			array(
				'file'     => $generated_thumb_path,
				'filename' => $generated_thumb_name,
				'filetype' => $generated_thumb_mime,
			)
		);
		if ( is_wp_error( $upload_url ) ) {
			echo 'Error - upload_url : ' . esc_html( $upload_url->get_error_message() );
			humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - upload_url (2) : %1$s-%2$s', $upload_url->get_error_code(), $upload_url->get_error_message() ) );
		}

		/**
		 * Create the THUMB datastream for the resource object if necessary.
		 */
		$t_content = $fedora_api->add_datastream(
			array(
				'pid'          => $next_pids[1],
				'dsID'         => $thumb_datastream_id,
				'controlGroup' => 'M',
				'dsLocation'   => $upload_url,
				'dsLabel'      => $generated_thumb_name,
				'versionable'  => true,
				'dsState'      => 'A',
				'mimeType'     => $generated_thumb_mime,
				'content'      => false,
			)
		);
		if ( is_wp_error( $t_content ) ) {
			echo 'Error - t_content : ' . esc_html( $t_content->get_error_message() );
			humcore_write_error_log( 'error', sprintf( '*****HumCORE Deposit Error***** - t_content : %1$s-%2$s', $t_content->get_error_code(), $t_content->get_error_message() ) );
		}
	}

	humcore_write_error_log( 'info', 'HumCORE deposit fedora/solr writes complete' );

	//DOI's are taking too long to resolve, put the permalink in the activity records.
	$local_link = sprintf( HC_SITE_URL . '/deposits/item/%s/', $next_pids[0] );

	/**
	 * Add the activity entry for the author.
	 */
	if ( $deposit_activity_needed ) {
		$activity_id = humcore_new_deposit_activity( $deposit_post_id, $metadata['abstract'], $local_link, $user->ID );
	}

	/**
	 * Publish the reserved DOI.
	 */
	if ( ! empty( $metadata['deposit_doi'] ) ) {
		$e_status = humcore_publish_handle( $metadata['deposit_doi'] );
		if ( false === $e_status ) {
			echo '<h3>', __( 'There was an EZID API error, the DOI was not sucessfully published.', 'humcore_domain' ), '</h3><br />';
		}
					humcore_write_error_log( 'info', 'HumCORE deposit DOI published' );
	}

	/**
	 * Notify provisional deposit review group for HC member deposits.
	 */
	if ( $deposit_review_needed ) {
		$bp                          = buddypress();
					$review_group_id = BP_Groups_Group::get_id_from_slug( 'provisional-deposit-review' );
		$group_args                  = array(
			'group_id'            => $review_group_id,
			'exclude_admins_mods' => false,
		);
		$provisional_reviewers       = groups_get_group_members( $group_args );
		humcore_write_error_log( 'info', 'Provisional Review Required ' . var_export( $provisional_reviewers, true ) );
		foreach ( $provisional_reviewers['members'] as $group_member ) {
			bp_notifications_add_notification(
				array(
					'user_id'           => $group_member->ID,
					'item_id'           => $deposit_post_id,
					'secondary_item_id' => $user->ID,
					'component_name'    => $bp->humcore_deposits->id,
					'component_action'  => 'deposit_review',
					'date_notified'     => bp_core_current_time(),
					'is_new'            => 1,
				)
			);
		}
		//$review_group = groups_get_group( array( 'group_id' => $review_group_id ) );
		//$group_activity_ids[] = humcore_new_group_deposit_activity( $metadata['record_identifier'], $review_group_id, $metadata['abstract'], $local_link );
		//$metadata['group'][] = $review_group->name;
		//$metadata['group_ids'][] = $review_group_id;
	}

	/**
	 * Re-index larger text based deposits in the background.
	 */
	if ( ! preg_match( '~^audio/|^image/|^video/~', $check_resource_filetype['type'] ) && (int) $filesize >= 1000000 ) {
		do_action( 'humcore_tika_text_extraction' );
	}

			$new_author_unis = array_map(
				function( $element ) {
						return urlencode( $element['uni'] );
				}, $metadata['authors']
			);
			$author_uni_keys = array_filter( $new_author_unis );
			humcore_delete_cache_keys( 'author_uni', $author_uni_keys );

			humcore_write_error_log( 'info', 'HumCORE deposit transaction complete' );
	echo '<h3>', __( 'Deposit complete!', 'humcore_domain' ), '</h3><br />';
	return $next_pids[0];

}

/**
 * Prepare the metadata sent to Fedora and Solr from $_POST input.
 *
 * @param string $user deposit user
 * @param array $curr_val array of $_POST entries.
 * @return array metadata content
 */
function prepare_user_entered_metadata( $user, $curr_val ) {

	/**
	 * Prepare the metadata to be sent to Fedora and Solr.
	 */
	$metadata                       = array();
	$metadata['title']              = wp_strip_all_tags( stripslashes( $curr_val['deposit-title-unchanged'] ) );
	$metadata['title_unchanged']    = wp_kses(
		stripslashes( $curr_val['deposit-title-unchanged'] ),
		array(
			'b'      => array(),
			'em'     => array(),
			'strong' => array(),
		)
	);
	$metadata['abstract']           = wp_strip_all_tags( stripslashes( $curr_val['deposit-abstract-unchanged'] ) );
	$metadata['abstract_unchanged'] = wp_kses(
		stripslashes( $curr_val['deposit-abstract-unchanged'] ),
		array(
			'b'      => array(),
			'em'     => array(),
			'strong' => array(),
		)
	);
	$metadata['genre']              = sanitize_text_field( $curr_val['deposit-genre'] );
	$metadata['committee_deposit']  = sanitize_text_field( $curr_val['deposit-on-behalf-flag'] );
	if ( ! empty( $curr_val['deposit-committee'] ) ) {
		$metadata['committee_id'] = sanitize_text_field( $curr_val['deposit-committee'] );
	} else {
		$metadata['committee_id'] = '';
	}

	/**
	 * Get committee or author metadata.
	 */

	if ( 'yes' === $metadata['committee_deposit'] ) {
		$committee                = groups_get_group( array( 'group_id' => $metadata['committee_id'] ) );
		$metadata['organization'] = strtoupper( Humanities_Commons::$society_id );
		$metadata['authors'][]    = array(
			'fullname'    => $committee->name,
			'given'       => '',
			'family'      => '',
			'uni'         => $committee->slug,
			'role'        => 'creator',
			'affiliation' => strtoupper( Humanities_Commons::$society_id ),
		);
	} elseif ( 'submitter' !== sanitize_text_field( $curr_val['deposit-author-role'] ) ) {
		$user_id                  = $user->ID;
		$user_firstname           = get_the_author_meta( 'first_name', $user_id );
		$user_lastname            = get_the_author_meta( 'last_name', $user_id );
		$user_affiliation         = bp_get_profile_field_data(
			array(
				'field'   => 2,
				'user_id' => $user_id,
			)
		);
		$metadata['organization'] = $user_affiliation;
		$metadata['authors'][]    = array(
			'fullname'    => $user->display_name,
			'given'       => $user_firstname,
			'family'      => $user_lastname,
			'uni'         => $user->user_login,
			'role'        => sanitize_text_field( $curr_val['deposit-author-role'] ),
			'affiliation' => $user_affiliation,
		);
	}

	if ( ( ! empty( $curr_val['deposit-other-authors-first-name'] ) && ! empty( $curr_val['deposit-other-authors-last-name'] ) ) ) {
		$other_authors = array_map(
			function ( $first_name, $last_name, $role ) {
						return array(
							'first_name' => sanitize_text_field( $first_name ),
							'last_name'  => sanitize_text_field( $last_name ),
							'role'       => sanitize_text_field( $role ),
						); },
			$curr_val['deposit-other-authors-first-name'], $curr_val['deposit-other-authors-last-name'], $curr_val['deposit-other-authors-role']
		);
		foreach ( $other_authors as $author_array ) {
			if ( ! empty( $author_array['first_name'] ) && ! empty( $author_array['last_name'] ) ) {
				$mla_user = bp_activity_find_mentions( $author_array['first_name'] . $author_array['last_name'] );
				if ( ! empty( $mla_user ) ) {
					foreach ( $mla_user as $mla_userid => $mla_username ) {
						break;
					} // Only one, right?
					$author_name        = bp_core_get_user_displayname( $mla_userid );
					$author_firstname   = get_the_author_meta( 'first_name', $mla_userid );
					$author_lastname    = get_the_author_meta( 'last_name', $mla_userid );
					$author_affiliation = bp_get_profile_field_data(
						array(
							'field'   => 2,
							'user_id' => $mla_userid,
						)
					);
					$author_uni         = $mla_username;
				} else {
					$author_firstname   = $author_array['first_name'];
					$author_lastname    = $author_array['last_name'];
					$author_name        = trim( $author_firstname . ' ' . $author_lastname );
					$author_uni         = '';
					$author_affiliation = '';
				}
				$metadata['authors'][] = array(
					'fullname'    => $author_name,
					'given'       => $author_firstname,
					'family'      => $author_lastname,
					'uni'         => $author_uni,
					'role'        => $author_array['role'],
					'affiliation' => $author_affiliation,
				);
			}
		}
	}

	usort(
		$metadata['authors'], function( $a, $b ) {
				return strcasecmp( $a['family'], $b['family'] );
		}
	);

	/**
	 * Format author info for solr.
	 */
	$metadata['author_info'] = humcore_deposits_format_author_info( $metadata['authors'] );

	if ( ! empty( $metadata['genre'] ) && in_array( $metadata['genre'], array( 'Dissertation', 'Technical report', 'Thesis', 'White paper' ) ) &&
		! empty( $curr_val['deposit-institution'] ) ) {
		$metadata['institution'] = sanitize_text_field( $curr_val['deposit-institution'] );
	} elseif ( ! empty( $metadata['genre'] ) && in_array( $metadata['genre'], array( 'Dissertation', 'Technical report', 'Thesis', 'White paper' ) ) &&
		empty( $curr_val['deposit-institution'] ) ) {
		$metadata['institution'] = $metadata['organization'];
	}

	if ( ! empty( $metadata['genre'] ) && ( 'Conference proceeding' == $metadata['genre'] || 'Conference paper' == $metadata['genre'] ) ) {
		$metadata['conference_title']        = sanitize_text_field( $curr_val['deposit-conference-title'] );
		$metadata['conference_organization'] = sanitize_text_field( $curr_val['deposit-conference-organization'] );
		$metadata['conference_location']     = sanitize_text_field( $curr_val['deposit-conference-location'] );
		$metadata['conference_date']         = sanitize_text_field( $curr_val['deposit-conference-date'] );
	}

	if ( ! empty( $metadata['genre'] ) && 'Presentation' == $metadata['genre'] ) {
		$metadata['meeting_title']        = sanitize_text_field( $curr_val['deposit-meeting-title'] );
		$metadata['meeting_organization'] = sanitize_text_field( $curr_val['deposit-meeting-organization'] );
		$metadata['meeting_location']     = sanitize_text_field( $curr_val['deposit-meeting-location'] );
		$metadata['meeting_date']         = sanitize_text_field( $curr_val['deposit-meeting-date'] );
	}

	$metadata['group']      = array();
			$deposit_groups = $curr_val['deposit-group'];
	if ( ! empty( $deposit_groups ) ) {
		foreach ( $deposit_groups as $group_id ) {
			$group                   = groups_get_group( array( 'group_id' => sanitize_text_field( $group_id ) ) );
			$metadata['group'][]     = $group->name;
			$metadata['group_ids'][] = $group_id;
		}
	}

	$metadata['subject']      = array();
			$deposit_subjects = $curr_val['deposit-subject'];
	if ( ! empty( $deposit_subjects ) ) {
		foreach ( $deposit_subjects as $subject ) {
			$metadata['subject'][] = sanitize_text_field( stripslashes( $subject ) );
			// Subject ids will be set later.
		}
	}

	$metadata['keyword']      = array();
			$deposit_keywords = $curr_val['deposit-keyword'];
	if ( ! empty( $deposit_keywords ) ) {
		foreach ( $deposit_keywords as $keyword ) {
			$metadata['keyword'][] = sanitize_text_field( stripslashes( $keyword ) );
			// Keyword ids will be set later.
		}
	}

	$metadata['type_of_resource'] = sanitize_text_field( $curr_val['deposit-resource-type'] );
	$metadata['language']         = sanitize_text_field( $curr_val['deposit-language'] );
	$metadata['notes']            = sanitize_text_field( stripslashes( $curr_val['deposit-notes-unchanged'] ) ); // Where do they go in MODS?
	$metadata['notes_unchanged']  = wp_kses(
		stripslashes( $curr_val['deposit-notes-unchanged'] ),
		array(
			'b'      => array(),
			'em'     => array(),
			'strong' => array(),
		)
	);
	$metadata['type_of_license']  = sanitize_text_field( $curr_val['deposit-license-type'] );
	$metadata['published']        = sanitize_text_field( $curr_val['deposit-published'] ); // Not stored in solr.
	if ( ! empty( $curr_val['deposit-publication-type'] ) ) {
		$metadata['publication-type'] = sanitize_text_field( $curr_val['deposit-publication-type'] ); // Not stored in solr.
	} else {
		$metadata['publication-type'] = 'none';
	}

	if ( 'book' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-book-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-book-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['edition'] = sanitize_text_field( $curr_val['deposit-book-edition'] );
		$metadata['volume']  = sanitize_text_field( $curr_val['deposit-book-volume'] );
		$metadata['isbn']    = sanitize_text_field( $curr_val['deposit-book-isbn'] );
		$metadata['doi']     = sanitize_text_field( $curr_val['deposit-book-doi'] );
	} elseif ( 'book-chapter' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-book-chapter-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-book-chapter-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-book-chapter-title'] );
		$metadata['book_author']        = sanitize_text_field( $curr_val['deposit-book-chapter-author'] );
		$metadata['chapter']            = sanitize_text_field( $curr_val['deposit-book-chapter-chapter'] );
		$metadata['start_page']         = sanitize_text_field( $curr_val['deposit-book-chapter-start-page'] );
		$metadata['end_page']           = sanitize_text_field( $curr_val['deposit-book-chapter-end-page'] );
		$metadata['isbn']               = sanitize_text_field( $curr_val['deposit-book-chapter-isbn'] );
		$metadata['doi']                = sanitize_text_field( $curr_val['deposit-book-chapter-doi'] );
	} elseif ( 'book-review' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-book-review-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-book-review-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['doi'] = sanitize_text_field( $curr_val['deposit-book-review-doi'] );
	} elseif ( 'book-section' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-book-section-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-book-section-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-book-section-title'] );
		$metadata['book_author']        = sanitize_text_field( $curr_val['deposit-book-section-author'] );
		$metadata['edition']            = sanitize_text_field( $curr_val['deposit-book-section-edition'] );
		$metadata['start_page']         = sanitize_text_field( $curr_val['deposit-book-section-start-page'] );
		$metadata['end_page']           = sanitize_text_field( $curr_val['deposit-book-section-end-page'] );
		$metadata['isbn']               = sanitize_text_field( $curr_val['deposit-book-section-isbn'] );
		$metadata['doi']                = sanitize_text_field( $curr_val['deposit-book-section-doi'] );
	} elseif ( 'journal-article' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-journal-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-journal-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-journal-title'] );
		$metadata['volume']             = sanitize_text_field( $curr_val['deposit-journal-volume'] );
		$metadata['issue']              = sanitize_text_field( $curr_val['deposit-journal-issue'] );
		$metadata['start_page']         = sanitize_text_field( $curr_val['deposit-journal-start-page'] );
		$metadata['end_page']           = sanitize_text_field( $curr_val['deposit-journal-end-page'] );
		$metadata['issn']               = sanitize_text_field( $curr_val['deposit-journal-issn'] );
		$metadata['doi']                = sanitize_text_field( $curr_val['deposit-journal-doi'] );
	} elseif ( 'magazine-section' == $metadata['publication-type'] ) {
		$metadata['date'] = sanitize_text_field( $curr_val['deposit-magazine-section-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-magazine-section-title'] );
		$metadata['volume']             = sanitize_text_field( $curr_val['deposit-magazine-section-volume'] );
		$metadata['start_page']         = sanitize_text_field( $curr_val['deposit-magazine-section-start-page'] );
		$metadata['end_page']           = sanitize_text_field( $curr_val['deposit-magazine-section-end-page'] );
		$metadata['url']                = sanitize_text_field( $curr_val['deposit-magazine-section-url'] );
	} elseif ( 'monograph' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-monograph-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-monograph-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['isbn'] = sanitize_text_field( $curr_val['deposit-monograph-isbn'] );
		$metadata['doi']  = sanitize_text_field( $curr_val['deposit-monograph-doi'] );
	} elseif ( 'newspaper-article' == $metadata['publication-type'] ) {
		$metadata['date'] = sanitize_text_field( $curr_val['deposit-newspaper-article-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-newspaper-article-title'] );
		$metadata['edition']            = sanitize_text_field( $curr_val['deposit-newspaper-article-edition'] );
		$metadata['volume']             = sanitize_text_field( $curr_val['deposit-newspaper-article-volume'] );
		$metadata['start_page']         = sanitize_text_field( $curr_val['deposit-newspaper-article-start-page'] );
		$metadata['end_page']           = sanitize_text_field( $curr_val['deposit-newspaper-article-end-page'] );
		$metadata['url']                = sanitize_text_field( $curr_val['deposit-newspaper-article-url'] );
	} elseif ( 'online-publication' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-online-publication-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-online-publication-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-online-publication-title'] );
		$metadata['edition']            = sanitize_text_field( $curr_val['deposit-online-publication-edition'] );
		$metadata['volume']             = sanitize_text_field( $curr_val['deposit-online-publication-volume'] );
		$metadata['url']                = sanitize_text_field( $curr_val['deposit-online-publication-url'] );
	} elseif ( 'podcast' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-podcast-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-podcast-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['volume'] = sanitize_text_field( $curr_val['deposit-podcast-volume'] );
		$metadata['url']    = sanitize_text_field( $curr_val['deposit-podcast-url'] );
	} elseif ( 'proceedings-article' == $metadata['publication-type'] ) {
		$metadata['publisher'] = sanitize_text_field( $curr_val['deposit-proceeding-publisher'] );
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-proceeding-publish-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
		$metadata['book_journal_title'] = sanitize_text_field( $curr_val['deposit-proceeding-title'] );
		$metadata['start_page']         = sanitize_text_field( $curr_val['deposit-proceeding-start-page'] );
		$metadata['end_page']           = sanitize_text_field( $curr_val['deposit-proceeding-end-page'] );
		$metadata['doi']                = sanitize_text_field( $curr_val['deposit-proceeding-doi'] );
	} elseif ( 'none' == $metadata['publication-type'] ) {
		$metadata['publisher'] = '';
		$metadata['date']      = sanitize_text_field( $curr_val['deposit-non-published-date'] );
		if ( ! empty( $metadata['date'] ) ) {
			$metadata['date_issued'] = get_year_issued( $metadata['date'] );
		} else {
			$metadata['date_issued'] = date( 'Y', strtotime( 'today' ) );
		}
	}

			$metadata['embargoed'] = sanitize_text_field( $curr_val['deposit-embargoed-flag'] );

	if ( 'yes' === $metadata['embargoed'] ) {
			$metadata['embargo_end_date'] = date( 'm/d/Y', strtotime( '+' . sanitize_text_field( $curr_val['deposit-embargo-length'] ) ) );
	}

			return $metadata;

}

/**
 * Get the year from the date entered.
 *
 * @param string $date Date entered
 * @return string Date in YYYY format
 */
function get_year_issued( $date_entered ) {

	// The strtotime function will handle a wide variety of entries. First address some cases it will not handle.
	$temp_date_entered = preg_replace(
		'~^(winter(?:/|)|spring(?:/|)|summer(?:/|)|fall(?:/|)|autumn(?:/|))+\s(\d{4})$~i',
		'Jan $2',
		$date_entered
	); // Custom publication date format.

	$temp_date_entered = preg_replace(
		'/^(\d{4})$/',
		'Jan $1',
		$temp_date_entered
	); // Workaround for when only YYYY is entered.

	$ambiguous_date = preg_match( '~^(\d{2})-(\d{2})-(\d{2}(?:\d{2})?)(?:\s.*?|)$~', $temp_date_entered, $matches );
	if ( 1 === $ambiguous_date ) { // Just deal with slashes.
			$temp_date_entered = sprintf( '%1$s/%2$s/%3$s', $matches[1], $matches[2], $matches[3] );
	}

	$ambiguous_date = preg_match( '~^(\d{2})/(\d{2})/(\d{2}(?:\d{2})?)(?:\s.*?|)$~', $temp_date_entered, $matches );
	if ( 1 === $ambiguous_date && $matches[1] > 12 ) { // European date in d/m/y format will fail for dd > 12.
		$temp_date_entered = sprintf( '%1$s/%2$s/%3$s', $matches[2], $matches[1], $matches[3] );
	}

	$date_value = strtotime( $temp_date_entered );

	if ( false === $date_value ) {
		return date( 'Y', strtotime( 'today' ) ); //TODO Real date edit message, kick back to user to fix. Meanwhile, this year is better than nothing.
	}

	return date( 'Y', $date_value );

}

/**
 * Format the xml used to create the DC datastream for the aggregator object.
 *
 * @param array $args Array of arguments.
 * @return WP_Error|string xml content
 * @see wp_parse_args()
 */
function create_aggregator_xml( $args ) {

	$defaults = array(
		'pid'     => '',
		'creator' => 'HumCORE',
		'title'   => 'Generic Content Aggregator',
		'type'    => 'InteractiveResource',
	);
	$params   = wp_parse_args( $args, $defaults );

	$pid     = $params['pid'];
	$creator = $params['creator'];
	$title   = $params['title'];
	$type    = $params['type'];

	if ( empty( $pid ) ) {
		return new WP_Error( 'missingArg', 'PID is missing.' );
	}

	return '<oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/"
			xmlns:dc="http://purl.org/dc/elements/1.1/"
			xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
			xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd">
		  <dc:identifier>' . $pid . '</dc:identifier>
		  <dc:creator>' . $creator . '</dc:creator>
		  <dc:title>' . $title . '</dc:title>
		  <dc:type>' . $type . '</dc:type>
		</oai_dc:dc>';

}

/**
 * Format the rdf used to create the RELS-EXT datastream for the aggregator object.
 *
 * @param array $args Array of arguments.
 * @return WP_Error|string rdf content
 * @see wp_parse_args()
 */
function create_aggregator_rdf( $args ) {

	$defaults = array(
		'pid'           => '',
		'collectionPid' => '',
		'isCollection'  => false,
		'fedoraModel'   => 'ContentAggregator',
	);
	$params   = wp_parse_args( $args, $defaults );

	$pid            = $params['pid'];
	$collection_pid = $params['collectionPid'];
	$is_collection  = $params['isCollection'];
	$fedora_model   = $params['fedoraModel'];

	if ( empty( $pid ) ) {
		return new WP_Error( 'missingArg', 'PID is missing.' );
	}

	$member_of_markup = '';
	if ( ! empty( $collection_pid ) ) {
		$member_of_markup = sprintf( '<pcdm:memberOf rdf:resource="info:fedora/%1$s"></pcdm:memberOf>', $collection_pid );
	}

	$is_collection_markup = '';
	if ( $is_collection ) {
		$is_collection_markup = '<isCollection xmlns="info:fedora/fedora-system:def/relations-external#">true</isCollection>';
	}

	return '<rdf:RDF xmlns:fedora-model="info:fedora/fedora-system:def/model#"
			xmlns:ore="http://www.openarchives.org/ore/terms/"
			xmlns:pcdm="http://pcdm.org/models#"
			xmlns:cc="http://creativecommons.org/ns#"
			xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
		  <rdf:Description rdf:about="info:fedora/' . $pid . '">
			<fedora-model:hasModel rdf:resource="info:fedora/ldpd:' . $fedora_model . '"></fedora-model:hasModel>
			<rdf:type rdf:resource="http://pcdm.org/models#Object"></rdf:type>
			' . $is_collection_markup . '
			' . $member_of_markup . '
			<cc:license rdf:resource="info:fedora/"></cc:license>
		   </rdf:Description>
		</rdf:RDF>';

}

/**
 * Format the xml used to create the DC datastream for the resource object.
 *
 * @param array $args Array of arguments.
 * @return WP_Error|string xml content
 * @see wp_parse_args()
 */
function create_resource_xml( $metadata, $filetype = '' ) {

	if ( empty( $metadata ) ) {
		return new WP_Error( 'missingArg', 'metadata is missing.' );
	}
	$pid = $metadata['pid'];
	if ( empty( $pid ) ) {
		return new WP_Error( 'missingArg', 'PID is missing.' );
	}
	$title        = humcore_cleanup_utf8( htmlspecialchars( $metadata['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) );
	$type         = humcore_cleanup_utf8( htmlspecialchars( $metadata['genre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) );
	$description  = humcore_cleanup_utf8( htmlspecialchars( $metadata['abstract'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) );
	$creator_list = '';
	foreach ( $metadata['authors'] as $author ) {
		if ( ( in_array( $author['role'], array( 'creator', 'author' ) ) ) && ! empty( $author['fullname'] ) ) {
				$creator_list .= '
                                  <dc:creator>' . $author['fullname'] . '</dc:creator>';
		}
	}

			$subject_list = '';
	foreach ( $metadata['subject'] as $subject ) {
			$subject_list .= '
                        <dc:subject>' . humcore_cleanup_utf8( htmlspecialchars( $subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) ) . '</dc:subject>';
	}
	if ( ! empty( $metadata['publisher'] ) ) {
		$publisher = '<dc:publisher>' . humcore_cleanup_utf8( htmlspecialchars( $metadata['publisher'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) ) . '</dc:publisher>';
	} else {
		$publisher = '';
	}
	if ( ! empty( $metadata['date_issued'] ) ) {
			$date = '
                        <dc:date encoding="w3cdtf">' . $metadata['date_issued'] . '</dc:date>';
	} else {
		$date = '';
	}

	return '<oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/"
		xmlns:dc="http://purl.org/dc/elements/1.1/"
		xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd">
		  <dc:identifier>' . $pid . '</dc:identifier>
		  ' . $creator_list . '
		  ' . $date . '
		  <dc:title>' . $title . '</dc:title>
		  <dc:description>' . $description . '</dc:description>
		  ' . $subject_list . '
		  ' . $publisher . '
		  <dc:type>' . $type . '</dc:type>
		  <dc:format>' . $filetype . '</dc:format>
		</oai_dc:dc>';

}

/**
 * Format the rdf used to create the RELS-EXT datastream for the aggregator object.
 *
 * @param array $args Array of arguments.
 * @return WP_Error|string rdf content
 * @see wp_parse_args()
 */
function create_resource_rdf( $args ) {

	$defaults = array(
		'aggregatorPid' => '',
		'resourcePid'   => '',
		'collectionPid' => '',
	);
	$params   = wp_parse_args( $args, $defaults );

	$aggregator_pid    = $params['aggregatorPid'];
	$resource_pid      = $params['resourcePid'];
	$collection_pid    = $params['collectionPid'];
	$collection_markup = '';

	if ( empty( $aggregator_pid ) ) {
		return new WP_Error( 'missingArg', 'PID is missing.' );
	}

	if ( empty( $resource_pid ) ) {
		return new WP_Error( 'missingArg', 'PID is missing.' );
	}

	if ( ! empty( $collection_pid ) ) {
		$collection_markup = sprintf( '<pcdm:memberOf rdf:resource="info:fedora/%1$s"></pcdm:memberOf>', $collection_pid );
	}

	return '<rdf:RDF xmlns:fedora-model="info:fedora/fedora-system:def/model#"
			xmlns:dcmi="http://purl.org/dc/terms/"
			xmlns:pcdm="http://pcdm.org/models#"
			xmlns:rel="info:fedora/fedora-system:def/relations-external#"
			xmlns:cc="http://creativecommons.org/ns#"
			xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
		  <rdf:Description rdf:about="info:fedora/' . $resource_pid . '">
			<fedora-model:hasModel rdf:resource="info:fedora/ldpd:Resource"></fedora-model:hasModel>
			<rdf:type rdf:resource="http://pcdm.org/models#File"></rdf:type>
			<pcdm:memberOf rdf:resource="info:fedora/' . $aggregator_pid . '"></pcdm:memberOf>
			' . $collection_markup . '
			<cc:license rdf:resource="info:fedora/"></cc:license>
		  </rdf:Description>
		</rdf:RDF>';

}

/**
 * Format the foxml used to create Fedora aggregator and resource objects.
 *
 * @param array $args Array of arguments.
 * @return WP_Error|string foxml content
 * @see wp_parse_args()
 */
function create_foxml( $args ) {

	$defaults = array(
		'pid'        => '',
		'label'      => '',
		'xmlContent' => '',
		'state'      => 'Active',
		'rdfContent' => '',
	);
	$params   = wp_parse_args( $args, $defaults );

	$pid         = $params['pid'];
	$label       = $params['label'];
	$xml_content = $params['xmlContent'];
	$state       = $params['state'];
	$rdf_content = $params['rdfContent'];

	if ( empty( $pid ) ) {
		return new WP_Error( 'missingArg', 'PID is missing.' );
	}

	if ( empty( $xml_content ) ) {
		return new WP_Error( 'missingArg', 'XML string is missing.' );
	}

	if ( empty( $rdf_content ) ) {
		return new WP_Error( 'missingArg', 'RDF string is missing.' );
	}

	$output = '<?xml version="1.0" encoding="UTF-8"?>
		<foxml:digitalObject VERSION="1.1" PID="' . $pid . '"
			xmlns:foxml="info:fedora/fedora-system:def/foxml#"
			xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
			xsi:schemaLocation="info:fedora/fedora-system:def/foxml# http://www.fedora.info/definitions/1/0/foxml1-1.xsd">
			<foxml:objectProperties>
				<foxml:property NAME="info:fedora/fedora-system:def/model#state" VALUE="' . $state . '"/>
				<foxml:property NAME="info:fedora/fedora-system:def/model#label" VALUE="' . $label . '"/>
			</foxml:objectProperties>
			<foxml:datastream ID="DC" STATE="A" CONTROL_GROUP="X" VERSIONABLE="true">
				<foxml:datastreamVersion ID="DC1.0" LABEL="Dublin Core Record for this object"
						CREATED="' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '" MIMETYPE="text/xml"
						FORMAT_URI="http://www.openarchives.org/OAI/2.0/oai_dc/" SIZE="' . strlen( $xml_content ) . '">
					<foxml:xmlContent>' . $xml_content . '</foxml:xmlContent>
				</foxml:datastreamVersion>
			</foxml:datastream>
			<foxml:datastream ID="RELS-EXT" STATE="A" CONTROL_GROUP="X" VERSIONABLE="true">
				<foxml:datastreamVersion ID="RELS-EXT1.0" LABEL="RDF Statements about this object"
						CREATED="' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '" MIMETYPE="application/rdf+xml"
						FORMAT_URI="info:fedora/fedora-system:FedoraRELSExt-1.0" SIZE="' . strlen( $rdf_content ) . '">
					<foxml:xmlContent>' . $rdf_content . '</foxml:xmlContent>
				</foxml:datastreamVersion>
			</foxml:datastream>
		</foxml:digitalObject>';

	$dom                     = new DOMDocument;
	$dom->preserveWhiteSpace = false; // @codingStandardsIgnoreLine camelCase
	if ( false === $dom->loadXML( $output ) ) {
		humcore_write_error_log( 'error', '*****HumCORE Error - bad xml content*****' . var_export( $pid, true ) );
	}
	$dom->formatOutput = true; // @codingStandardsIgnoreLine camelCase
	return $dom->saveXML();

}

/**
 * Format the xml used to create the CONTENT datastream for the MODS metadata object.
 *
 * @param array $metadata
 * @return WP_Error|string mods xml content
 */
function create_mods_xml( $metadata ) {

	/**
	 * Format MODS xml fragment for one or more authors.
	 */
	$author_mods = '';
	foreach ( $metadata['authors'] as $author ) {

		if ( in_array( $author['role'], array( 'creator', 'author' ) ) ) {
			if ( 'creator' === $author['role'] ) {
				$author_mods .= '
				<name type="corporate">';
			} else {
				if ( ! empty( $author['uni'] ) ) {
					$author_mods .= '
					<name type="personal" ID="' . $author['uni'] . '">';
				} else {
					$author_mods .= '
					<name type="personal">';
				}
			}

			if ( ( 'creator' !== $author['role'] ) && ( ! empty( $author['family'] ) || ! empty( $author['given'] ) ) ) {
				$author_mods .= '
				  <namePart type="family">' . $author['family'] . '</namePart>
				  <namePart type="given">' . $author['given'] . '</namePart>';
				//          } else if ( 'creator' !== $author['role'] ) {
			} else {
				$author_mods .= '
				<namePart>' . $author['fullname'] . '</namePart>';
			}

			if ( 'creator' === $author['role'] ) {
				$author_mods .= '
					<role>
						<roleTerm type="text">creator</roleTerm>
					</role>';
			} else {
				$author_mods .= '
					<role>
						<roleTerm type="text">' . $author['role'] . '</roleTerm>
					</role>';
			}

			if ( ! empty( $author['affiliation'] ) ) {
				$author_mods .= '
				  <affiliation>' . htmlspecialchars( $author['affiliation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</affiliation>';
			}

			$author_mods .= '
				</name>';

		}
	}

	/**
	 * Format MODS xml fragment for organization affiliation.
	 */
	$org_mods = '';
	if ( ! empty( $metadata['genre'] ) && in_array( $metadata['genre'], array( 'Dissertation', 'Technical report', 'Thesis', 'White paper' ) ) &&
		! empty( $metadata['institution'] ) ) {
		$org_mods .= '
				<name type="corporate">
				  <namePart>
					' . htmlspecialchars( $metadata['institution'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '
				  </namePart>
				  <role>
					<roleTerm type="text">originator</roleTerm>
				  </role>
				</name>';
	}

	/**
	 * Format MODS xml fragment for date issued.
	 */
	$date_issued_mods = '';
	if ( ! empty( $metadata['date_issued'] ) ) {
		$date_issued_mods = '
			<originInfo>
				<dateIssued encoding="w3cdtf" keyDate="yes">' . $metadata['date_issued'] . '</dateIssued>
			</originInfo>';
	}

	/**
	 * Format MODS xml fragment for resource type.
	 */
	$resource_type_mods = '';
	if ( ! empty( $metadata['type_of_resource'] ) ) {
		$resource_type_mods = '
			<typeOfResource>' . $metadata['type_of_resource'] . '</typeOfResource>';
	}

	/**
	 * Format MODS xml fragment for language.
	 */
	$language_mods = '';
	if ( ! empty( $metadata['language'] ) ) {
		$term          = wpmn_get_term_by( 'name', $metadata['language'], 'humcore_deposit_language' );
		$language_mods = '
			<language>
                                <languageTerm authority="iso639-3" >' . $term->slug . '</languageTerm>
                        </language>';
	}

	/**
	 * Format MODS xml fragment for genre.
	 */
	$genre_mods = '';
	if ( ! empty( $metadata['genre'] ) ) {
		$genre_mods = '
			<genre>' . htmlspecialchars( $metadata['genre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</genre>';
	}

	/**
	 * Format MODS xml fragment for one or more subjects.
	 */
	$full_subject_list = $metadata['subject'];
	$subject_mods      = '';
	foreach ( $full_subject_list as $subject ) {

		$subject_mods .= '
			<subject>
				<topic>' . htmlspecialchars( $subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</topic>
			</subject>';
	}

	$related_item_mods = '';
	if ( 'journal-article' == $metadata['publication-type'] ) {
		$related_item_mods = '
				<relatedItem type="host">
					<titleInfo>';
		if ( ! empty( $metadata['book_journal_title'] ) ) {
			$related_item_mods .= '
						<title>' . htmlspecialchars( $metadata['book_journal_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</title>';
		} else {
			$related_item_mods .= '
						<title/>';
		}
		$related_item_mods .= '
					</titleInfo>';
		if ( ! empty( $metadata['publisher'] ) ) {
			$related_item_mods .= '
					<originInfo>
						<publisher>' . htmlspecialchars( $metadata['publisher'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</publisher>';
			if ( ! empty( $metadata['date_issued'] ) ) {
				$related_item_mods .= '
						<dateIssued encoding="w3cdtf">' . $metadata['date_issued'] . '</dateIssued>';
			}
			$related_item_mods .= '
					</originInfo>';
		}
		$related_item_mods .= '
					<part>';
		if ( ! empty( $metadata['volume'] ) ) {
			$related_item_mods .= '
						<detail type="volume">
							<number>' . $metadata['volume'] . '</number>
						</detail>';
		}
		if ( ! empty( $metadata['issue'] ) ) {
			$related_item_mods .= '
						<detail type="issue">
							<number>' . $metadata['issue'] . '</number>
						</detail>';
		}
		if ( ! empty( $metadata['start_page'] ) ) {
			$related_item_mods .= '
						<extent unit="page">
							<start>' . $metadata['start_page'] . '</start>
							<end>' . $metadata['end_page'] . '</end>
						</extent>';
		}
		if ( ! empty( $metadata['date'] ) ) {
			$related_item_mods .= '
						<date>' . $metadata['date'] . '</date>';
		}
		$related_item_mods .= '
					</part>';
		if ( ! empty( $metadata['doi'] ) ) {
			$related_item_mods .= '
					<identifier type="doi">' . $metadata['doi'] . '</identifier>';
		}
		if ( ! empty( $metadata['issn'] ) ) {
			$related_item_mods .= '
					<identifier type="issn">' . $metadata['issn'] . '</identifier>';
		}
		$related_item_mods .= '
				</relatedItem>';
	} elseif ( 'book-chapter' == $metadata['publication-type'] ) {
		$related_item_mods = '
				<relatedItem type="host">
					<titleInfo>';
		if ( ! empty( $metadata['book_journal_title'] ) ) {
			$related_item_mods .= '
						<title>' . htmlspecialchars( $metadata['book_journal_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</title>';
		} else {
			$related_item_mods .= '
						<title/>';
		}
		$related_item_mods .= '
					</titleInfo>';
		if ( ! empty( $metadata['book_author'] ) ) {
			$related_item_mods .= '
						<name type="personal">
						<namePart>' . $metadata['book_author'] . '</namePart>
						<role>
						<roleTerm type="text">editor</roleTerm>
						</role>
					</name>';
		}
		if ( ! empty( $metadata['publisher'] ) ) {
			$related_item_mods .= '
					<originInfo>
						<publisher>' . htmlspecialchars( $metadata['publisher'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</publisher>';
			if ( ! empty( $metadata['date_issued'] ) ) {
				$related_item_mods .= '
						<dateIssued encoding="w3cdtf">' . $metadata['date_issued'] . '</dateIssued>';
			}
			$related_item_mods .= '
					</originInfo>';
		}
		$related_item_mods .= '
					<part>';
		if ( ! empty( $metadata['chapter'] ) ) {
			$related_item_mods .= '
						<detail type="chapter">
							<number>' . $metadata['chapter'] . '</number>
						</detail>';
		}
		if ( ! empty( $metadata['start_page'] ) ) {
			$related_item_mods .= '
						<extent unit="page">
							<start>' . $metadata['start_page'] . '</start>
							<end>' . $metadata['end_page'] . '</end>
						</extent>';
		}
		if ( ! empty( $metadata['date'] ) ) {
			$related_item_mods .= '
						<date>' . $metadata['date'] . '</date>';
		}
		$related_item_mods .= '
					</part>';
		if ( ! empty( $metadata['doi'] ) ) {
			$related_item_mods .= '
					<identifier type="doi">' . $metadata['doi'] . '</identifier>';
		}
		if ( ! empty( $metadata['isbn'] ) ) {
			$related_item_mods .= '
					<identifier type="isbn">' . $metadata['isbn'] . '</identifier>';
		}
		$related_item_mods .= '
				</relatedItem>';
	} elseif ( ! empty( $metadata['genre'] ) && ( 'Conference proceeding' == $metadata['genre'] || 'Conference paper' == $metadata['genre'] ) ) {
		$related_item_mods = '
				<relatedItem type="host">
					<titleInfo>';
		if ( ! empty( $metadata['conference_title'] ) ) {
			$related_item_mods .= '
						<title>' . htmlspecialchars( $metadata['conference_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</title>';
		} else {
			$related_item_mods .= '
						<title/>';
		}
		$related_item_mods .= '
					</titleInfo>';
		if ( ! empty( $metadata['publisher'] ) ) {
			$related_item_mods .= '
					<originInfo>
						<publisher>' . htmlspecialchars( $metadata['publisher'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</publisher>';
			if ( ! empty( $metadata['date_issued'] ) ) {
				$related_item_mods .= '
						<dateIssued encoding="w3cdtf">' . $metadata['date_issued'] . '</dateIssued>';
			}
			$related_item_mods .= '
					</originInfo>';
		}
		$related_item_mods .= '
				</relatedItem>';
	}

	/**
	 * Format the xml used to create the CONTENT datastream for the MODS metadata object.
	 */
	$metadata_mods = '<mods xmlns="http://www.loc.gov/mods/v3"
		  xmlns:xlink="http://www.w3.org/1999/xlink"
		  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		  xsi:schemaLocation="http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-4.xsd">
			<titleInfo>
				<title>' . htmlspecialchars( $metadata['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</title>
			</titleInfo>
			' . $author_mods . '
			' . $org_mods . '
			' . $resource_type_mods . '
			' . $genre_mods . '
			' . $date_issued_mods . '
			' . $language_mods . '
			<abstract>' . htmlspecialchars( $metadata['abstract'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ) . '</abstract>
			' . $subject_mods . '
			' . $related_item_mods . '
			<recordInfo>
				<recordCreationDate encoding="w3cdtf">' . date( 'Y-m-d H:i:s O' ) . '</recordCreationDate>
				<languageOfCataloging>
					<languageTerm authority="iso639-3">eng</languageTerm>
				</languageOfCataloging>
			</recordInfo>
		</mods>';

	return $metadata_mods;

}

/**
 * Format and ingest the foxml used to create a Fedora collection object.
 * Really only needed once per install.
 *
 * Example usage:
 * $c_status = create_collection_object();
 * var_export( $c_status, true );
 *
 * @global object $fedora_api {@link Humcore_Deposit_Fedora_Api}
 * @return WP_Error|string status
 * @see wp_parse_args()
 */
function create_collection_object() {

	global $fedora_api;

	$next_pids = $fedora_api->get_next_pid(
		array(
			'numPIDs'   => '1',
			'namespace' => $fedora_api->namespace . 'collection',
		)
	);
	if ( is_wp_error( $next_pids ) ) {
		echo 'Error - next_pids : ' . esc_html( $next_pids->get_error_code() ) . '-' . esc_html( $next_pids->get_error_message() );
		return $next_pids;
	}

	$collection_xml = create_aggregator_xml(
		array(
			'pid'   => $next_pids[0],
			'title' => 'Collection parent object for ' . $fedora_api->namespace,
			'type'  => 'Collection',
		)
	);

	$collection_rdf = create_aggregator_rdf(
		array(
			'pid'           => $next_pids[0],
			'collectionPid' => $fedora_api->collection_pid,
			'isCollection'  => true,
			'fedoraModel'   => 'BagAggregator',
		)
	);

	$collection_foxml = create_foxml(
		array(
			'pid'        => $next_pids[0],
			'label'      => '',
			'xmlContent' => $collection_xml,
			'state'      => 'Active',
			'rdfContent' => $collection_rdf,
		)
	);

	$c_ingest = $fedora_api->ingest( array( 'xmlContent' => $collection_foxml ) );
	if ( is_wp_error( $c_ingest ) ) {
		echo 'Error - c_ingest : ' . esc_html( $c_ingest->get_error_message() );
		return $c_ingest;
	}

	echo '<br />', __( 'Object Created: ', 'humcore_domain' ), date( 'Y-m-d H:i:s' ), var_export( $c_ingest, true );
	return $c_ingest;

}
