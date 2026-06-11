// ForgePanel — mock data
window.FP_DATA = {
  server: {
    name: 'fra1-prod-01',
    ip: '157.90.224.18',
    os: 'Ubuntu 24.04 LTS',
    kernel: '6.8.0-41',
    uptime: '47d 12h',
    load: [1.84, 1.62, 1.38],
    cores: 8,
    ramTotal: 32,
    diskTotal: 640,
  },

  sites: [
    { id: 's1', name: 'aurora-shop.hr', stack: 'PHP 8.3 · nginx', type: 'WooCommerce', status: 'live', ssl: 'valid', sslDays: 64, traffic: 48210, traffic7: '+12%', deploy: 'prije 2 h', branch: 'main', php: '8.3', disk: '4.2 GB' },
    { id: 's2', name: 'api.fintrack.io', stack: 'Node 22 · pm2', type: 'REST API', status: 'live', ssl: 'valid', sslDays: 41, traffic: 156400, traffic7: '+31%', deploy: 'prije 18 min', branch: 'main', php: '—', disk: '860 MB' },
    { id: 's3', name: 'studio-mono.com', stack: 'Static · CDN', type: 'Astro', status: 'live', ssl: 'valid', sslDays: 78, traffic: 9120, traffic7: '-4%', deploy: 'jučer', branch: 'main', php: '—', disk: '210 MB' },
    { id: 's4', name: 'staging.aurora-shop.hr', stack: 'PHP 8.3 · nginx', type: 'WooCommerce', status: 'idle', ssl: 'valid', sslDays: 64, traffic: 340, traffic7: '0%', deploy: 'prije 3 d', branch: 'develop', php: '8.3', disk: '4.1 GB' },
    { id: 's5', name: 'legacy.kontaplan.hr', stack: 'PHP 7.4 · apache', type: 'Laravel 8', status: 'warning', ssl: 'expiring', sslDays: 9, traffic: 2210, traffic7: '-18%', deploy: 'prije 4 mj', branch: 'master', php: '7.4', disk: '1.8 GB' },
    { id: 's6', name: 'mail.fintrack.io', stack: 'Mailcow', type: 'Email', status: 'live', ssl: 'valid', sslDays: 52, traffic: 0, traffic7: '—', deploy: '—', branch: '—', php: '—', disk: '12.4 GB' },
  ],

  services: [
    { name: 'nginx', ver: '1.27.2', status: 'active', cpu: 1.2, ram: 84, uptime: '47d', port: '80, 443' },
    { name: 'php-fpm 8.3', ver: '8.3.12', status: 'active', cpu: 8.4, ram: 612, uptime: '12d', port: 'sock' },
    { name: 'postgresql', ver: '16.4', status: 'active', cpu: 4.1, ram: 1840, uptime: '47d', port: '5432' },
    { name: 'redis', ver: '7.4.0', status: 'active', cpu: 0.8, ram: 226, uptime: '47d', port: '6379' },
    { name: 'pm2 (node)', ver: '22.9.0', status: 'active', cpu: 6.2, ram: 940, uptime: '18m', port: '3000-3004' },
    { name: 'fail2ban', ver: '1.1.0', status: 'active', cpu: 0.1, ram: 38, uptime: '47d', port: '—' },
    { name: 'cron', ver: '—', status: 'active', cpu: 0.0, ram: 4, uptime: '47d', port: '—' },
    { name: 'apache2', ver: '2.4.62', status: 'degraded', cpu: 11.8, ram: 488, uptime: '2h', port: '8080' },
  ],

  events: [
    { t: '14:32:08', kind: 'deploy', msg: 'api.fintrack.io · deploy #482 (main @ b3f19c2) — uspješno', level: 'ok' },
    { t: '14:28:51', kind: 'security', msg: 'fail2ban: blokirano 14 IP-ova (ssh brute-force, RU/CN)', level: 'info' },
    { t: '14:11:02', kind: 'ssl', msg: 'legacy.kontaplan.hr · certifikat istječe za 9 dana — auto-renew nije uspio', level: 'warn' },
    { t: '13:58:44', kind: 'system', msg: 'apache2 restartan (OOM) — preuzeo 488 MB nakon restarta', level: 'warn' },
    { t: '13:40:19', kind: 'backup', msg: 'Snapshot fra1-prod-01 → S3 (eu-central) · 38.2 GB · 4m 12s', level: 'ok' },
    { t: '13:12:30', kind: 'db', msg: 'postgresql: vacuum analyze dovršen na aurora_shop (2.1 GB)', level: 'ok' },
    { t: '12:55:07', kind: 'deploy', msg: 'aurora-shop.hr · deploy #219 (main @ 88ac01d) — uspješno', level: 'ok' },
    { t: '12:31:40', kind: 'security', msg: 'Novi SSH ključ dodan: marko@macbook-pro (ed25519)', level: 'info' },
  ],

  files: {
    tree: [
      { name: 'app', type: 'dir', open: true, children: [
        { name: 'Http', type: 'dir', open: true, children: [
          { name: 'Controllers', type: 'dir', open: true, children: [
            { name: 'CheckoutController.php', type: 'php' },
            { name: 'ProductController.php', type: 'php' },
            { name: 'WebhookController.php', type: 'php', mod: true },
          ]},
          { name: 'Middleware', type: 'dir', children: [] },
        ]},
        { name: 'Models', type: 'dir', children: [] },
        { name: 'Services', type: 'dir', children: [] },
      ]},
      { name: 'config', type: 'dir', children: [] },
      { name: 'public', type: 'dir', children: [] },
      { name: 'resources', type: 'dir', children: [] },
      { name: 'routes', type: 'dir', open: true, children: [
        { name: 'api.php', type: 'php' },
        { name: 'web.php', type: 'php' },
      ]},
      { name: '.env', type: 'env', lock: true },
      { name: 'composer.json', type: 'json' },
      { name: 'docker-compose.yml', type: 'yml' },
    ],
  },

  dns: [
    { type: 'A', name: '@', value: '157.90.224.18', ttl: 3600, proxied: true },
    { type: 'A', name: 'www', value: '157.90.224.18', ttl: 3600, proxied: true },
    { type: 'CNAME', name: 'staging', value: 'aurora-shop.hr', ttl: 3600, proxied: false },
    { type: 'MX', name: '@', value: '10 mail.fintrack.io', ttl: 14400, proxied: false },
    { type: 'TXT', name: '@', value: 'v=spf1 mx a ip4:157.90.224.18 ~all', ttl: 3600, proxied: false },
    { type: 'TXT', name: '_dmarc', value: 'v=DMARC1; p=quarantine; rua=mailto:dmarc@aurora-shop.hr', ttl: 3600, proxied: false },
    { type: 'CAA', name: '@', value: '0 issue "letsencrypt.org"', ttl: 3600, proxied: false },
  ],

  certs: [
    { domain: 'aurora-shop.hr, *.aurora-shop.hr', issuer: "Let's Encrypt", expires: '14. 08. 2026.', days: 64, auto: true, status: 'valid' },
    { domain: 'api.fintrack.io', issuer: "Let's Encrypt", expires: '22. 07. 2026.', days: 41, auto: true, status: 'valid' },
    { domain: 'studio-mono.com', issuer: "Let's Encrypt", expires: '28. 08. 2026.', days: 78, auto: true, status: 'valid' },
    { domain: 'legacy.kontaplan.hr', issuer: "Let's Encrypt", expires: '20. 06. 2026.', days: 9, auto: false, status: 'expiring' },
    { domain: 'mail.fintrack.io', issuer: "Let's Encrypt", expires: '02. 08. 2026.', days: 52, auto: true, status: 'valid' },
  ],

  firewall: [
    { rule: 'SSH rate-limit', detail: '22/tcp · max 4 conn/min po IP-u', on: true },
    { rule: 'Cloudflare-only ingress', detail: '80, 443 samo s CF rangeova', on: true },
    { rule: 'Blokiraj XML-RPC', detail: 'wp-xmlrpc.php → 403', on: true },
    { rule: 'GeoIP blok', detail: 'Odbij login pokušaje izvan EU', on: false },
    { rule: 'PostgreSQL javni pristup', detail: '5432/tcp s bilo koje adrese', on: false },
  ],

  processes: [
    { pid: 18422, name: 'php-fpm: pool aurora', cpu: 7.2, ram: 312, user: 'aurora' },
    { pid: 2210, name: 'postgres: writer', cpu: 4.8, ram: 1620, user: 'postgres' },
    { pid: 31055, name: 'node /srv/fintrack/api', cpu: 4.1, ram: 488, user: 'fintrack' },
    { pid: 1180, name: 'nginx: worker', cpu: 1.4, ram: 42, user: 'www-data' },
    { pid: 30911, name: 'node /srv/fintrack/worker', cpu: 1.2, ram: 196, user: 'fintrack' },
    { pid: 9904, name: 'redis-server *:6379', cpu: 0.9, ram: 226, user: 'redis' },
  ],

  alerts: [
    { name: 'CPU > 85% (5 min)', target: 'fra1-prod-01', channel: 'Slack #ops', on: true },
    { name: 'Disk > 90%', target: 'svi volumeni', channel: 'Email + SMS', on: true },
    { name: '5xx rate > 1%', target: 'api.fintrack.io', channel: 'PagerDuty', on: true },
    { name: 'SSL istječe < 14 d', target: 'svi certifikati', channel: 'Slack #ops', on: true },
  ],
};
