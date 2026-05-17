# PRODUCTION_CHECKLIST.md — WA SaaS AI Sales Agent
# Jalankan checklist ini sebelum go-live ke production.
# Update setiap item dari [ ] menjadi [x] ketika sudah selesai.

---

## Pre-Launch Checklist

### Infrastruktur
- [ ] Redis persistence: `appendonly yes` + `appendfsync everysec` (sudah di docker-compose.yml)
- [ ] Semua Docker container `restart: unless-stopped` (app, nginx, queue, scheduler, horizon, wa-gateway)
- [ ] Healthcheck semua service configured dan healthy (`docker compose ps`)
- [ ] Backup PostgreSQL: `pg_dump` terjadwal (tambahkan ke cron atau OPS.md)
- [ ] Volume data PostgreSQL: mapped ke persistent storage (bukan tmpfs)
- [ ] Redis AOF file: pastikan volume redis ter-persist di host

### Security
- [ ] `APP_KEY` sudah di-generate (`php artisan key:generate`)
- [ ] `APP_DEBUG=false` di production `.env`
- [ ] `APP_ENV=production` di production `.env`
- [ ] Semua secret hanya ada di `.env` (bukan hardcode di kode)
- [ ] `SUPERADMIN_PASSWORD` sudah diganti dari default `change_this_password`
- [ ] `WA_INTERNAL_SECRET` sudah diganti dari default (min 32 chars random)
- [ ] Rate limiting aktif: POST /webhook/inbound, POST /api/auth/login, GET /app/export/* (Sub-task 7.3)
- [ ] PII masking di log aktif: `StripPiiProcessor` inject ke semua log channel (Sub-task 7.4)
- [ ] `APP_URL` di-set ke domain production (bukan localhost)
- [ ] CORS: verifikasi hanya domain tenant yang di-allow

### Konfigurasi
- [ ] `OPENAI_API_KEY` valid dan billing aktif di OpenAI dashboard
- [ ] `LLM_CLASSIFIER_MODEL=gpt-4o-mini` dan `LLM_COMPOSER_MODEL=gpt-4o` dikonfirmasi
- [ ] `RESEND_API_KEY` valid dan domain email sudah di-verify di Resend
- [ ] `RESEND_FROM_ADDRESS` menggunakan domain yang terverifikasi
- [ ] `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` valid, OAuth consent screen approved
- [ ] `GOOGLE_REDIRECT_URI` di-update ke domain production
- [ ] `R2_BUCKET` sudah dibuat + credentials valid di Cloudflare R2
- [ ] `R2_PUBLIC_URL` pointing ke domain Cloudflare (bukan endpoint API)
- [ ] WA account CONNECTED (scan QR via `/superadmin/wa-accounts`)
- [ ] `HORIZON_PREFIX` unik per environment (hindari konflik Redis key)

### Database & Migrations
- [ ] `php artisan migrate` sudah dijalankan di production database
- [ ] Semua index sudah ter-apply (`php artisan migrate:status` semua `Ran`)
- [ ] `php artisan db:seed --class=DatabaseSeeder` hanya untuk staging (JANGAN di production tanpa konfirmasi)
- [ ] Backup database sebelum setiap migration

### Testing
- [ ] `php artisan test` → 100% pass (678+ tests)
- [ ] `php artisan benchmark:run` → 30/30 skenario PASS
- [ ] `php artisan migrate:fresh --seed` di staging → tidak error
- [ ] Manual QA: minimal 5 percakapan nyata dengan mock tenant via WA
- [ ] Verifikasi: invoice PDF ter-generate dengan benar
- [ ] Verifikasi: follow-up automation berjalan via `php artisan schedule:run`
- [ ] Verifikasi: Horizon dashboard accessible di `/horizon` (superadmin only)

### DNS + SSL
- [ ] Domain production dikonfigurasi dan propagasi selesai
- [ ] SSL/TLS certificate valid (Let's Encrypt atau Cloudflare)
- [ ] HTTPS redirect aktif di Nginx config
- [ ] `APP_URL` menggunakan `https://`

### Monitoring
- [ ] Laravel Horizon dashboard accessible (`/horizon`, superadmin only, SUPERADMIN gate)
- [ ] Log shipping dikonfigurasi (Papertrail / Logtail / Datadog / etc)
- [ ] Alert dikonfigurasi untuk:
  - [ ] Queue failure (job failed > threshold)
  - [ ] WA disconnect (`WaAccountStatus::DISCONNECTED`)
  - [ ] Invoice overdue (tidak terbayar)
  - [ ] High error rate di log
- [ ] `GET /up` health endpoint response 200 (dipakai Docker healthcheck)

### Post-Launch
- [ ] Smoke test: kirim pesan WA → reply diterima dalam 30 detik
- [ ] Pantau Horizon queue depth selama 1 jam pertama
- [ ] Cek `storage/logs/laravel.log` — tidak ada error kritis
- [ ] Verifikasi tenant onboarding: buat 1 tenant demo, connect WA, buat paket, kirim pesan

---

## Recovery Checklist

### Redis Restart
- AOF persistence: queue tidak hilang (pesan di-retry otomatis via Laravel queue)
- Session Baileys: perlu scan QR ulang (normal, bukan data loss)

### App Container Restart
- Queue jobs: akan di-retry sesuai config `--tries=3`
- Ongoing conversation: customer bisa kirim ulang, pipeline idempotent via Redis dedup key

### Database Restart
- Data tidak hilang (PostgreSQL volume persistent)
- Connections akan reconnect otomatis via Laravel

---

## Perintah Berguna

```bash
# Health check semua service
docker compose ps

# Lihat log real-time
docker compose logs -f app
docker compose logs -f horizon

# Run test suite
docker compose exec app php artisan test

# Run benchmark
docker compose exec app php artisan benchmark:run

# Manual migration
docker compose exec app php artisan migrate

# Horizon status
docker compose exec horizon php artisan horizon:status

# Flush failed jobs
docker compose exec app php artisan queue:flush

# Clear cache
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
```
