import { rmSync } from 'node:fs';

// A successful build alone does not switch Laravel out of Vite development mode.
rmSync(new URL('../public/hot', import.meta.url), { force: true });
console.log('Ngrok assets ready: public/hot removed. Keep npm run dev stopped while sharing.');
