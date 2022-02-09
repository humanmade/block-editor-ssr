/**
 * The Block Editor SSR JS library.
 *
 * This library handles isomorphic rendering and hydration on the server
 * site and client side.
 */
let ReactDOMServer = window.ReactDOMServer;
let ReactDOMHydrate = window.ReactDOM && window.ReactDOM.hydrate;
let ReactDOMRender = window.wp && window.wp.element.render;
let useState = window.wp && window.wp.element.useState;
let useEffect = window.wp && window.wp.element.useEffect;
let apiFetch = window.wp && window.wp.apiFetch;

const ENV_BROWSER = 'browser';
const ENV_SERVER = 'server';

function getEnvironment() {
	return typeof global === 'undefined' || typeof global.isSSR === 'undefined' ? ENV_BROWSER : ENV_SERVER;
}

const onFrontend = callback => getEnvironment() === ENV_BROWSER && callback();
const onBackend = callback => getEnvironment() === ENV_SERVER && callback();

function render( getComponent, containerId ) {
	const environment = getEnvironment();
	const component = getComponent( environment );

	switch ( environment ) {
		case ENV_SERVER:
			global.print( ReactDOMServer.renderToString( component ) );
			break;

		case ENV_BROWSER: {
			if ( ! containerId ) {
				return;
			}
			const container = document.getElementById( containerId );
			const didRender = 'rendered' in container.dataset;
			if ( didRender ) {
				ReactDOMHydrate(
					component,
					container,
					undefined
				);
			} else {
				ReactDOMRender(
					component,
					container
				);
			}
			break;
		}

		default:
			throw new Error( `Unknown environment "${ environment }"` );
	}
}

if ( apiFetch ) {
	apiFetch.use( ( options, next ) => {
		if ( window.SSRHydrationData && window.SSRHydrationData[ JSON.stringify( options ) ] ) {
			const [ status, data ] = window.SSRHydrationData[ JSON.stringify( options ) ];
			return new Promise( ( resolve, reject ) => {
				if ( status < 400 ) {
					resolve( data );
				} else {
					reject( data );
				}
			} );

		}
		return next( options );
	} );
}

function useApiFetch( args ) {
	if ( getEnvironment() === 'browser' ) {
		let defaultIsLoading = true;
		let defaultData = null;
		let defaultError = null;
		if ( window.SSRHydrationData && window.SSRHydrationData[ JSON.stringify( args ) ] ) {
			const [ status, data ] = window.SSRHydrationData[ JSON.stringify( args ) ];
			defaultIsLoading = false;
			if ( status < 400 ) {
				defaultData = data;
			} else {
				defaultError = data;
			}

		}
		const [ isLoading, setIsLoading ] = useState( defaultIsLoading );
		const [ data, setData ] = useState( defaultData );
		const [ error, setError ] = useState( defaultError );

		useEffect( () => {
			setIsLoading( true );
			apiFetch( args )
				.then( res => {
					setData( res );
					setIsLoading( false );
				} )
				.catch( err => {
					setError( err );
					setIsLoading( false );
				} );
		}, [ JSON.stringify( args ) ] );

		return [ isLoading, data, error ];
	} else if ( getEnvironment() === 'server' ) {
		const [ status, data ] = global.PHP.apiFetch( args );
		if ( status >= 400 ) {
			return [ false, null, data ];
		}
		return [ false, data, null ];
	}

	return [ false, [], null ];
}

window.BlockEditorSSR = {
	render: render,
	onBackend: onBackend,
	onFrontend: onFrontend,
	getEnvironment: getEnvironment,
	useApiFetch: useApiFetch,
};
