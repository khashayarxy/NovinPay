import {createHash} from 'node:crypto';
import {mkdir, readFile, readdir, writeFile} from 'node:fs/promises';
import path from 'node:path';

// A closed public asset allowlist. PHP, configuration, HTML and runtime data never enter public/.
const source=path.resolve('resources/support-chat'), destination=path.resolve('public/build/support-chat');
const files=[];
async function walk(relative){
 for(const entry of await readdir(path.join(source,relative),{withFileTypes:true})){
  if(entry.isSymbolicLink())throw Error('Support asset symlinks are forbidden');
  const name=relative+'/'+entry.name;
  if(entry.isDirectory())await walk(name);
  else if(/\.(?:js|css|woff2|png|svg)$/.test(name))files.push(name);
 }
}
for(const directory of ['operator','support-client','assets/fonts','assets/icons'])await walk(directory);
files.push('support-widget.js');files.sort();
const bytes=await Promise.all(files.map(async file=>{
 const original=await readFile(path.join(source,file));
 // The directory hash replaces legacy per-import versions. Canonical imports
 // must match HTML modulepreloads or browsers instantiate/fetch modules twice.
 // Never rewrite concatenated URLs: retry counters must remain query values,
 // not become part of the filename (for example customer-faq.js0).
 return file.endsWith('.js')?Buffer.from(original.toString('utf8').replace(/(["'])(\.{1,2}\/[a-zA-Z0-9_./-]+\.js)\?v=[a-zA-Z0-9_.-]+\1(?!\s*\+)/g,'$1$2$1')):original;
}));
const digest=createHash('sha256');files.forEach((file,i)=>digest.update(file+'\0').update(bytes[i]));
const version=digest.digest('hex').slice(0,24);
for(let i=0;i<files.length;i++){
 const target=path.join(destination,version,files[i]);
 await mkdir(path.dirname(target),{recursive:true});await writeFile(target,bytes[i]);
}
await writeFile(path.join(destination,'.htaccess'),'<IfModule mod_headers.c>\nHeader set Cache-Control "public, max-age=31536000, immutable"\nHeader set X-Content-Type-Options "nosniff"\n</IfModule>\n');
await writeFile(path.join(destination,'manifest.json'),JSON.stringify({version,files}));
console.log(`Published ${files.length} public support assets: ${version}`);
