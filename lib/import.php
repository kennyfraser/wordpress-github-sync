<?php
/**
 * GitHub Import Manager
 *
 * @package WordPress_GitHub_Sync
 */

/**
 * Class WordPress_GitHub_Sync_Import
 */
class WordPress_GitHub_Sync_Import {

	/**
	 * Application container.
	 *
	 * @var WordPress_GitHub_Sync
	 */
	protected $app;

	/**
	 * Initializes a new import manager.
	 *
	 * @param WordPress_GitHub_Sync $app Application container.
	 */
	public function __construct( WordPress_GitHub_Sync $app ) {
		$this->app = $app;
	}

	/**
	 * Imports a payload.
	 *
	 * @param WordPress_GitHub_Sync_Payload $payload GitHub payload object.
	 *
	 * @return string|WP_Error
	 */
	public function payload( WordPress_GitHub_Sync_Payload $payload ) {
		/**
		 * Whether there's an error during import.
		 *
		 * @var false|WP_Error $error
		 */
		$error = false;

		$result = $this->commit( $this->app->api()->fetch()->commit( $payload->get_commit_id() ) );

		if ( is_wp_error( $result ) ) {
			$error = $result;
		}

		$removed = array();
		foreach ( $payload->get_commits() as $commit ) {
			$removed = array_merge( $removed, $commit->removed );
		}
		foreach ( array_unique( $removed ) as $path ) {
			$result = $this->app->database()->delete_post_by_path( $path );

			if ( is_wp_error( $result ) ) {
				if ( $error ) {
					$error->add( $result->get_error_code(), $result->get_error_message() );
				} else {
					$error = $result;
				}
			}
		}

		if ( $error ) {
			return $error;
		}

		return __( 'Payload processed', 'wordpress-github-sync' );
	}

	/**
	 * Imports the latest commit on the master branch.
	 *
	 * @return string|WP_Error
	 */
	public function master() {
		return $this->commit( $this->app->api()->fetch()->master() );
	}

	/**
	 * Imports a provided commit into the database.
	 *
	 * @param WordPress_GitHub_Sync_Commit|WP_Error $commit Commit to import.
	 *
	 * @return string|WP_Error
	 */
	protected function commit( $commit ) {
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}

		if ( $commit->already_synced() ) {
			return new WP_Error( 'commit_synced', __( 'Already synced this commit.', 'wordpress-github-sync' ) );
		}

		$posts = array();
		$new   = array();

		$import_results = array();

		foreach ( $commit->tree()->blobs() as $blob ) {

			$import_results[] = $import_result = (object) array();
			$import_result->path = $blob->path();

			// is this blob importable?
			if ( !$this->importable_blob($blob) ) {
				$import_result->importable = false;
				continue;
			}

			// has the blob changed since the last import?
			if( !$this->blob_changed($blob) ){
				$import_result->changed = false;
				continue;
			}

			// fetch the function that will be used to import the blob
			// based on blob properties
			if( $processor = $this->get_blob_processor($blob) ){
				if( is_callable($processor) ) {

					// call the processor to import the blob
					$result = call_user_func_array($processor,[$blob,$this]);

					// if the result is a Post object ( WordPress_GitHub_Sync_Post )
					if( $result && is_a($result,'WordPress_GitHub_Sync_Post')){

						// add the post to the array of posts to be
						// imported in to the database
						$posts[] = $post = $result;

						if ( $post->is_new() ) {
							// keep track of new posts
							$new[] = $post;
						}

						$import_result->new = $post->is_new();

					}else{
						$import_result->result = $result?$result:false;
					}

				}else{
					$import_result->processor = "not callable";
				}

			}else{
				$import_result->processor = "no processor";
			}

		}

		// TODO: Log/output results
		// print_r($import_results);

		if( count($posts) ){

			$result = $this->app->database()->save_posts( $posts, $commit->author_email() );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $new ) {

				do_action('wpghs_post_new_post_import', $new);

				$result = $this->app->export()->new_posts( $new );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

		}

		return __( 'No commitable posts', 'wordpress-github-sync' );;
	}

	/**
	 * Get the function that will be used to import the blob, hookable
	 *
	 * @param  WordPress_GitHub_Sync_Blob
	 * @return callable
	 */
	protected function get_blob_processor( WordPress_GitHub_Sync_Blob $blob ) {
		$processor = false;

		// import any plain text .md document in the defaut way
		if ( $blob->has_frontmatter()
				&& $blob->mimetype() == "text/plain"
				&& $blob->file_extension() == "md" ) {
			$processor = array($this,'blob_to_post');
		}

		return apply_filters('wpghs_get_blob_processor', $processor, $blob);
	}

	/**
	 * Returns true if the path is not importable, hookable
	 *
	 * @param  string
	 * @return boolean
	 */
	protected function is_excluded_path( $path ) {

		$excluded = false;

		// Skip the repo's readme.
		if ( 'readme' === strtolower( substr( $path, 0, 6 ) ) ) {
			$excluded = true;
		}

		return apply_filters('wpghs_exclude_paths', $excluded, $path);
	}

	/**
	 * Returns true if the mine type is not importable, hookable
	 *
	 * @param  string
	 * @return boolean
	 */
	protected function is_excluded_mime_type( $mime_type ) {

		// nothing excuded by default
		$excluded = false;

		return apply_filters('wpghs_exclude_mime_types', $excluded, $mime_type);
	}

	/**
	 * Returns true if the file extension is not importable, hookable
	 *
	 * @param  string
	 * @return boolean
	 */
	protected function is_excluded_file_extension( $file_extension ) {

		// nothing excuded by default
		$excluded = false;

		// TODO: Test this hook
		return apply_filters('wpghs_exclude_file_extensions', $excluded, $file_extension);
	}

	/**
	 * Checks whether the provided blob should be imported.
	 *
	 * @param WordPress_GitHub_Sync_Blob $blob Blob to validate.
	 *
	 * @return bool
	 */
	protected function importable_blob( WordPress_GitHub_Sync_Blob $blob ) {
		global $wpdb;

		$importable = true;

		if( $this->is_excluded_path($blob->path()) ||
			$this->is_excluded_file_extension($blob->file_extension()) ||
			$this->is_excluded_mime_type($blob->path()) ){
			$importable = false;
		}

		return apply_filters('wpghs_importable_blob', $importable, $blob);
	}

	/**
	 * Checks whether the provided blob has changed.
	 *
	 * @param WordPress_GitHub_Sync_Blob $blob Blob to validate.
	 *
	 * @return bool
	 */
	protected function blob_changed( WordPress_GitHub_Sync_Blob $blob ) {
		global $wpdb;

		$changed = true;

		// If the blob sha already matches a post, and the path accociated with the sha
		// has not changed then there has been no change
		if ( $this->app->database()->sha_exists_with_path( $blob->sha(), $blob->path() ) ){
			return false;
		}

		return apply_filters('wpghs_blob_changed', $blob, $changed);
	}

	/**
	 * Imports a single blob content into matching post.
	 *
	 * @param WordPress_GitHub_Sync_Blob $blob Blob to transform into a Post.
	 *
	 * @return WordPress_GitHub_Sync_Post
	 */
	protected function blob_to_post( WordPress_GitHub_Sync_Blob $blob ) {
		$args = array( 'post_content' => $blob->content_import() );
		$meta = $blob->meta();

		if ( $meta ) {
			if ( array_key_exists( 'layout', $meta ) ) {
				$args['post_type'] = $meta['layout'];
				unset( $meta['layout'] );
			}

			if ( array_key_exists( 'published', $meta ) ) {
				$args['post_status'] = true === $meta['published'] ? 'publish' : 'draft';
				unset( $meta['published'] );
			}

			if ( array_key_exists( 'post_title', $meta ) ) {
				$args['post_title'] = $meta['post_title'];
				unset( $meta['post_title'] );
			}

			if ( array_key_exists( 'ID', $meta ) ) {
				$args['ID'] = $meta['ID'];
				unset( $meta['ID'] );
			}
		}

		$meta['_sha'] = $blob->sha();
		$meta['_wpghs_github_path']  = $blob->path();
		
		$meta = apply_filters('wpghs_blob_to_post_meta', $meta, $blob);
		$args = apply_filters('wpghs_blob_to_post_args', $args, $meta, $blob);

		$post = new WordPress_GitHub_Sync_Post( $args, $this->app->api() );
		$post->set_meta( $meta );

		return $post;
	}
}
