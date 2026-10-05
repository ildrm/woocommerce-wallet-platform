import { transform } from 'esbuild';
import { readFile, writeFile } from 'node:fs/promises';
for (const name of ['blocks', 'paypal']) {
    const input = await readFile(`assets/frontend/${name}.js`, 'utf8');
    const output = await transform(input, { minify: true, target: 'es2020', sourcemap: false });
    await writeFile(`assets/frontend/${name}.min.js`, output.code);
}
console.log('Built wallet and PayPal Blocks payment assets.');
