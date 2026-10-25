import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const root = path.dirname( fileURLToPath( import.meta.url ) );
const hotFile = path.join( root, 'hot' );

/**
 * Writes the dev server origin to a "hot" file so PHP can detect dev mode
 * and load assets from the Vite server instead of the build directory.
 */
function wordpressHotFile() {
	return {
		name: 'wordpress-hot-file',
		apply: 'serve',
		configureServer( server ) {
			server.httpServer?.once( 'listening', () => {
				const { port } = server.httpServer.address();
				fs.writeFileSync( hotFile, `http://localhost:${ port }` );
			} );

			const clean = () => {
				if ( fs.existsSync( hotFile ) ) {
					fs.rmSync( hotFile );
				}
			};
			process.on( 'exit', clean );
			for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
				process.on( signal, () => process.exit() );
			}
		},
	};
}

export default defineConfig( {
	plugins: [ react(), wordpressHotFile() ],
	base: '',
	build: {
		manifest: true,
		outDir: 'build',
		emptyOutDir: true,
		rollupOptions: {
			input: path.join( root, 'resources/js/index.jsx' ),
		},
	},
	server: {
		// The admin page is served from the WordPress origin, so dev assets
		// are cross-origin requests to the Vite server.
		cors: true,
	},
} );
