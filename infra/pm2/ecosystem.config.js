/**
 * PM2 — apps Next.js en production (LOT C.2).
 *
 * v1 : frontend (pssfp.org) + candidature (apply.pssfp.org).
 * La library (bibliotheque.pssfp.org, port 6002) est hors périmètre v1 —
 * décommenter son bloc au lancement de la biblio.
 *
 * `pm2 start infra/pm2/ecosystem.config.js && pm2 save && pm2 startup`
 */
module.exports = {
  apps: [
    {
      name: 'pssfp-frontend',
      cwd: '/var/www/pssfp/frontend',
      script: 'node_modules/next/dist/bin/next',
      args: 'start --port 6001',
      env: { NODE_ENV: 'production' },
      max_memory_restart: '512M',
      out_file: '/var/log/pm2/pssfp-frontend.out.log',
      error_file: '/var/log/pm2/pssfp-frontend.err.log',
    },
    {
      name: 'pssfp-candidature',
      cwd: '/var/www/pssfp/candidature',
      script: 'node_modules/next/dist/bin/next',
      args: 'start --port 6003',
      env: { NODE_ENV: 'production' },
      max_memory_restart: '512M',
      out_file: '/var/log/pm2/pssfp-candidature.out.log',
      error_file: '/var/log/pm2/pssfp-candidature.err.log',
    },
    // {
    //   name: 'pssfp-library',
    //   cwd: '/var/www/pssfp/library',
    //   script: 'node_modules/next/dist/bin/next',
    //   args: 'start --port 6002',
    //   env: { NODE_ENV: 'production' },
    // },
  ],
};
