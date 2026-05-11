# OPS.md — Panduan Operasional Production
# WA SaaS AI Sales Agent — Wedding MVP
# Baca ini sebelum Phase 5 (Production Setup)

---

## OVERVIEW

File ini berisi:
1. Docker Compose production setup
2. Monitoring & alerting
3. Scaling WA agents
4. Backup & disaster recovery
5. Performance baseline

---

## 1. DOCKER COMPOSE PRODUCTION

### docker-compose.production.yml (template)
```yaml
version: '3.8'

services:
  app:
    build:
      context: ./laravel-app
      dockerfile: Dockerfile.production
    restart: always
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    depends_on:
      - postgres
      - redis
    networks:
      - internal

  horizon:
    build:
      context: ./laravel-app
      dockerfile: Dockerfile.production
    command: php artisan horizon
    restart: always
    environment:
      - APP_ENV=production
    depends_on:
      - postgres
      - redis
    networks:
      - internal

  wa-gateway:
    build:
      context: ./wa-gateway
      dockerfile: Dockerfile.production
    restart: always
    volumes:
      - wa_sessions:/app/sessions
    networks:
      - internal

  postgres:
    image: pgvector/pgvector:pg16
    restart: always
    volumes:
      - postgres_data:/var/lib/postgresql/data
    environment:
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: wa_saas
    networks:
      - internal
    # JANGAN expose port ke host di production
    # ports: — tidak ada

  redis:
    image: redis:7-alpine
    restart: always
    command: >
      redis-server
      --requirepass ${REDIS_PASSWORD}
      --appendonly yes
      --appendfsync everysec
    volumes:
      - redis_data:/data
    networks:
      - internal
    # JANGAN expose port ke host di production

  nginx:
    image: nginx:alpine
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/production.conf:/etc/nginx/conf.d/default.conf
      - /etc/letsencrypt:/etc/letsencrypt:ro
      - ./laravel-app/public:/var/www/html/public:ro
    networks:
      - internal
      - external

networks:
  internal:
    driver: bridge
    internal: true   # tidak bisa akses internet langsung
  external:
    driver: bridge

volumes:
  postgres_data:
  redis_data:
  wa_sessions:
```

### Perbedaan dari development compose
```
1. Redis: password + persistence (AOF)
2. PostgreSQL: tidak expose port
3. Nginx: SSL/TLS dengan Let's Encrypt
4. Horizon: container terpisah dari app
5. Network: internal network (antar service tidak bisa akses internet)
6. Semua service: restart: always
```

---

## 2. LARAVEL HORIZON CONFIGURATION

### Horizon worker configuration (config/horizon.php)
```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['high', 'default', 'low'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'tries' => 3,
            'timeout' => 60,
        ],
        // Dedicated worker untuk WA inbound (prioritas tinggi)
        'supervisor-wa' => [
            'connection' => 'redis',
            'queue' => ['wa-inbound'],
            'balance' => 'simple',
            'processes' => 3,
            'tries' => 3,
            'timeout' => 30,
        ],
    ],
    'local' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['high', 'default', 'wa-inbound', 'low'],
            'balance' => 'simple',
            'processes' => 1,
            'tries' => 3,
        ],
    ],
],
```

### Queue priority
```
high      : handoff notifications, billing alerts
wa-inbound: semua ProcessInboundMessageJob
default   : outbound dispatch, calendar, email
low       : analytics, cleanup jobs, memory jobs
```

---

## 3. MONITORING SETUP

### Health Endpoint
```php
// app/Modules/Shared/Http/Controllers/HealthController.php

public function index(): JsonResponse
{
    $checks = [
        'database' => $this->checkDatabase(),
        'redis'    => $this->checkRedis(),
        'horizon'  => $this->checkHorizon(),
        'storage'  => $this->checkStorage(),
    ];

    $status = collect($checks)->every(fn($v) => $v === 'ok') ? 'ok' : 'degraded';

    return response()->json([
        'status'    => $status,
        'timestamp' => now()->toIso8601String(),
        'checks'    => $checks,
        'version'   => config('app.version'),
    ], $status === 'ok' ? 200 : 503);
}
```

### UptimeRobot Setup (gratis, cukup untuk MVP)
```
1. Daftar di https://uptimerobot.com
2. Add Monitor:
   - Type: HTTP(s)
   - URL: https://[domain]/health
   - Interval: 5 minutes
   - Alert contacts: email kamu

3. Alert threshold: 1 kali gagal = alert langsung
   (jangan 2 kali — bisa kehilangan 10 menit downtime)
```

### Telegram Bot Alert
```bash
# 1. Buat bot: chat @BotFather di Telegram
# 2. Dapatkan token: xxx:yyy
# 3. Dapatkan chat_id kamu:
curl https://api.telegram.org/bot{TOKEN}/getUpdates
# Kirim pesan dulu ke bot, lalu run perintah ini

# .env.production:
ALERT_TELEGRAM_TOKEN=xxx:yyy
ALERT_TELEGRAM_CHAT_ID=-100xxxxxxx

# Laravel notification channel (install):
composer require laravel-notification-channels/telegram

# Alert yang dikirim otomatis:
# - Health check fail → CRITICAL
# - Horizon stopped → CRITICAL
# - LLM error rate > 10% → WARNING
# - WA agent disconnect → INFO
# - Injection attempt → INFO
```

### Key Metrics yang Harus Dipantau
```
Response Time  : Target < 3 detik (pesan masuk → reply terkirim)
Queue Size     : Alert jika > 100 jobs pending
Error Rate     : Alert jika > 5% dalam 5 menit
LLM Token Usage: Pantau daily cost di OpenAI dashboard
Disk Usage     : Alert jika > 80% (log dan storage bisa penuh)
Conversation/day: Pantau pertumbuhan (indikator health product)
```

---

## 4. SCALING WA AGENTS

### Limit per tenant berdasarkan plan
```
Starter : MAX_WA_AGENTS = 1
Growth  : MAX_WA_AGENTS = 3
Pro     : MAX_WA_AGENTS = 10

Setiap WA agent = satu Baileys session = satu process di wa-gateway
```

### Resource estimation
```
Per WA agent aktif:
- RAM: ~100-150MB (Node.js + Baileys session)
- CPU: minimal saat idle, spike saat reconnect
- Disk: ~5MB per session file

Server minimum untuk MVP (1-10 tenant, masing-masing 1 WA agent):
- VPS: 2 vCPU, 4GB RAM, 50GB SSD
- Rekomendasi: DigitalOcean Droplet $24/bulan atau Vultr

Scaling point:
- 10+ tenant aktif → upgrade ke 4GB RAM
- 50+ tenant aktif → pisahkan wa-gateway ke server sendiri
```

### WA Gateway scaling
```javascript
// wa-gateway/src/agent-manager.js
// Batas maksimal concurrent agents per gateway instance:
const MAX_AGENTS_PER_INSTANCE = 20;

// Jika perlu lebih:
// → Deploy wa-gateway instance kedua
// → Load balance di Laravel dengan round-robin ke multiple gateway URLs
// → Simpan gateway_url per wa_account di database
```

---

## 5. BACKUP STRATEGY

### Database backup
```bash
# Script: scripts/backup-database.sh
#!/bin/bash

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR=/backups/postgres
CONTAINER=wa-saas-postgres-1

mkdir -p $BACKUP_DIR

# Dump database
docker exec $CONTAINER pg_dump \
  -U postgres wa_saas \
  | gzip > $BACKUP_DIR/wa_saas_$DATE.sql.gz

# Hapus backup lebih dari 7 hari
find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete

echo "Backup selesai: wa_saas_$DATE.sql.gz"
```

```bash
# Jadwalkan dengan cron (setiap jam 2 pagi):
# crontab -e
0 2 * * * /home/ubuntu/scripts/backup-database.sh >> /var/log/backup.log 2>&1
```

### WA Sessions backup
```bash
# Session Baileys perlu backup karena:
# - Jika hilang: semua WA agent harus scan QR ulang
# - Tenant tidak bisa self-service re-scan (perlu notify)

# Backup wa_sessions volume:
docker run --rm \
  -v wa_sessions:/source \
  -v /backups/wa_sessions:/backup \
  alpine tar czf /backup/wa_sessions_$(date +%Y%m%d).tar.gz -C /source .

# Simpan 3 hari terakhir (session tidak berguna jika terlalu lama)
find /backups/wa_sessions -name "*.tar.gz" -mtime +3 -delete
```

### Restore procedure
```bash
# Restore database:
gunzip -c /backups/postgres/wa_saas_YYYYMMDD_HHMMSS.sql.gz \
  | docker exec -i wa-saas-postgres-1 psql -U postgres wa_saas

# Restore WA sessions:
docker run --rm \
  -v wa_sessions:/target \
  -v /backups/wa_sessions:/backup \
  alpine tar xzf /backup/wa_sessions_YYYYMMDD.tar.gz -C /target
```

---

## 6. PERFORMANCE BASELINE

### Target SLA untuk MVP
```
P50 response time : < 2 detik
P95 response time : < 5 detik
P99 response time : < 10 detik

Definisi: dari pesan WhatsApp diterima wa-gateway
          sampai reply terkirim ke customer

Komponen breakdown (estimasi):
- WA Gateway → Laravel webhook : 100ms
- Queue dispatch + pickup       : 200ms
- Sanitize + Classify (LLM)    : 500-800ms
- Entity Extract (LLM)         : 400-600ms
- Knowledge Retrieval (DB)     : 100-200ms
- Decision Engine (PHP)        : 50ms
- Validate                     : 50ms
- Compose Reply (LLM)          : 600-1000ms
- Dispatch → WA Gateway        : 100ms
Total estimate                 : 2.1-3.1 detik
```

### Optimasi jika response terlalu lambat
```
1. Cache intent classification untuk pesan identik (Redis, TTL 1 jam)
   → Hemat 500-800ms untuk pesan yang sering diulang

2. Eager load semua knowledge data saat conversation dimulai
   → Hindari N+1 query di Knowledge Retrieval

3. Parallel classify + extract (jika memungkinkan)
   → Jalankan IntentClassifier dan EntityExtractor bersamaan
   → Laravel concurrent HTTP requests ke OpenAI

4. Kurangi max_tokens di LLM call
   → Classifier: max 200 tokens (hanya JSON kecil)
   → Extractor: max 400 tokens
   → Composer: max 500 tokens (reply tidak boleh terlalu panjang)
```

---

## 7. DEGRADATION CHECK SCRIPT

```bash
# scripts/check-production-health.sh
# Jalankan setiap 5 menit via cron

#!/bin/bash

HEALTH_URL="https://[domain]/health"
TELEGRAM_TOKEN="${ALERT_TELEGRAM_TOKEN}"
CHAT_ID="${ALERT_TELEGRAM_CHAT_ID}"
THRESHOLD_QUEUE=100

alert() {
    local msg=$1
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_TOKEN}/sendMessage" \
      -d "chat_id=${CHAT_ID}" \
      -d "text=🚨 WA SaaS Alert: ${msg}" > /dev/null
}

# Check health endpoint
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_URL")
if [ "$HTTP_STATUS" != "200" ]; then
    alert "Health check FAIL (HTTP ${HTTP_STATUS})"
    exit 1
fi

# Parse response
RESPONSE=$(curl -s "$HEALTH_URL")
STATUS=$(echo $RESPONSE | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])")
if [ "$STATUS" != "ok" ]; then
    alert "System degraded: $RESPONSE"
fi

echo "Health check OK: $(date)"
```

---

## 8. FIRST PRODUCTION DEPLOYMENT CHECKLIST

Jalankan ini satu kali sebelum menerima tenant pertama:

```
[ ] Domain sudah pointing ke server
[ ] SSL certificate aktif (Let's Encrypt via certbot)
[ ] .env.production sudah diisi semua variable
[ ] APP_DEBUG=false, APP_ENV=production
[ ] Docker compose production up dan semua container running
[ ] php artisan migrate --force berhasil
[ ] php artisan db:seed --class=ProductionSeeder berhasil
    (seed plans, default config — bukan demo data)
[ ] Health endpoint return {"status":"ok"}
[ ] Horizon dashboard bisa diakses (via /superadmin)
[ ] Horizon workers running minimal 3 processes
[ ] UptimeRobot monitor aktif
[ ] Telegram bot alert test: kirim test message
[ ] Backup script berjalan dan ada file backup
[ ] Security checklist di SECURITY.md: semua ✅
[ ] 1 test conversation end-to-end berhasil
[ ] Benchmark 30 skenario: semua pass (dari BENCHMARK.md)
```
