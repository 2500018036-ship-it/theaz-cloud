# Phase 10: Production Preparation & Deployment Readiness

Tanggal audit lokal: 2026-10-11.

Dokumen ini mencatat kesiapan produksi aplikasi Theaz Cloud setelah Phase 09, perubahan yang dibuat pada Phase 10, serta langkah manual yang masih wajib dilakukan di server Debian/Proxmox/Docker sebelum dinyatakan production-ready penuh.

## Status Implementasi

- Mode production tersedia melalui `CI_ENVIRONMENT=production`; `app/Config/Boot/production.php` mematikan detail error/debug.
- Docker Compose memakai PHP-FPM, Nginx, MariaDB, private storage volume di luar webroot, health check ringan, dan rotasi log Docker.
- Nginx memakai root `public`, memblokir dotfile, `.env`, log, file backup, dan file konfigurasi umum.
- SMTP dikonfigurasi melalui environment dan diproses lewat queue + command `php spark email:send-queued`.
- Registrasi user dapat diwajibkan verifikasi email pada production atau ketika setting `email_verification_required=1`.
- Admin invitation dari Super Admin membuat akun admin `pending_verification` ketika verifikasi email diwajibkan.
- Login menolak akun `pending_verification`.
- Resend verification tersedia dengan rate limit dan pesan generik agar tidak membocorkan keberadaan email.
- Profile avatar top bar sudah memakai fallback dan styling yang sama dengan Settings.
- Tombol UI yang sebelumnya polos sudah diberi ikon sesuai template.

## Audit Prioritas

Critical:
- SMTP nyata belum dapat dinyatakan berhasil sebelum host, credential, sender domain, SPF/DKIM/DMARC, dan command queue diuji di server.
- HTTPS dan reverse proxy Azure/WireGuard belum diuji dari lingkungan ini. `APP_BASE_URL` harus memakai URL publik HTTPS agar tautan email benar.
- Backup/restore belum diuji ke environment terpisah dari sesi lokal ini.

High:
- Production harus menjalankan `email:send-queued`, `drive:cleanup-temp`, dan `drive:versions:prune` lewat cron/systemd timer.
- Cookie `Secure` dan trusted proxy harus dikonfigurasi saat aplikasi benar-benar berada di belakang HTTPS reverse proxy.
- Upload limit harus disamakan antara system settings, PHP `upload_max_filesize`/`post_max_size`, dan Nginx `client_max_body_size`.

Medium:
- File session masih memakai file handler. Cukup untuk VM kecil, tetapi pastikan volume `writable` persisten dan permission benar.
- SMTP failure disimpan di `email_queue.last_error` secara ringkas; dashboard status SMTP masih berupa setting kebijakan, bukan tes konektivitas live.

Low:
- Resource RAM/CPU belum diukur di VM target 2 GB.
- CSP belum diaktifkan ketat karena template aset perlu diaudit dulu agar tidak memecah UI.

## Environment Production

Gunakan `docker/env/production.example` sebagai template untuk file lokal seperti `docker/env/production.local`. Jangan commit secret.

Variabel penting:

```dotenv
CI_ENVIRONMENT=production
APP_BUILD_TARGET=production
APP_BASE_URL=https://cloud.example.test/
APP_TIMEZONE=Asia/Jakarta
APP_FORCE_HTTPS=false
COOKIE_SECURE=true
COOKIE_SAMESITE=Lax
HTTP_PORT=8080
LOGGER_THRESHOLD=4

MARIADB_DATABASE=storage_cloud
MARIADB_USER=storage_cloud
MARIADB_PASSWORD=
MARIADB_ROOT_PASSWORD=

SMTP_FROM_EMAIL=
SMTP_FROM_NAME=Theaz Cloud
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_CRYPTO=tls
SMTP_TIMEOUT=10
```

Command deploy dasar:

```bash
docker compose --env-file docker/env/production.local up -d --build
docker compose --env-file docker/env/production.local exec app php spark migrate --all
docker compose --env-file docker/env/production.local exec app php spark auth:bootstrap-admin
```

## SMTP dan Email Queue

Email tidak dikirim langsung dari transaksi registrasi. Aplikasi membuat entri `email_queue`; worker CLI mengirimnya setelah transaksi berhasil.

Jalankan manual:

```bash
docker compose --env-file docker/env/production.local exec app php spark email:send-queued --limit=25 --max-attempts=3
```

Contoh cron host:

```cron
* * * * * cd /srv/storage-cloud && docker compose --env-file docker/env/production.local exec -T app php spark email:send-queued --limit=25 --max-attempts=3 >/dev/null 2>&1
```

Kegagalan SMTP tidak dianggap sukses. Queue akan retry dengan jeda bertahap lalu menandai `failed` setelah batas percobaan. Token mentah tidak dicatat ke activity log.

## Email Verification

User registration:
- Akun dibuat sebagai `pending_verification` jika production atau `email_verification_required=1`.
- Token random 64 hex disimpan sebagai SHA-256 hash di `user_profiles.pending_email_token_hash`.
- Token berlaku default 24 jam.
- Login ditolak sampai token valid dikonfirmasi.
- Setelah verifikasi, user diarahkan ke login dan tidak otomatis login.

Admin invitation:
- Hanya `super_admin` yang dapat membuat admin invitation.
- Invitation token tetap satu kali pakai.
- Ketika verifikasi email diwajibkan, akun admin baru tetap `pending_verification` dan tidak dapat mengakses route admin.
- Verifikasi email mengaktifkan akun tanpa mengubah role.

## Docker, Nginx, dan Persistensi

Compose mempublikasikan hanya service `web`. MariaDB tidak mempunyai port publik. Volume persisten:

- `db_data`: data MariaDB.
- `app_writable`: file writable CodeIgniter.
- `cloud_private_storage`: file pengguna di `/var/cloud-drive/storage`, di luar webroot.

Nginx:
- `root /var/www/html/public`.
- `client_max_body_size 128m`.
- Dotfile, `.env`, `.sql`, `.bak`, `.ini`, `.log` ditolak.
- Header dasar `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, dan `Permissions-Policy` aktif.

Jika upload policy di dashboard diubah melebihi 128 MB, operator juga harus menaikkan `client_max_body_size`, `upload_max_filesize`, dan `post_max_size`.

## HTTPS dan Reverse Proxy

Untuk Azure VPS/WireGuard:
- Publik hanya arahkan HTTPS ke Nginx aplikasi.
- Jangan publish Proxmox, MariaDB, atau PHP-FPM ke internet.
- `APP_BASE_URL` wajib URL HTTPS publik.
- Set trusted proxy (`app_proxyIPs`) hanya untuk IP reverse proxy yang benar-benar dipercaya.
- Jangan percaya header forwarding dari publik langsung.
- Aktifkan cookie secure/force HTTPS hanya setelah proxy HTTPS dan trusted proxy benar.

Status dari sesi ini: belum diuji end-to-end karena memerlukan infrastruktur Azure/WireGuard/sertifikat aktual.

## Backup dan Restore

Backup minimal harus mencakup database, private storage, dan env konfigurasi tanpa membocorkan secret ke tempat publik.

Contoh backup:

```bash
mkdir -p /backup/storage-cloud/$(date +%F)
docker compose --env-file docker/env/production.local exec -T db mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" > /backup/storage-cloud/$(date +%F)/database.sql
docker run --rm -v storage-cloud_cloud_private_storage:/data:ro -v /backup/storage-cloud/$(date +%F):/backup alpine tar -czf /backup/cloud-private-storage.tgz -C /data .
cp docker/env/production.local /backup/storage-cloud/$(date +%F)/production.local
```

Contoh restore ke environment terpisah:

```bash
docker compose --env-file docker/env/production.local down
docker compose --env-file docker/env/production.local up -d db
docker compose --env-file docker/env/production.local exec -T db mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" < /backup/storage-cloud/YYYY-MM-DD/database.sql
docker run --rm -v storage-cloud_cloud_private_storage:/data -v /backup/storage-cloud/YYYY-MM-DD:/backup alpine sh -c "cd /data && tar -xzf /backup/cloud-private-storage.tgz"
docker compose --env-file docker/env/production.local up -d
```

Status dari sesi ini: prosedur terdokumentasi, restore belum diuji di environment terpisah.

## Scheduled Jobs

Jalankan ringan, tanpa service tambahan:

```cron
* * * * * cd /srv/storage-cloud && docker compose --env-file docker/env/production.local exec -T app php spark email:send-queued >/dev/null 2>&1
0 * * * * cd /srv/storage-cloud && docker compose --env-file docker/env/production.local exec -T app php spark drive:cleanup-temp >/dev/null 2>&1
30 2 * * * cd /srv/storage-cloud && docker compose --env-file docker/env/production.local exec -T app php spark drive:versions:prune >/dev/null 2>&1
```

## Pengujian Phase 10

Sudah diuji lokal:
- PHP lint untuk file Phase 10.
- PHPUnit feature test untuk registrasi user dengan email verification, queue email, blok login `pending_verification`, dan confirm token.
- PHPUnit feature test untuk admin invitation yang menjadi `pending_verification` saat policy verification aktif.

Belum dapat diuji dari sesi ini:
- SMTP sukses/gagal dengan server nyata.
- Docker Compose production build di Debian 13/Proxmox.
- HTTPS dan akses eksternal Azure/WireGuard.
- Backup dan restore ke environment terpisah.
- Penggunaan RAM/CPU selama traffic/upload nyata.

## Pemulihan Super Admin

Gunakan akses server, bukan endpoint publik:

```bash
docker compose --env-file docker/env/production.local exec app php spark auth:bootstrap-admin
```

Pastikan command hanya dijalankan oleh operator yang punya akses server. Jangan hardcode password awal di source code.
