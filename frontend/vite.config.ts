import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { execSync } from 'child_process';

const now = new Date();
const year = now.getFullYear().toString().slice(-2);
const month = (now.getMonth() + 1).toString().padStart(2, '0');
const day = now.getDate().toString().padStart(2, '0');
const datePrefix = `${year}${month}${day}`;

const midnight = new Date();
midnight.setHours(0, 0, 0, 0);
const since = midnight.toISOString();

// Calculate revision as number of commits today + 1
let rev = '1';
try {
  const count = execSync(`git rev-list --count --after="${since}" HEAD`).toString().trim();
  rev = (parseInt(count) + 1).toString();
} catch (e) {
  console.warn('Failed to get git rev count', e);
}

const version = `${datePrefix}.${rev}`;

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/investyx/', // Deploy to hollyhop.cz/investyx
  define: {
    __APP_VERSION__: JSON.stringify(version),
    __APP_BUILD_DATE__: JSON.stringify(new Date().toISOString())
  },
  server: {
    port: 5173
  }
})
