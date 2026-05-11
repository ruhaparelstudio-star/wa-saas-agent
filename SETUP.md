# SETUP.md — Panduan Setup Lengkap dari Nol
# WA SaaS AI Sales Agent — Wedding MVP
# Ikuti urutan ini PERSIS sebelum mulai development

---

## MINIMUM REQUIREMENTS

```
Hardware:
- RAM: 16GB (minimum 8GB, tapi 16GB sangat direkomendasikan)
- Disk: 50GB free space
- CPU: 4 core+

Software (Windows):
- Windows 10 version 2004+ atau Windows 11
- WSL2 sudah aktif
- VS Code terbaru
```

---

## STEP 1 — Aktifkan WSL2

```powershell
# Jalankan PowerShell sebagai Administrator

# Aktifkan WSL
dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart

# Aktifkan Virtual Machine Platform
dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart

# Restart Windows

# Set WSL2 sebagai default
wsl --set-default-version 2

# Install Ubuntu 22.04
wsl --install -d Ubuntu-22.04

# Verifikasi
wsl -l -v
# Harus menampilkan Ubuntu-22.04 dengan VERSION 2
```

---

## STEP 2 — Konfigurasi WSL2 Memory

```
Buat file: C:\Users\[NamaKamu]\.wslconfig
Isi dengan:

[wsl2]
memory=8GB
processors=4
swap=2GB
localhostForwarding=true
```

Restart WSL:
```powershell
wsl --shutdown
wsl
```

---

## STEP 3 — Setup Ubuntu WSL2

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install dependencies dasar
sudo apt install -y curl git unzip build-essential

# Verifikasi
git --version
curl --version
```

---

## STEP 4 — Install Docker Engine di WSL2

```bash
# Hapus versi lama jika ada
sudo apt remove docker docker-engine docker.io containerd runc

# Install dependencies
sudo apt install -y apt-transport-https ca-certificates gnupg lsb-release

# Tambah Docker GPG key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

# Tambah Docker repository
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker Engine
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Tambah user ke group docker (tidak perlu sudo)
sudo usermod -aG docker $USER
newgrp docker

# Start Docker service
sudo service docker start

# Auto-start Docker saat WSL start
echo 'sudo service docker start' >> ~/.bashrc

# Verifikasi
docker --version
docker compose version
docker run hello-world
```

---

## STEP 5 — Install Node.js 20

```bash
# Install NVM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
source ~/.bashrc

# Install Node.js 20
nvm install 20
nvm use 20
nvm alias default 20

# Verifikasi
node --version  # harus v20.x.x
npm --version
```

---

## STEP 6 — Install Claude Code

```bash
# Install Claude Code global
npm install -g @anthropic/claude-code

# Verifikasi
claude --version

# Login ke Anthropic (ikuti instruksi)
claude auth login
```

---

## STEP 7 — Install PHP 8.3 + Composer (untuk POC di luar Docker)

```bash
# Tambah PHP repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP 8.3 dan extensions
sudo apt install -y php8.3 php8.3-cli php8.3-curl php8.3-mbstring \
  php8.3-xml php8.3-zip php8.3-pgsql php8.3-redis php8.3-bcmath \
  php8.3-gd php8.3-intl php8.3-json

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verifikasi
php --version   # harus 8.3.x
composer --version
```

---

## STEP 8 — Setup VS Code

```bash
# Install VS Code di Windows (bukan di WSL)
# Download dari: https://code.visualstudio.com/

# Install extension yang diperlukan di VS Code:
# 1. Remote - WSL (Microsoft) — WAJIB
# 2. PHP Intelephense
# 3. Laravel Blade Snippets
# 4. Docker
# 5. GitLens
# 6. Thunder Client (API testing)
# 7. PostgreSQL (Chris Kolkman)
# 8. DotENV
```

VS Code settings yang direkomendasikan (settings.json):
```json
{
  "editor.formatOnSave": true,
  "editor.tabSize": 4,
  "files.eol": "\n",
  "terminal.integrated.defaultProfile.linux": "bash",
  "php.suggest.basic": false,
  "intelephense.environment.phpVersion": "8.3.0"
}
```

---

## STEP 9 — Setup Project

```bash
# PENTING: Simpan project di dalam WSL filesystem, BUKAN di /mnt/c/
# Ini 10x lebih cepat daripada simpan di Windows filesystem

# Buat folder project
mkdir -p ~/projects
cd ~/projects

# Clone atau init project
# Jika belum ada repo:
mkdir wa-saas-agent
cd wa-saas-agent
git init

# Copy semua file dari output (CLAUDE.md, PROGRESS.md, dll)
# ke folder ini

# Verifikasi struktur
ls -la
```

---

## STEP 10 — Setup OpenAI API Key

```bash
# Buat file .env di root project
cp .env.example .env

# Edit .env
nano .env

# Isi variabel berikut:
LLM_API_KEY=sk-proj-xxxxxxxxxxxx   # API key dari platform.openai.com
LLM_PROVIDER=openai
LLM_CLASSIFIER_MODEL=gpt-4o-mini
LLM_COMPOSER_MODEL=gpt-4o
LLM_EMBEDDING_MODEL=text-embedding-3-small

# Test API key valid
curl https://api.openai.com/v1/models \
  -H "Authorization: Bearer $LLM_API_KEY" | head -20
# Harus return list of models, bukan error
```

**Budget OpenAI yang direkomendasikan:**
```
Phase 0 (POC)     : $5-10
Phase 1-2         : $5 (test pakai mock, bukan real API)
Phase 3 (AI pipe) : $20-30 (banyak accuracy testing)
Phase 4-5         : $10-15
Development total : ~$50
```

---

## STEP 11 — Verifikasi Setup Lengkap

```bash
# Jalankan checklist ini sebelum mulai coding:

echo "=== VERIFIKASI SETUP ==="

# 1. WSL2
echo "WSL version: $(wsl.exe -l -v 2>/dev/null | head -1)"

# 2. Docker
docker --version && echo "✅ Docker OK" || echo "❌ Docker FAIL"
docker compose version && echo "✅ Docker Compose OK" || echo "❌ Compose FAIL"

# 3. Node.js
node --version && echo "✅ Node.js OK" || echo "❌ Node FAIL"

# 4. Claude Code
claude --version && echo "✅ Claude Code OK" || echo "❌ Claude Code FAIL"

# 5. PHP
php --version && echo "✅ PHP OK" || echo "❌ PHP FAIL"

# 6. Composer
composer --version && echo "✅ Composer OK" || echo "❌ Composer FAIL"

# 7. OpenAI API
curl -s https://api.openai.com/v1/models \
  -H "Authorization: Bearer $LLM_API_KEY" | grep -q "data" \
  && echo "✅ OpenAI API OK" || echo "❌ OpenAI API FAIL"

echo "=== SELESAI ==="
```

Semua harus ✅ sebelum lanjut.

---

## CARA OPTIMAL MENGGUNAKAN CLAUDE CODE

```bash
# Buka project di VS Code via WSL
cd ~/projects/wa-saas-agent
code .

# Di terminal VS Code (sudah terhubung ke WSL):
claude

# Claude Code akan otomatis baca CLAUDE.md
# Konfirmasi dengan tanya: "Apa nama project ini?"
# Claude Code harus jawab berdasarkan CLAUDE.md

# Tips penggunaan:
# 1. Gunakan /chat untuk diskusi
# 2. Gunakan mode default untuk coding
# 3. Paste prompt dalam satu blok, jangan sepotong-sepotong
# 4. Jika Claude Code mulai coding yang tidak diminta: ketik "STOP"
# 5. Jika context penuh: tutup sesi, buka baru, paste Context Prompt lagi
```

---

## DEBUGGING LLM DI LOKAL

### Cara lihat prompt yang dikirim ke OpenAI
```bash
# Install Laravel Telescope (untuk development only)
docker compose exec app composer require laravel/telescope --dev
docker compose exec app php artisan telescope:install
docker compose exec app php artisan migrate

# Buka: http://localhost:8080/telescope/requests
# Filter by tag: "llm" untuk lihat semua LLM calls
# Setiap call akan menampilkan:
# - Prompt lengkap yang dikirim
# - Raw response dari OpenAI
# - Token usage
# - Waktu eksekusi
```

### Cara replay satu turn conversation
```bash
# Dari Filament, buka menu: AI Logs → Decision Traces
# Klik trace yang ingin di-replay
# Klik tombol "Replay Turn"
# Ini akan re-run pipeline dengan input yang sama
# Berguna untuk debug tanpa perlu chat ulang

# Atau via artisan:
docker compose exec app php artisan agent:replay-turn {trace_id}
```

### Cara test prompt tanpa full pipeline
```bash
# Buat file poc/test-prompt.php
# (folder poc/ tidak masuk production)
<?php
$prompt = "...paste prompt di sini...";
$input = "kak mau tanya paket foto nikah";

// Direct call ke LLM
// Lihat hasilnya langsung di terminal
```

---

## SETUP SEED DATA REALISTIS

```bash
# Jalankan seeder khusus wedding
docker compose exec app php artisan db:seed --class=WeddingDemoSeeder

# Ini akan membuat:
# 1. Tenant demo: "Studio Foto Harmoni" (fotografi)
# 2. Paket: Basic (8 jt), Premium (15 jt), Ultimate (25 jt)
# 3. FAQ: 20 pertanyaan umum seputar foto wedding
# 4. Pricelist PDF: sudah di-upload ke storage
# 5. Business hours: Senin-Sabtu 09:00-17:00 WIB
# 6. WA agent: +62811xxxxxxx (mock, tidak perlu QR)
# 7. 5 sample conversations dengan berbagai stage

# Verifikasi seed berhasil:
docker compose exec app php artisan tinker
>>> App\Modules\Tenancy\Models\Tenant::count() // harus 1+
>>> App\Modules\Knowledge\Models\Package::count() // harus 3+
```

---

## LOCAL HTTPS SETUP (jika diperlukan Baileys)

```bash
# Generate self-signed certificate
mkdir -p ~/projects/wa-saas-agent/docker/certs
cd ~/projects/wa-saas-agent/docker/certs

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout localhost.key \
  -out localhost.crt \
  -subj "/C=ID/ST=Jakarta/L=Jakarta/O=Dev/CN=localhost"

# Tambahkan ke nginx.conf di docker:
# listen 443 ssl;
# ssl_certificate /etc/nginx/certs/localhost.crt;
# ssl_certificate_key /etc/nginx/certs/localhost.key;

# Catatan: Baileys (WhatsApp Web) TIDAK butuh HTTPS.
# HTTPS hanya diperlukan jika menggunakan WhatsApp Cloud API (berbeda)
# Untuk MVP dengan Baileys: HTTP localhost sudah cukup
```

---

## FIRST RUN SEQUENCE

Setelah semua setup selesai, urutan pertama kali menjalankan project:

```bash
cd ~/projects/wa-saas-agent

# 1. Copy env
cp .env.example .env
# Edit .env dengan nilai yang benar

# 2. Start Docker
docker compose up -d --build

# 3. Tunggu semua container ready (±2 menit)
docker compose ps
# Semua harus "running"

# 4. Install dependencies
docker compose exec app composer install

# 5. Generate key
docker compose exec app php artisan key:generate

# 6. Run migration + seed
docker compose exec app php artisan migrate --seed

# 7. Storage link
docker compose exec app php artisan storage:link

# 8. Verifikasi
curl http://localhost:8080/health
# Expected: {"status":"ok","timestamp":"..."}

# 9. Run test suite
docker compose exec app php artisan test
# Expected: All tests pass

# 10. Buka browser
# Superadmin panel: http://localhost:8080/superadmin
# Tenant panel: http://localhost:8080/app
# Mailpit: http://localhost:8025
# PgAdmin: http://localhost:5050
```
