#!/usr/bin/env node
/**
 * One-step setup for the wp-env site.
 *
 * Generates a signing keypair if there isn't one, then runs seed.php inside the
 * container through WP-CLI. The container path is derived from this checkout's
 * directory name, because wp-env mounts the project as a plugin named after the
 * folder — which is not necessarily the plugin slug.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const PROJECT_ROOT = path.resolve( HERE, '..', '..' );
const PUBLIC_KEY_PATH = path.join( HERE, '.keys', 'public.pem' );

function run( command, args ) {
	return execFileSync( command, args, {
		cwd: PROJECT_ROOT,
		stdio: 'inherit',
		shell: process.platform === 'win32',
	} );
}

if ( ! fs.existsSync( PUBLIC_KEY_PATH ) ) {
	console.log( 'No signing key yet — generating one.\n' );
	run( 'node', [ path.join( 'tests', 'tools', 'webhook.mjs' ), 'keygen' ] );
	console.log( '' );
}

const pluginDir = path.basename( PROJECT_ROOT );
const seedPath = `wp-content/plugins/${ pluginDir }/tests/tools/seed.php`;

console.log( `Seeding through ${ seedPath }\n` );

run( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval-file', seedPath ] );
