/**
 * Minify JavaScript source files using Terser.
 *
 * Usage: node build-js.mjs <source-dir>
 *
 * Processes every *.js file in <source-dir> that is not already a *.min.js file,
 * writing <name>.min.js and <name>.min.js.map alongside the source.
 *
 * Terser is run with its working directory set to <source-dir> and is handed bare
 * file names. Terser records paths in the source map exactly as they are given on
 * the command line, so this keeps the map's "sources" relative (`gmail.js`) instead
 * of leaking the absolute path of the build machine into a shipped artifact.
 */

import { execFileSync } from 'child_process';
import { readdirSync } from 'fs';
import { join, basename } from 'path';
import { fileURLToPath } from 'url';

const srcDir = process.argv[2];

if (!srcDir) {
	console.error('Usage: node build-js.mjs <source-dir>');
	process.exit(1);
}

const terser = fileURLToPath(new URL('./node_modules/.bin/terser', import.meta.url));

const files = readdirSync(srcDir).filter(f => f.endsWith('.js') && !f.endsWith('.min.js'));

if (!files.length) {
	console.log(`No JavaScript source files found in ${srcDir}`);
	process.exit(0);
}

for (const file of files) {
	const name = basename(file, '.js');
	const out  = `${name}.min.js`;

	// `filename` sets the source map's "file" field; the map itself is always written
	// next to --output, as <output>.map.
	execFileSync(terser, [
		file,
		'--compress',
		'--mangle',
		'--comments', 'false',
		'--output', out,
		'--source-map', `filename=${out},url=${out}.map`,
	], { cwd: srcDir, stdio: 'inherit' });

	console.log(`  Built: ${join(srcDir, out)}`);
}
