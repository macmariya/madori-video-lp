// @ts-check
import { defineConfig } from 'astro/config';

// 静的出力。dist/ をバリューサーバーのサブドメインのドキュメントルートへ rsync する（deploy.sh）
export default defineConfig({
  site: 'https://madori.macmariya.com',
  trailingSlash: 'ignore',
  build: { inlineStylesheets: 'auto' },
});
