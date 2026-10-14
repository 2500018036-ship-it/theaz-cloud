# Verifikasi email dan operasi aplikasi

Registrasi tetap memerlukan invitation code, email, username, password, dan konfirmasi. Akun baru berstatus `pending_verification`. Login dan filter session juga memeriksa `email_verified_at`, sehingga perubahan status tidak dapat melewati verifikasi. Akun lama yang belum terverifikasi perlu menggunakan **Resend verification email**. Administrator utama yang diprovision melalui CLI dikecualikan dari verifikasi registrasi.

Token dibuat dengan 32 byte acak, disimpan sebagai SHA-256 pada profil, berlaku 24 jam, dan diklaim secara atomik dalam transaksi. Pengiriman ulang mengganti token dan membatalkan email lama yang masih dalam antrean. Verifikasi tidak mengaktifkan kembali akun yang telah dinonaktifkan oleh admin. Email perubahan alamat memakai mekanisme token yang sama; alamat sebelumnya tetap berlaku sampai alamat baru diverifikasi.

SMTP menggunakan `.env` privat pada direktori Docker Compose. Salin nama variabel dari `docker/smtp.env.example`, isi nilai melalui akses administratif yang aman, dan gunakan izin `0600`. Jangan menampilkan konfigurasi Compose lengkap, environment container, token, isi antrean email, atau diagnostik SMTP mentah. TLS Gmail menggunakan `smtp.gmail.com:587`. Secret hanya diteruskan kepada aplikasi dan `mail_worker`; database tetap berada pada network internal. Worker melakukan claim antrean sebelum mengirim, retry bertahap sampai lima percobaan, dan memulihkan claim yang ditinggalkan worker setelah 10 menit. Alamat domain pengujian `.test` dan `.invalid` tidak dikirim.

```bash
cd /mnt/data/docker-compose/cloud-storage
sudo docker compose --env-file production.env --env-file release.env config --quiet
sudo docker compose --env-file production.env --env-file release.env ps
sudo docker compose --env-file production.env --env-file release.env logs --tail 20 mail_worker
```

Gagal SMTP tidak menghapus akun, menggunakan ulang invitation, atau membuka akses login. Pengguna mendapat pemberitahuan antrean beserta email yang didaftarkan dan dapat meminta email baru dengan pembatasan laju. Periksa hanya jumlah/status `email_queue` melalui akses database privat untuk menilai pengiriman. Status `sent` berarti transport menerima email; status tersebut tidak membuktikan email terlihat di inbox penerima. Gangguan saat transport menerima email tetapi worker belum menyimpan status dapat menyebabkan pengiriman ulang.

Hanya satu akun `super_admin` diizinkan. Migrasi mempertahankan owner yang diprovision, mengubah akun admin lainnya menjadi pengguna biasa, dan mempertahankan akun/file/riwayatnya. Unique index membatasi jumlah admin; trigger database menolak perubahan role, deaktivasi/soft delete, dan penghapusan owner. Password owner tetap dipertahankan. `auth:bootstrap-admin` hanya membuat owner ketika belum ada owner. Admin Management dan endpoint perubahan role dihapus. Perubahan status pengguna hanya menerima Active/Inactive; pengguna yang belum terverifikasi tidak dapat diaktifkan melalui kontrol admin.

Penghapusan invitation kedaluwarsa atau dicabut menggunakan `deleted_at`. Riwayat pemakaian, jumlah penggunaan, dan referensi pengguna penerima dipertahankan. Invitation yang diarsipkan tidak dapat dipakai atau dimunculkan kembali melalui endpoint edit.

Broadcast dikirim kepada satu penerima per email. Respons notifikasi hanya berasal dari pengguna yang sedang login. Email internal dan email pengguna lain pada isi broadcast disamarkan oleh backend. Header pengirim SMTP Gmail tetap menggunakan akun SMTP sesuai keputusan pengguna.

Navigasi dan formulir memakai Fetch, pembaruan shell, history browser, token CSRF terbaru, serialisasi mutasi, pencegahan submit berulang, timeout, spinner, dan notifikasi error. Email verification yang dibuka dari email merupakan navigasi masuk yang kemudian diarahkan ke login. Upload memakai progres dan request berurutan; request upload diberi batas waktu lebih panjang untuk koneksi lambat. Teks statis dan komponen dinamis menggunakan katalog English/Indonesia. Pilihan bahasa disimpan pada session/profil dan localStorage; template RTL diperbaiki agar tidak menghapus atribut bahasa.

Request fragment menghilangkan asset/script yang telah dimuat, Nginx mengompresi respons teks, dan asset aplikasi diberi versi release serta cache tujuh hari. File privat/session/halaman akun tetap tidak menggunakan shared cache. Database menggunakan pagination dan index antrean/invitation. Pengukuran kapasitas VM dan hambatan koneksi WireGuard dijelaskan pada `operations.md`.

Backup menghentikan aplikasi/web/worker yang sedang berjalan, menyimpan dump database beserta trigger, file pengguna, avatar profil, dan konfigurasi privat, lalu menghidupkan layanan yang sebelumnya berjalan. Rollback mengganti image/Compose pada release sebelumnya tanpa membalik migrasi atau data. Trigger proteksi admin tetap berlaku ketika menggunakan image lama. Jangan menjalankan rollback migrasi atau restore database tanpa rencana pemulihan data yang terpisah.

`scripts/ci.sh` menggunakan database/volume terisolasi. Pengujian pada Debian dapat menggunakan port localhost berbeda, `CI_DB_WAIT_TIMEOUT=600`, dan `CI_KEEP_RUNNING=true` untuk pemeriksaan browser. Runtime pengujian disimpan privat di direktori sementara, bukan repository. Hentikan hanya project Compose pengujian setelah selesai. Stack pengujian tidak bergabung dengan network proxy production.
