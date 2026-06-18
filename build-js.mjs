/**
 * Minify JavaScript source files using Terser.
 *
 * Usage: node build-js.mjs <source-dir>
 *
 * Processes every *.js file in <source-dir> that is not already a *.min.js file,
 * writing <name>.min.js and <name>.min.js.map alongside the source.
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
	const src  = join(srcDir, file);
	const name = basename(file, '.js');
	const out  = join(srcDir, `${name}.min.js`);
	const map  = `${out}.map`;

	execFileSync(terser, [
		src,
		'--compress',
		'--mangle',
		'--comments', 'false',
		'--output', out,
		'--source-map', `filename=${map},url=${name}.min.js.map`,
	], { stdio: 'inherit' });

	console.log(`  Built: ${out}`);
}
