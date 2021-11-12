# Block Editor SSR

Block Editor SSR adds support for rendering interactive (front-end React powered) Gutenberg blocks server side. If you want to build custom blocks that render in React on the front end, _and_ then have them also render server-side with hydration, this is the library for you.

Block Editor SSR is experimental, uses the PHP V8JS module and is not production ready. If that doesn't scare you, read on.

---

Building blocks that will render as a React-app on the front end has many possible architectures and solutions. Block Editor SSR expects blocks to be built in a _certain_ way (the way that made most sense to me). Before detailing how Block Editor SSR will server-render and hydrate your custom React block, first let's go over how building custom blocks in React (front end) is expected to go.

## Register the block

Like all good things in WordPress, it starts with registering something. In this case, a new custom Gutenbern block. This is done in PHP with `register_block_type()`. For example, say I have a block that is a TV Shows directory or something like that:

```php
register_block_type(
    'joe/tv-shows',
    [
        'title' => 'TV Shows',
        'category' => 'embed',
        'editor_script' => 'tv-shows-editor',
        'editor_style' => 'tv-shows-editor',
        'script' => 'tv-shows',
        'style' => 'tv-shows',
    ]
);
```

That makes the block exist, and I've set script / style handles for the editor and the front-end. If you were not familier, WordPress will auto-enqueue my `editor_` scripts in Gutenberg, and will auto-enqueue the `script` / `style` on the front-end whenever the block is rendered on a page.

Of course the scripts / styles also need registering via `wp_register_script` / `wp_enqueue_style`. We'll also assume that the `tv-shows-editor` script has been setup, and registered the block in JavaScrip via `registerBlockType`.

When it comes to showing the block on the front-end, we'll make use of the `render_callback` argument for `register_block_type`. The objective is to output a placeholder `<div>` that the React component will render to (via `ReactDOM.render`). The most basic version would be something like:


```php
register_block_type(
    'joe/tv-shows',
    [
        ...,
        'render_callback' => function ( array $attributes ) : string {
            return '<div id="tv-shows"></div>';
        },
    ]
);
```

Now when our block is added to a page, we'll have a `<div>` to render the React component into.

## Render the React comopnent

Building / bundling the React component for the custom block has many options and patterns. I've detailed what I think is the most basic way to get a React build process up and running. This leverages the in-build WordPress scripts for React etc. If you are unfamiliar with `@wordpress/element` and `@wordpress/scripts` it may be a good idea to research those. `@wordpress/scripts` provides a zero-config way to build + bundle JSX / React. Simply create a new `package.json` and add it as a dev dependency. A barebones example to transpile and bundle both the editor script and frontend scripts as seperate bundles:


```json
{
  "name": "joe/tv-shows",
  "scripts": {
    "build": "wp-scripts build ./src/editor.js ./src/frontend.js",
    "start": "wp-scripts start ./src/editor.js ./src/frontend.js"
  },
  "devDependencies": {
    "@wordpress/scripts": "^18.0.1"
  }
}
```

That's basically it! `npm run start` will auto-rebuild on file changes. No local dev server etc, keep it simple. The above would mean the editor and frontend scripts are `wp_register_script`ed with the `build/editor.js` etc URLs.

Speaking of which, the `tv-shows` frontend script would then looking like:


```jsx
import { render } from '@wordpress/element';

render( <h1>Hello World</h1>, document.getElementById( 'tv-shows' ) ); // Grab the container that was rendered in `render_callback`.
```

The above assumed the `@wordpress/element` is added as a dep of the frontend script via `wp_register_script`. `@wordpress/scripts` will automatically "external" the WordPress scripts from the bundle. This means there's only ever one copy of React used across many "React apps" / blocks.

Voila! That's pretty much, what I think, is the simplest way to get a custom block rendered as a React component on the frontend. It's quite likely you'd make use of `@wordpress/api-fetch` for fetching data from the REST API as part of your React component too.

## Rendering on the server

So, once we've jumoed through many hoops and technologies to get a frontend JavaScript component, we are at a position of `-1`, as our block requires JavaScript to render (SEO, etc etc). This is where Block Editor SSR comes in. To have a custom block be rendered on the server, you must set the `ssr` flag in `register_block_type`:

```php
register_block_type(
    'joe/tv-shows',
    [
        ...,
        'ssr' => true,
    ]
);
```

The library will now know to (basically) override the `render_callback` of the custom block, render the React component via PHP V8JS and put the output directly into the page output. This works by taking the `script` property of the registered block and executing it (with all registered deps). You _can_ also disable "frontend rendering" of the block, so the JavaScript is only executed on the server, and not in the browser by setting the `fsr` property in `register_block_type` to `false`. Why would one want to do that? Well, it can be useful for debugging / verifying that SSR is working correctly. It may also be that you want to write your frontend components in React, but not actually execute any JavaScript in the browser.

You must also adapt your React DOM `render` call to make use of server side rendering too. When any block has the `ssr` flag, the `window.BlockEditorSSR` object will be available. Adapt the render call like so:

```jsx
import { render } from '@wordpress/element';

if ( window.BlockEditorSSR ) {
    window.BlockEditorSSR.render( <h1>Hello World</h1>, document.getElementById( 'tv-shows' ) )
} else {
    render( <h1>Hello World</h1>, document.getElementById( 'tv-shows' ) );
}
```

`window.BlockEditorSSR.render` will handler the server-side rendering on the server, and when run in the browser, it will automatically hydrate the React app.

## Data Loading Superpowers

When you are going to the lengths of creating "mini React apps" per component, it's quite possible you'll be doing data-loading as part of the component intialization. In my TV Shows example, I'd load `/wp/v2/shows` from the REST API. WordPress provides the `@wordpress/api-fetch` package for that kind of thing. Block Editor SSR ships with a custom React hook called `useApiFetch` which will provided sychronous data loading when rendered on the server, and use `@wordpress/api-fetch` when loading data in the browser. This means you can write React components that will load data from the REST API and have it auto-load data on the server. What's more, any data loaded via server rendering will automatically be included in the page payload, so the React frontend hydration will have all the data instantly available for the client-side render.

`useApiFetch` is available via the `window.BlockEditorSSR` global. For example, to load all `tv-show` posts:

```jsx
let useApiFetch = window.BlockEditorSSR.useApiFetch;

export default function TvShowsList() {
    const [ isLoading, tvShows, error ] = useApiFetch( { path: '/wp/v2/tv-shows' } );
    if ( isLoading ) {
        return <div>Loading...</div>
    }
    if ( error ) {
        return <div>{ error.message }</div>
    }

    return <ul>
        { tvShows.map( show ) => (
            <li key={ show.id }>{ show.title.rendered }</li>
        )}
    </ul>
}
```
