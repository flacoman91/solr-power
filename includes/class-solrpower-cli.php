<?php

/**
 * Perform a variety of actions against your Solr instance.
 */
class SolrPower_CLI extends WP_CLI_Command {

	/**
	 * Check server settings.
	 *
	 * Pings the Solr server to see if the connection is functional.
	 *
	 * @subcommand check-server-settings
	 */
	public function check_server_settings() {
		$retval = SolrPower_Api::get_instance()->ping_server();
		if ( $retval ) {
			WP_CLI::success( 'Server ping successful.' );
		} else {
			$last_error = SolrPower_Api::get_instance()->last_error;
			WP_CLI::error( "Server ping failed: {$last_error->getMessage()}" );
		}
	}

	/**
	 * Remove one or more posts from the index.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more post ids to remove from the index.
	 *
	 * [--all]
	 * : Remove all posts from the index.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp solr delete 342
	 *     Removed post 342 from the index.
	 *     Success: Specified posts removed from the index.
	 *
	 *     $ wp solr delete --all
	 *     Success: All posts successfully removed from the index.
	 *
	 */
	public function delete( $args, $assoc_args ) {

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all' ) ) {
			if ( SolrPower_Sync::get_instance()->delete_all() ) {
				WP_CLI::success( 'All posts successfully removed from the index.' );
			} else {
				$last_error = SolrPower_Api::get_instance()->last_error;
				WP_CLI::error( "Couldn't remove all posts from the index: {$last_error->getMessage()}" );
			}
		} else if ( count( $args ) ) {
			foreach( $args as $post_id ) {
				if ( SolrPower_Sync::get_instance()->delete( absint( $post_id ) ) ) {
					WP_CLI::log( "Removed post {$post_id} from the index." );
				} else {
					$last_error = SolrPower_Api::get_instance()->last_error;
					WP_CLI::warning( "Couldn't removed post {$post_id} from the index: {$last_error->getMessage()}" );
				}
			}
			WP_CLI::success( 'Specified posts removed from the index.' );
		} else {
			WP_CLI::error( 'Please specify one or more post ids, or use the --all flag.' );
		}

	}

	/**
	 * Index all posts for a site.
	 *
	 * @synopsis [--posts_per_page] [--post_type]
	 *
	 * @param array $args
	 *
	 *
	 * @param array $assoc_args
	 */
	public function index( $args, $assoc_args ) {
		$defaults = array(
			'posts_per_page' => 300,
			'post_status'	 => 'publish',
			'fields'		 => 'ids',
			'paged'			 => 1,
			'post_type'		 => get_post_types( array( 'exclude_from_search' => false ) ),
		);
		// Check if specified post_type is valid.
		if ( isset( $assoc_args[ 'post_type' ] ) && (false === post_type_exists( $assoc_args[ 'post_type' ] )) ) {
			WP_CLI::error( '"' . $assoc_args[ 'post_type' ] . '" is an invalid post type.' );
		}
		$query_args		 = array_merge( $defaults, $assoc_args );
		$query			 = new WP_Query( $query_args );
		$current_page	 = $query->get( 'paged' );
		$total			 = $query->max_num_pages;
		// There's a bug with found_posts that shows two more than what it should.
		$total_posts	 = (1 == $query->max_num_pages) ? $query->post_count : $query->found_posts - 2;

		$notify	 = \WP_CLI\Utils\make_progress_bar( 'Indexing Items:', $total_posts );
		$done	 = 0;
		$failed	 = 0;
		$solr	 = get_solr();
		$update	 = $solr->createUpdate();
		while ( $current_page <= $total ) {
			$query->set( 'paged', $current_page );
			$query->get_posts();
			foreach ( $query->posts as $id ) {
				$documents	 = array();
				$documents[] = SolrPower_Sync::get_instance()->build_document( $update->createDocument(), get_post( $id ) );
				$post_it	 = SolrPower_Sync::get_instance()->post( $documents, true, FALSE );

				if ( false === $post_it ) {
					$failed++;
				} else {
					$done++;
				}
				$notify->tick();
			}
			$current_page++;
		}
		$notify->finish();
		WP_CLI::success( sprintf( '%d of %d items indexed.', $done, $total_posts ) );
		if ( 0 < $failed ) {
			WP_CLI::error( 'Failed to index ' . $failed . ' item(s).' );
			WP_CLI::error( SolrPower_Sync::get_instance()->error_msg );
		}
	}

	/**
	 * Optimize the Solr index.
	 *
	 * Calls Solarium's addOptimize() to 'defragment' your index. The space
	 * taken by deleted document data is reclaimed and can merge the index into
	 * fewer segments. This can improve search performance a lot.
	 *
	 * @subcommand optimize-index
	 */
	public function optimize_index() {
		SolrPower_Api::get_instance()->optimize();
		WP_CLI::success( 'Index optimized.' );
	}

}

WP_CLI::add_command( 'solr', 'SolrPower_CLI' );
