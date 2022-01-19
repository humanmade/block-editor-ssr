<?php

namespace Block_Editor_SSR;

use V8Js;
use V8JsScriptException;
use V8Object;
use WP_Block_Type_Registry;
use WP_REST_Request;

/**
 * Setup function.
 *
 * @return void
 */
function bootstrap() : void {
	add_filter( 'render_block', __NAMESPACE__ . '\\on_render_block', 10, 2 );
	add_action( 'wp_footer', __NAMESPACE__ . '\\output_hydration_data', 1 );
	add_action( 'init', __NAMESPACE__ . '\\register_scripts' );
}

function register_scripts() {
	wp_register_script(
		'block-editor-ssr',
		plugin_dir_url( __FILE__ ) . 'ssr.js',
		[
			'wp-element',
			'wp-api-fetch',
		],
		time(),
		true
	);
}

/**
 * Render a block on the `render_block` action.
 *
 * @param string $block_content The current block content.
 * @param array $parsed_block The parsed block data.
 * @return string
 */
function on_render_block( string $block_content, array $parsed_block ) : string {
	$registry = WP_Block_Type_Registry::get_instance();
	$block_type = $registry->get_registered( $parsed_block['blockName'] );
	if ( ! isset( $block_type->ssr ) ) {
		return $block_content;
	}

	if ( ! class_exists( 'V8Js' ) ) {
		trigger_error( 'react-wp-ssr requires the v8js extension, skipping server-side rendering.', E_USER_NOTICE );
		return $block_content;
	}
	$script_handle = $block_type->script;

	// Inject "block-editor-ssr" as another dep for
	// the block's script.
	$script = wp_scripts()->query( $script_handle );
	$script->deps[] = 'block-editor-ssr';

	ob_start();
	if ( $block_type->ssr ) {
		$v8js = get_v8js();
		$output = execute_script( $v8js, $script_handle );
	} else {
		$output = '';
	}
	$content = ob_get_clean();

	$attributes = $parsed_block['attrs'];

	if ( isset( $attributes['align'] ) && $attributes['align'] === 'full' ) {
		$attributes['className'] .= ' alignfull';
	}

	$output = sprintf(
		'<div id="%s" %s class="%s">%s</div>',
		esc_attr( $script_handle ),
		$output ? 'data-rendered=""' : '',
		esc_attr( $attributes['className'] ?? '' ),
		$output, // phpcs:ignore
	);

	$handle = $script_handle;
	// Ensure the live script also receives the container.
	add_filter(
		'script_loader_tag',
		function ( $tag, $script_handle ) use ( $handle, $block_type ) {
			if ( $script_handle !== $handle ) {
				return $tag;
			}
			// Allow disabling frontend rendering for debugging.
			if ( ( isset( $block_type->fsr ) && $block_type->fsr === false ) || ( defined( 'SSR_DEBUG_SERVER_ONLY' ) && SSR_DEBUG_SERVER_ONLY ) ) {
				return '';
			}

			$new_tag = sprintf( '<script data-container="%s" ', esc_attr( $handle ) );
			return str_replace( '<script ', $new_tag, $tag );
		},
		10,
		2
	);
	return $output;
}

/**
 * Get data to load into the `window` object in JS.
 *
 * @return object `window`-compatible object.
 */
function get_window_object() {
	list( $path ) = explode( '?', $_SERVER['REQUEST_URI'] );
	$port = $_SERVER['SERVER_PORT'];
	$port = $port !== '80' && $port !== '443' ? (int) $port : '';
	$query = $_SERVER['QUERY_STRING'];
	return [
		'document' => null,
		'location' => [
			'hash'     => '',
			'host'     => $port ? $_SERVER['HTTP_HOST'] . ':' . $port : $_SERVER['HTTP_HOST'],
			'hostname' => $_SERVER['HTTP_HOST'],
			'pathname' => $path,
			'port'     => $port,
			'protocol' => is_ssl() ? 'https:' : 'http:',
			'search'   => $query ? '?' . $query : '',
		],
	];
}

/**
 * Get a bootstrapped V8Js object.
 *
 * @return V8Js
 */
function get_v8js() : V8Js {

	$window = wp_json_encode( get_window_object() );
	$setup = <<<END
// Set up browser-compatible APIs.
var window = this;
Object.assign( window, $window );
var console = {
	warn: PHP.log,
	error: PHP.log,
	log: ( print => it => print( JSON.stringify( it ) ) )( PHP.log )
};
window.ReactDOM = {};
window.setTimeout = window.clearTimeout = () => {};
// Expose more globals we might want.
var global = global || this,
	self = self || this;
var isSSR = true;
// Remove default top-level APIs.
delete exit;
delete var_dump;
delete require;
delete sleep;
END;

	$v8 = new V8Js();

	/**
	 * Filter functions available to the server-side rendering.
	 *
	 * @param array $functions Map of function name => callback. Exposed on the global `PHP` object.
	 * @param string $handle Script being rendered.
	 * @param array $options Options passed to render.
	 */
	$functions = apply_filters( 'ssr.functions', [] );
	foreach ( $functions as $name => $function ) {
		$v8->$name = $function;
	}

	$v8->apiFetch = function ( V8Object $args ) { // phpcs:ignore
		$url = parse_url( $args->path );
		$request = new WP_REST_Request( 'GET', $url['path'] );
		if ( isset( $url['query'] ) ) {
			parse_str( $url['query'], $query );
			$request->set_url_params( $query );
		}
		$embed  = $request->has_param( '_embed' ) ? rest_parse_embed_param( $request['_embed'] ) : false;

		// REST Requests can clobber the global $post object. Because this function
		// can be called mid-loop we have to make sure to not screw up the global
		// state.
		global $post;
		$old_post = $post;
		$response = rest_do_request( $request );
		$server = rest_get_server();
		$data   = (array) $server->response_to_data( $response, $embed );

		// Restore the global post data
		$post = $old_post;
		$r = [
			$response->get_status(),
			$data,
			$response->get_headers(),
		];
		append_hydration_data( $args, $r );
		return $r;
	};

	$v8->log = function ( $log ) {
		error_log( '[SSR] ' . $log );
	};

	$preload_scripts = [
		__DIR__ . '/url-search-params.js',
	];

	$v8->loaded_scripts = [];
	$v8->executeString( $setup, 'ssrBootstrap' );

	foreach ( $preload_scripts as $preload_script ) {
		$v8->executeString( file_get_contents( $preload_script ), $preload_script );
	}
	return $v8;
}

/**
 * Append fetch api hydration data to the page.
 *
 * @param object $request The request params.
 * @param array $data The data for the request [status, data, error]
 * @return void
 */
function append_hydration_data( $request, $data ) : void {
	global $ssr_hydration_data;
	$key = wp_json_encode( $request, JSON_UNESCAPED_SLASHES );
	$ssr_hydration_data[ $key ] = $data;
}

/**
 * Output the fetch api hydration data in the footer.
 *
 * @return void
 */
function output_hydration_data() : void {
	global $ssr_hydration_data;
	if ( ! $ssr_hydration_data ) {
		return;
	}
	?>
	<script>
		var SSRHydrationData = <?php echo wp_json_encode( $ssr_hydration_data ) ?>;
	</script>
	<?php
}

/**
 * Execute the JavaScript for a given registered script handle in V8JS.
 *
 * @param V8Js $v8js The V8js object.
 * @param string $handle The scrip handle from wp_scripts
 * @return string
 * @throws V8JsScriptException The V8 exception if there's an error.
 */
function execute_script( V8Js $v8js, string $handle ) : string {
	$script = wp_scripts()->query( $handle );
	if ( $script->deps ) {
		foreach ( $script->deps as $dep ) {
			// Dont't load deps more than once.
			if ( in_array( $dep, $v8js->loaded_scripts ) ) {
				continue;
			}
			execute_script( $v8js, $dep );
		}
	}

	if ( $handle === 'react-dom' ) {
		$path = __DIR__ . '/react-dom-server.js';
	} else {
		$path = get_path_for_script_url( $script->src );
	}

	$source = file_get_contents( $path );

	// Lodash will incorrectly assume it's running in Node rather
	// than a browser, and set global._ rather than window.lodash.
	if ( $handle === 'lodash' ) {
		$source .= ' window.lodash = global._;';
	}
	ob_start();
	try {
		$v8js->loaded_scripts[] = $handle;
		$v8js->executeString( $source, $path );
	} catch ( V8JsScriptException $e ) {
		ob_clean();
		if ( WP_DEBUG || true ) {
			handle_exception( $e, $path );
		} else {
			// Trigger a warning, but otherwise do nothing.
			trigger_error( 'SSR error: ' . $e->getMessage(), E_USER_WARNING ); // phpcs:ignore
		}

		throw $e;
	}

	$output = ob_get_clean();

	return $output;
}

/**
 * Get a script file path from the enqueued url.
 *
 * @param string $url The script URL
 * @return string
 */
function get_path_for_script_url( string $url ) : string {
	if ( strpos( $url, WP_CONTENT_URL ) === 0 ) {
		return str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $url );
	}

	if ( strpos( $url, '/' ) === 0 ) {
		return untrailingslashit( ABSPATH ) . $url;
	}

	return $url;
}

/**
 * Render JS exception handler.
 *
 * @param V8JsScriptException $e Exception to handle.
 */
function handle_exception( V8JsScriptException $e ) {
	$file = $e->getJsFileName();
	?>
	<style><?php echo file_get_contents( __DIR__ . '/error-overlay.css' ) ?></style>
	<div class="error-overlay"><div class="wrapper"><div class="overlay">
		<div class="header">Failed to render</div>
		<pre class="preStyle"><code class="codeStyle"><?php
			echo esc_html( $file ) . "\n";

			$trace = $e->getJsTrace();
			if ( $trace ) {
				$trace_lines = $error = explode( "\n", $e->getJsTrace() );
				echo esc_html( $trace_lines[0] ) . "\n\n";
			} else {
				echo $e->getMessage() . "\n\n";
			}

			// Replace tabs with tab character.
			$prefix = '> ' . (int) $e->getJsLineNumber() . ' | ';
			echo $prefix . str_replace(
				"\t",
				'<span class="tab">→</span>',
				esc_html( $e->getJsSourceLine() )
			) . "\n";
			echo str_repeat( " ", strlen( $prefix ) + $e->getJsStartColumn() );
			echo str_repeat( "^", $e->getJsEndColumn() - $e->getJsStartColumn() ) . "\n";
			?></code></pre>
		<div class="footer">
			<p>This error occurred during server-side rendering and cannot be dismissed.</p>
			<?php if ( $file === 'ssrBootstrap' ): ?>
				<p>This appears to be an internal error in SSR. Please report it on GitHub.</p>
			<?php elseif ( $file === 'ssrDataInjection' ): ?>
				<p>This appears to be an error in your script's data. Check that your data is valid.</p>
			<?php endif ?>
		</div>
	</div></div></div>
	<?php
}
