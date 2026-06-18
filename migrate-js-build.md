# Migrate JavaScript build from Babel to Terser

## Background

The JavaScript build tooling across all Akeeba repositories has been migrated from
Babel + babel-preset-minify to Terser. Babel 8 (released 2026-06-16) removed the
`useBuiltIns` option from `@babel/preset-env`, which broke our configuration. Since
our source JS targets modern browsers anyway (we use `fetch`, `async/await`, and
nullish coalescing `??` without polyfilling), transpilation was never needed — we
only used Babel for minification. Terser handles minification directly with no
configuration overhead.

The shared build script lives in `buildfiles/build-js.mjs`. Each repository only
needs `terser` as a dev dependency and one npm script to call the shared script.

---

## Prompt for Claude Code

Copy and paste the following prompt when opening another repository in Claude Code:

---

> We have migrated our JavaScript build tooling from Babel to Terser. The shared
> `buildfiles` sibling repository (at `../buildfiles/`) now contains a
> `build-js.mjs` script that handles minification with Terser.
>
> Please make the following changes to this repository:
>
> 1. **Replace `package.json`** with a minimal version that contains only:
>    - `terser: "^5.0.0"` in `devDependencies`
>    - A `scripts` section with one entry: `"build-js": "node ../buildfiles/build-js.mjs <source-dir>"`
>    - No Babel dependencies, no Babel configuration block, no `core-js` dependency
>
>    Replace `<source-dir>` with the relative path to the directory that contains
>    the JavaScript source files for this repository (the non-minified `.js` files
>    that sit alongside their `.min.js` counterparts).
>
> 2. **Run `npm install`** to regenerate `package-lock.json`.
>
> 3. **Run `npm run build-js`** to regenerate all minified JS files.
>
> 4. **Commit** `package.json`, `package-lock.json`, and all updated `.min.js` and
>    `.min.js.map` files.

---

## PhpStorm File Watchers setup

Configure a File Watcher so that saving any JS source file automatically produces
the corresponding `.min.js` and `.min.js.map`.

### Step 1 — Create a custom scope

This prevents the watcher from triggering on `.min.js` files (which would cause an
infinite loop).

1. Open **Settings → Appearance & Behavior → Scopes**.
2. Click **+** and choose **Local**.
3. Name it `JS Sources`.
4. In the pattern field enter (adjust the path to match this repository):

   ```
   file:app/media/js/solo/*.js&&!file:app/media/js/solo/*.min.js
   ```

5. Click **OK**.

### Step 2 — Create the File Watcher

1. Open **Settings → Tools → File Watchers**.
2. Click **+** and choose **Custom**.
3. Fill in the fields as follows:

   | Field | Value |
   |---|---|
   | Name | `Terser` |
   | File type | `JavaScript` |
   | Scope | `JS Sources` (the scope created above) |
   | Program | `$ProjectFileDir$/node_modules/.bin/terser` |
   | Arguments | (see below) |
   | Output paths to refresh | `$FileDir$/$FileNameWithoutExtension$.min.js:$FileDir$/$FileNameWithoutExtension$.min.js.map` |
   | Working directory | `$ProjectFileDir$` |

4. In **Arguments**, enter this single line:

   ```
   $FilePath$ --compress --mangle --comments false --output $FileDir$/$FileNameWithoutExtension$.min.js --source-map filename=$FileDir$/$FileNameWithoutExtension$.min.js.map,url=$FileNameWithoutExtension$.min.js.map
   ```

5. Expand **Advanced Options** and make sure **Auto-save edited files to trigger the watcher** is ticked.

6. Click **OK**.

### Notes

- The watcher uses the project-local `node_modules/.bin/terser` binary. Run
  `npm install` in the repository root before expecting the watcher to work.
- The `Output paths to refresh` field tells PhpStorm which files to reload in the
  editor after Terser runs, so the in-editor view stays in sync.
- If PhpStorm cannot find `node_modules/.bin/terser`, use the full absolute path
  (e.g. `/usr/local/bin/terser`) or install Terser globally with `npm install -g terser`
  and set the program to just `terser`.
