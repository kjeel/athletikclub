# Athletikclub Steiermark – Vereinsplattform

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB-orange.svg)](https://mysql.com)
[![Hetzner](https://img.shields.io/badge/Hosting-Hetzner%20Webhosting%20L-red.svg)](https://hetzner.com)

Neue Web-Plattform für den **Athletikclub für Individualsportarten (ACI)** –  
gebaut für [Hetzner Webhosting L](https://www.hetzner.com/de/webhosting), rein mit PHP + MySQL.

---

## 🏗️ Projektstruktur

```
athletikclub-webapp/
├── index.php                  # Startseite
├── .htaccess                  # URL-Routing, HTTPS, Security
├── config/
│   ├── database.php           # DB-Verbindung (PDO)
│   └── config.php             # App-Konfiguration
├── includes/
│   ├── header.php             # Globaler Header/Nav
│   ├── footer.php             # Globaler Footer
│   ├── auth.php               # Auth-Helper
│   ├── dashboard-header.php   # Dashboard Layout
│   └── dashboard-footer.php
├── pages/                     # Öffentliche Seiten
├── auth/                      # Login, Registrierung, Passwort
├── dashboard/                 # Geschützter Mitgliederbereich
│   └── admin/                 # Admin-Only Seiten
├── api/                       # AJAX / Download Handler
├── assets/
│   ├── css/style.css          # Design-System
│   ├── css/dashboard.css      # Dashboard-CSS
│   └── js/                    # JavaScript
├── uploads/
│   ├── pdfs/                  # Hochgeladene PDFs
│   └── images/                # Bilder
└── sql/
    └── schema.sql             # Datenbankschema
```

---

## 🚀 Erstinstallation

### 1. Datenbank erstellen (Hetzner Konsole)

Im Hetzner Kundenbereich unter **Webhosting → Datenbanken**:
- Neue Datenbank anlegen (MySQL / MariaDB)
- Zugangsdaten notieren (Host, DB-Name, User, Passwort)

### 2. Schema importieren

Über **phpMyAdmin** (im Hetzner Panel):
```
Datei: sql/schema.sql → Importieren
```

### 3. Konfiguration anpassen

**`config/database.php`** bearbeiten:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'dein_datenbankname');
define('DB_USER', 'dein_datenbankuser');
define('DB_PASS', 'dein_passwort');
```

**`config/config.php`** bearbeiten:
```php
define('APP_ENV', 'production');
define('APP_URL', 'https://www.athletikclub-steiermark.at');
```

### 4. Dateien hochladen (Hetzner FTP / Deployment)

Via **FTP/SFTP** oder dem Hetzner Dateimanager alle Dateien in das  
`/html` bzw. `public_html` Verzeichnis hochladen.

### 5. Admin-Passwort ändern

Nach dem ersten Login (Standard-Admin: `admin@athletikclub-steiermark.at`)  
**sofort das Passwort im Profil ändern!**

Standard-Passwort: `AdminACI2025!`

---

## 👥 Rollen-System

| Rolle | Berechtigungen |
|---|---|
| **mitglied** | Profil, Kursanmeldung, PDF-Bibliothek (Mitglieder) |
| **trainer** | + Kurse erstellen/verwalten, Mitgliederliste, PDFs hochladen |
| **admin** | + Nutzerverwaltung, Seiteninhalte, alle Dokumente, Kontaktanfragen |

Neue Registrierungen erhalten automatisch die Rolle `mitglied`.  
Trainer werden vom Admin im Dashboard ernannt.

---

## 📄 PDF-System

- PDFs werden im Verzeichnis `uploads/pdfs/` gespeichert
- Direkter Zugriff ist via `.htaccess` gesperrt
- Download nur über `api/dokument-download.php` mit Zugriffsprüfung
- Kategorien: Vereinsdokument, Trainingsplan, Kursinformation, Protokoll, Sonstiges
- Sichtbarkeit: Alle / Mitglieder / Trainer / Admin

---

## 🔒 Sicherheit

- CSRF-Schutz auf allen Formularen
- Passwörter mit `bcrypt` (cost 12) gehasht
- PDO Prepared Statements (kein SQL-Injection)
- Upload-Verzeichnis via `.htaccess` geschützt
- Config-Ordner via `.htaccess` gesperrt
- Session Fixation Prevention (`session_regenerate_id`)
- Security Headers via `.htaccess`

---

## 🎨 Design

- **Farben**: Navy (#1F3556) + Gold (#C6A135) + Sport-Akzente
- **Fonts**: Montserrat (Headings) + Inter (Body) via Google Fonts
- **Responsive**: Mobile-first, Hamburger-Nav unter 768px
- **Animationen**: Scroll-Reveal, Counter, Hover-Effekte

---

## 📁 Wichtige Seiten

### Öffentlich
| URL | Datei |
|---|---|
| `/` | `index.php` |
| `/pages/kontakt.php` | Kontaktformular |
| `/pages/leistung.php` | Kursangebote |
| `/pages/mitglied-werden.php` | Mitglied werden |

### Auth
| URL | Datei |
|---|---|
| `/auth/login.php` | Login |
| `/auth/register.php` | Registrierung |
| `/auth/passwort-vergessen.php` | Passwort Reset |

### Dashboard
| URL | Datei |
|---|---|
| `/dashboard/index.php` | Übersicht (rollenbasiert) |
| `/dashboard/dokumente.php` | PDF-Bibliothek |
| `/dashboard/kurse.php` | Kursübersicht |
| `/dashboard/admin/nutzerverwaltung.php` | Admin: Nutzer |

---

## 🛠️ Technischer Stack

- **Backend**: PHP 7.4+ (PDO, nativer `mail()`)
- **Datenbank**: MySQL 5.7+ / MariaDB
- **Frontend**: Vanilla HTML/CSS/JS (keine Abhängigkeiten außer Google Fonts + Feather Icons)
- **Hosting**: Hetzner Webhosting L (Apache + PHP + MySQL)
- **Icons**: [Feather Icons](https://feathericons.com) via CDN

---

## 📬 Kontakt & Support

**Athletikclub für Individualsportarten (ACI)**  
St. Georgen an der Stiefing 14, 8413 Sankt Georgen an der Stiefing  
E-Mail: office@athletikclub-steiermark.at  
Tel: +43 664 882 895 00