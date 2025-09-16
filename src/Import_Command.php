<?php

class Import_Command extends FIN_CLI_Command {

	private $blog_users = array();

	public $processed_posts = array();

	/**
	 * Imports content from a given WXR file.
	 *
	 * Provides a command line interface to the WordPress Importer plugin, for
	 * performing data migrations.
	 *
	 * Use `define( 'IMPORT_DEBUG', true );` for more verbosity during importing.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Path to one or more valid WXR files for importing. Directories are also accepted.
	 *
	 * --authors=<authors>
	 * : How the author mapping should be handled. Options are 'create', 'mapping.csv', or 'skip'. The first will create any non-existent users from the WXR file. The second will read author mapping associations from a CSV, or create a CSV for editing if the file path doesn't exist. The CSV requires two columns, and a header row like "old_user_login,new_user_login". The last option will skip any author mapping.
	 *
	 * [--skip=<data-type>]
	 * : Skip importing specific data. Supported options are: 'attachment' and 'image_resize' (skip time-consuming thumbnail generation).
	 *
	 * ## EXAMPLES
	 *
	 *     # Import content from a WXR file
	 *     $ fin import example.wordpress.2016-06-21.xml --authors=create
	 *     Starting the import process...
	 *     Processing post #1 ("Hello world!") (post_type: post)
	 *     -- 1 of 1
	 *     -- Tue, 21 Jun 2016 05:31:12 +0000
	 *     -- Imported post as post_id #1
	 *     Success: Finished importing from 'example.wordpress.2016-06-21.xml' file.
	 */
	public function __invoke( $args, $assoc_args ) {
		$defaults   = array(
			'authors' => null,
			'skip'    => array(),
		);
		$assoc_args = fin_parse_args( $assoc_args, $defaults );

		if ( ! is_array( $assoc_args['skip'] ) ) {
			$assoc_args['skip'] = explode( ',', $assoc_args['skip'] );
		}

		$importer = $this->is_importer_available();
		if ( is_fin_error( $importer ) ) {
			FIN_CLI::error( $importer );
		}

		$this->add_wxr_filters();

		FIN_CLI::log( 'Starting the import process...' );

		$new_args = array();
		foreach ( $args as $arg ) {
			if ( is_dir( $arg ) ) {
				$dir   = FIN_CLI\Utils\trailingslashit( $arg );
				$files = glob( $dir . '*.wxr' );
				if ( ! empty( $files ) ) {
					$new_args = array_merge( $new_args, $files );
				}

				$files = glob( $dir . '*.xml' );
				if ( ! empty( $files ) ) {
					$new_args = array_merge( $new_args, $files );
				}

				if ( empty( $files ) ) {
					FIN_CLI::warning( "No files found in the import directory '$arg'." );
				}
			} else {
				if ( ! file_exists( $arg ) ) {
					FIN_CLI::warning( "File '$arg' doesn't exist." );
					continue;
				}

				if ( is_readable( $arg ) ) {
					$new_args[] = $arg;
					continue;
				}

				FIN_CLI::warning( "Cannot read file '$arg'." );
			}
		}

		if ( empty( $new_args ) ) {
			FIN_CLI::error( 'Import failed due to missing or unreadable file/s.' );
		}

		$args = $new_args;

		foreach ( $args as $file ) {

			$ret = $this->import_wxr( $file, $assoc_args );

			if ( is_fin_error( $ret ) ) {
				FIN_CLI::error( $ret );
			} else {
				FIN_CLI::log( '' ); // WXR import ends with HTML, so make sure message is on next line
				FIN_CLI::success( "Finished importing from '$file' file." );
			}
		}
	}

	/**
	 * Imports a WXR file.
	 */
	private function import_wxr( $file, $args ) {

		$fin_import                  = new FIN_Import();
		$fin_import->processed_posts = $this->processed_posts;
		$import_data                = $fin_import->parse( $file );

		// Prepare the data to be used in process_author_mapping();
		$fin_import->get_authors_from_import( $import_data );

		// We no longer need the original data, so unset to avoid using excess
		// memory.
		unset( $import_data );

		$author_data = array();
		foreach ( $fin_import->authors as $wxr_author ) {
			$author = new \stdClass();
			// Always in the WXR
			$author->user_login = $wxr_author['author_login'];

			// Should be in the WXR; no guarantees
			if ( isset( $wxr_author['author_email'] ) ) {
				$author->user_email = $wxr_author['author_email'];
			}
			if ( isset( $wxr_author['author_display_name'] ) ) {
				$author->display_name = $wxr_author['author_display_name'];
			}
			if ( isset( $wxr_author['author_first_name'] ) ) {
				$author->first_name = $wxr_author['author_first_name'];
			}
			if ( isset( $wxr_author['author_last_name'] ) ) {
				$author->last_name = $wxr_author['author_last_name'];
			}

			$author_data[] = $author;
		}

		/**
		 * @var array<\FIN_User> $author_data
		 */

		// Build the author mapping
		$author_mapping = $this->process_author_mapping( $args['authors'], $author_data );
		if ( is_fin_error( $author_mapping ) ) {
			return $author_mapping;
		}

		$author_in  = fin_list_pluck( $author_mapping, 'old_user_login' );
		$author_out = fin_list_pluck( $author_mapping, 'new_user_login' );
		unset( $author_mapping, $author_data );

		// $user_select needs to be an array of user IDs
		$user_select         = array();
		$invalid_user_select = array();
		foreach ( $author_out as $author_login ) {
			$user = get_user_by( 'login', $author_login );
			if ( $user ) {
				$user_select[] = $user->ID;
			} else {
				$invalid_user_select[] = $author_login;
			}
		}
		if ( ! empty( $invalid_user_select ) ) {
			return new FIN_Error( 'invalid-author-mapping', sprintf( 'These user_logins are invalid: %s', implode( ',', $invalid_user_select ) ) );
		}

		unset( $author_out );

		// Drive the import
		$fin_import->fetch_attachments = ! in_array( 'attachment', $args['skip'], true );

		$_GET  = array(
			'import' => 'wordpress',
			'step'   => 2,
		);
		$_POST = array(
			'imported_authors'  => $author_in,
			'user_map'          => $user_select,
			'fetch_attachments' => $fin_import->fetch_attachments,
		);

		if ( in_array( 'image_resize', $args['skip'], true ) ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_set_image_sizes' ) );
		}

		$GLOBALS['fincli_import_current_file'] = basename( $file );
		$fin_import->import( $file );
		$this->processed_posts += $fin_import->processed_posts;

		return true;
	}

	public function filter_set_image_sizes( $sizes ) {
		// Return null here to prevent the core image resizing logic from running.
		return null;
	}

	/**
	 * Defines useful verbosity filters for the WXR importer.
	 */
	private function add_wxr_filters() {

		add_filter(
			'fin_import_posts',
			function ( $posts ) {
				global $fincli_import_counts;
				$fincli_import_counts['current_post'] = 0;
				$fincli_import_counts['total_posts']  = count( $posts );
				return $posts;
			},
			10
		);

		add_filter(
			'fin_import_post_comments',
			function ( $comments ) {
				global $fincli_import_counts;
				$fincli_import_counts['current_comment'] = 0;
				$fincli_import_counts['total_comments']  = count( $comments );
				return $comments;
			}
		);

		add_filter(
			'fin_import_post_data_raw',
			function ( $post ) {
				global $fincli_import_counts, $fincli_import_current_file;

				$fincli_import_counts['current_post']++;
				FIN_CLI::log( '' );
				FIN_CLI::log( '' );
				FIN_CLI::log( sprintf( 'Processing post #%d ("%s") (post_type: %s)', $post['post_id'], $post['post_title'], $post['post_type'] ) );
				FIN_CLI::log( sprintf( '-- %s of %s (in file %s)', number_format( $fincli_import_counts['current_post'] ), number_format( $fincli_import_counts['total_posts'] ), $fincli_import_current_file ) );
				FIN_CLI::log( '-- ' . date( 'r' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

				return $post;
			}
		);

		add_action(
			'fin_import_insert_post',
			function ( $post_id ) {
				global $fincli_import_counts;
				if ( is_fin_error( $post_id ) ) {
					FIN_CLI::warning( '-- Error importing post: ' . $post_id->get_error_code() );
				} else {
					FIN_CLI::log( "-- Imported post as post_id #{$post_id}" );
				}

				if ( 0 === ( $fincli_import_counts['current_post'] % 500 ) ) {
					// @phpstan-ignore function.deprecated
					FIN_CLI\Utils\fin_clear_object_cache();
					FIN_CLI::log( '-- Cleared object cache.' );
				}
			}
		);

		add_action(
			'fin_import_insert_term',
			function ( $t, $import_term ) {
				FIN_CLI::log( "-- Created term \"{$import_term['name']}\"" );
			},
			10,
			2
		);

		add_action(
			'fin_import_set_post_terms',
			function ( $tt_ids, $term_ids, $taxonomy ) {
				FIN_CLI::log( '-- Added terms (' . implode( ',', $term_ids ) . ") for taxonomy \"{$taxonomy}\"" );
			},
			10,
			3
		);

		add_action(
			'fin_import_insert_comment',
			function ( $comment_id ) {
				global $fincli_import_counts;
				$fincli_import_counts['current_comment']++;
				FIN_CLI::log( sprintf( '-- Added comment #%d (%s of %s)', $comment_id, number_format( $fincli_import_counts['current_comment'] ), number_format( $fincli_import_counts['total_comments'] ) ) );
			}
		);

		add_action(
			'import_post_meta',
			function ( $post_id, $key ) {
				FIN_CLI::log( "-- Added post_meta $key" );
			},
			10,
			2
		);
	}

	/**
	 * Determines whether the requested importer is available.
	 */
	private function is_importer_available() {
		require_once ABSPATH . 'fin-admin/includes/plugin.php';

		if ( class_exists( 'FIN_Import' ) ) {
			return true;
		}

		$plugins            = get_plugins();
		$wordpress_importer = 'wordpress-importer/wordpress-importer.php';
		if ( array_key_exists( $wordpress_importer, $plugins ) ) {
			$error_msg = "WordPress Importer needs to be activated. Try 'fin plugin activate wordpress-importer'.";
		} else {
			$error_msg = "WordPress Importer needs to be installed. Try 'fin plugin install wordpress-importer --activate'.";
		}

		return new FIN_Error( 'importer-missing', $error_msg );
	}

	/**
	 * Processes how the authors should be mapped
	 *
	 * @param string          $authors_arg The `--author` argument originally passed to command
	 * @param array<\FIN_User> $author_data An array of FIN_User-esque author objects
	 * @return array<\FIN_User>|FIN_Error Author mapping array if successful, FIN_Error if something bad happened
	 */
	private function process_author_mapping( $authors_arg, $author_data ) {

		// Provided an author mapping file (method checks validity)
		if ( file_exists( $authors_arg ) ) {
			return $this->read_author_mapping_file( $authors_arg );
		}

		// Provided a file reference, but the file doesn't yet exist
		if ( false !== stripos( $authors_arg, '.csv' ) ) {
			return $this->create_author_mapping_file( $authors_arg, $author_data );
		}

		switch ( $authors_arg ) {
			// Create authors if they don't yet exist; maybe match on email or user_login
			case 'create':
				return $this->create_authors_for_mapping( $author_data );

			// Skip any sort of author mapping
			case 'skip':
				return array();

			default:
				return new FIN_Error( 'invalid-argument', "'authors' argument is invalid." );
		}
	}

	/**
	 * Reads an author mapping file.
	 */
	private function read_author_mapping_file( $file ) {
		$author_mapping = array();

		foreach ( new \FIN_CLI\Iterators\CSV( $file ) as $i => $author ) {
			/**
			 * @var array<string, \FIN_User> $author
			 */
			if ( ! array_key_exists( 'old_user_login', $author ) || ! array_key_exists( 'new_user_login', $author ) ) {
				return new FIN_Error( 'invalid-author-mapping', "Author mapping file isn't properly formatted." );
			}

			$author_mapping[] = $author;
		}

		return $author_mapping;
	}

	/**
	 * Creates an author mapping file, based on provided author data.
	 *
	 * @return FIN_Error      The file was just now created, so some action needs to be taken
	 */
	private function create_author_mapping_file( $file, $author_data ) {

		if ( touch( $file ) ) {
			$author_mapping = array();
			foreach ( $author_data as $author ) {
				$author_mapping[] = array(
					'old_user_login' => $author->user_login,
					'new_user_login' => $this->suggest_user( $author->user_login, $author->user_email ),
				);
			}
			$file_resource = fopen( $file, 'w' );

			if ( ! $file_resource ) {
				return new FIN_Error( 'author-mapping-error', "Couldn't create author mapping file." );
			}

			// TODO: Fix $rows type upstream in write_csv()
			// @phpstan-ignore argument.type
			\FIN_CLI\Utils\write_csv( $file_resource, $author_mapping, array( 'old_user_login', 'new_user_login' ) );

			return new FIN_Error( 'author-mapping-error', sprintf( 'Please update author mapping file before continuing: %s', $file ) );
		} else {
			return new FIN_Error( 'author-mapping-error', "Couldn't create author mapping file." );
		}
	}

	/**
	 * Creates users if they don't exist, and build an author mapping file.
	 *
	 * @param array<\FIN_User> $author_data
	 */
	private function create_authors_for_mapping( $author_data ) {

		$author_mapping = array();
		foreach ( $author_data as $author ) {

			if ( isset( $author->user_email ) ) {
				$user = get_user_by( 'email', $author->user_email );
				if ( $user instanceof FIN_User ) {
					$author_mapping[] = array(
						'old_user_login' => $author->user_login,
						'new_user_login' => $user->user_login,
					);
					continue;
				}
			}

			$user = get_user_by( 'login', $author->user_login );
			if ( $user instanceof FIN_User ) {
				$author_mapping[] = array(
					'old_user_login' => $author->user_login,
					'new_user_login' => $user->user_login,
				);
				continue;
			}

			$user = array(
				'user_login' => '',
				'user_email' => '',
				'user_pass'  => fin_generate_password(),
			);
			$user = array_merge( $user, (array) $author );

			$user_id = fin_insert_user( $user );
			if ( is_fin_error( $user_id ) ) {
				return $user_id;
			}

			/**
			 * @var \FIN_User $user
			 */
			$user             = get_user_by( 'id', $user_id );
			$author_mapping[] = array(
				'old_user_login' => $author->user_login,
				'new_user_login' => $user->user_login,
			);
		}
		return $author_mapping;
	}

	/**
	 * Suggests a blog user based on the levenshtein distance.
	 *
	 * @return string|\FIN_User
	 */
	private function suggest_user( $author_user_login, $author_user_email = '' ) {

		if ( ! isset( $this->blog_users ) ) {
			$this->blog_users = get_users();
		}

		$shortest    = -1;
		$shortestavg = array();

		$threshold = floor( ( strlen( $author_user_login ) / 100 ) * 10 ); // 10 % of the strlen are valid
		$closest   = '';
		foreach ( $this->blog_users as $user ) {
			// Before we resort to an algorithm, let's try for an exact match
			if ( $author_user_email && $user->user_email === $author_user_email ) {
				return $user->user_login;
			}

			$levs        = array();
			$levs[]      = levenshtein( $author_user_login, $user->display_name );
			$levs[]      = levenshtein( $author_user_login, $user->user_login );
			$levs[]      = levenshtein( $author_user_login, $user->user_email );
			$email_parts = explode( '@', $user->user_email );
			$email_login = array_shift( $email_parts );
			$levs[]      = levenshtein( $author_user_login, $email_login );
			rsort( $levs );
			$lev = array_pop( $levs );
			if ( 0 === $lev ) {
				$closest  = $user->user_login;
				$shortest = 0;
				break;
			}

			if ( ( $lev <= $shortest || $shortest < 0 ) && $lev <= $threshold ) {
				$closest  = $user->user_login;
				$shortest = $lev;
			}
			$shortestavg[] = $lev;
		}
		// in case all usernames have a common pattern
		if ( $shortest > ( array_sum( $shortestavg ) / count( $shortestavg ) ) ) {
			return '';
		}

		return $closest;
	}
}
